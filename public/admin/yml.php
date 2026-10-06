<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Import\ColumnRef;
use App\Import\Fetcher;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Db;
use App\YmlExport\FeedBuilder;
use App\YmlExport\Matcher;
use App\YmlExport\Schema;
use App\YmlExport\SettingsRepository;
use App\YmlExport\StockMapRepository;
use App\YmlExport\UploadImporter;
use App\YmlExport\UploadRepository;
use App\YmlExport\WarehouseRepository;

/**
 * Раздел «Выгрузка YML» — дополнение к админ-панели.
 * Самостоятельная страница: основной код админки не изменяется,
 * раздел открывается по адресу /admin/yml.php (ссылку в меню можно
 * добавить одной строкой в public/admin/index.php — по желанию).
 */

set_exception_handler(static function (Throwable $exception): void {
    error_log('[admin-yml] ' . $exception);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $detailed = Auth::check() || (bool) App\Support\Config::get('debug', false);
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Ошибка</title><link rel="stylesheet" href="' . e(asset('assets/css/admin.css', '../')) . '"></head><body>'
        . '<main class="a-main a-main--narrow"><h1 class="a-title">Что-то пошло не так</h1>'
        . '<div class="alert alert--error">'
        . ($detailed ? e($exception->getMessage()) : 'Внутренняя ошибка сервиса.')
        . '</div><p><a class="btn btn--ghost" href="yml.php">Вернуться в раздел</a></p></main></body></html>';
});

try {
    if (!Db::tableExists('sources')) {
        redirect('../install.php');
    }
} catch (PDOException $exception) {
    error_log('[admin-yml] ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    exit('<h1>Нет соединения с базой данных</h1><p>Проверьте секцию <code>db</code> в config.php.</p>');
}

Auth::requireLogin();
Csrf::check();
Schema::ensure();

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$tab = (string) ($_GET['tab'] ?? 'warehouses');

/** Пересборка фида без падения страницы: ошибка уйдёт в лог и flash. */
function rebuildQuiet(): void
{
    try {
        FeedBuilder::build();
    } catch (Throwable $exception) {
        error_log('[admin-yml] сборка фида: ' . $exception->getMessage());
        flash('error', 'Фид не пересобрался: ' . $exception->getMessage());
    }
}

/* ---------- Действия (POST) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'warehouse-save':
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                flash('error', 'Укажите название склада.');
                redirect('yml.php?tab=warehouses');
            }
            $code = trim((string) ($_POST['code'] ?? ''));
            $sort = (int) ($_POST['sort'] ?? 0);
            $kind = (string) ($_POST['kind'] ?? 'file') === 'virtual' ? 'virtual' : 'file';
            $defaultStock = max(0, (int) ($_POST['default_stock'] ?? 0));
            if ($id > 0) {
                WarehouseRepository::update($id, $name, $code, $sort, $kind, $defaultStock);
                flash('success', 'Склад сохранён.');
            } else {
                WarehouseRepository::create($name, $code, $sort, $kind, $defaultStock);
                flash('success', $kind === 'virtual'
                    ? 'Виртуальный склад создан: его остаток попадёт в каждый оффер фида.'
                    : 'Склад создан. Теперь загрузите его файл остатков.');
            }
            redirect('yml.php?tab=warehouses');
            break;

        case 'warehouse-toggle':
            $id = (int) ($_POST['id'] ?? 0);
            $active = (int) ($_POST['active'] ?? 0) === 1;
            WarehouseRepository::setActive($id, $active);
            flash('success', $active ? 'Склад включён.' : 'Склад отключён: его остатки не попадут в фид.');
            rebuildQuiet();
            redirect('yml.php?tab=warehouses');
            break;

        case 'warehouse-delete':
            WarehouseRepository::delete((int) ($_POST['id'] ?? 0));
            flash('success', 'Склад удалён вместе с позициями и сопоставлениями.');
            rebuildQuiet();
            redirect('yml.php?tab=warehouses');
            break;

        case 'upload-save':
            handleUploadSave();
            break;

        case 'upload-import':
            @set_time_limit(0);
            $upload = UploadRepository::find((int) ($_POST['id'] ?? 0));
            if ($upload === null) {
                flash('error', 'Загрузка не найдена.');
                redirect('yml.php?tab=warehouses');
            }
            $result = UploadImporter::run($upload);
            flash($result['status'] === 'ok' ? 'success' : 'error', $result['message']);
            if ($result['status'] === 'ok') {
                rebuildQuiet();
            }
            redirect('yml.php?tab=warehouses');
            break;

        case 'upload-delete':
            UploadRepository::delete((int) ($_POST['id'] ?? 0));
            flash('success', 'Файл склада и его позиции удалены.');
            rebuildQuiet();
            redirect('yml.php?tab=warehouses');
            break;

        case 'match-confirm':
            $matchId = (int) ($_POST['match_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            Matcher::confirm($matchId, $productId > 0 ? $productId : null, $adminId);
            flash('success', 'Сопоставление подтверждено.');
            rebuildQuiet();
            redirect('yml.php?tab=matches');
            break;

        case 'match-bulk':
            $minScore = max(Matcher::NAME_THRESHOLD, min(100, (int) ($_POST['min_score'] ?? 80)));
            $count = Matcher::bulkConfirm($minScore, $adminId);
            flash('success', 'Подтверждено пар: ' . $count . ' (совпадение от ' . $minScore . '%).');
            rebuildQuiet();
            redirect('yml.php?tab=matches');
            break;

        case 'match-reject':
            Matcher::reject((int) ($_POST['match_id'] ?? 0));
            flash('success', 'Пара отклонена — позиция не попадёт в фид.');
            rebuildQuiet();
            redirect('yml.php?tab=matches');
            break;

        case 'match-reset':
            Matcher::resetToSuggested((int) ($_POST['match_id'] ?? 0));
            flash('success', 'Подтверждение снято.');
            rebuildQuiet();
            redirect('yml.php?tab=matches');
            break;

        case 'match-unmatch':
            Matcher::unmatch((int) ($_POST['match_id'] ?? 0));
            flash('success', 'Сопоставление удалено — позиция вернулась в очередь «К сопоставлению».');
            rebuildQuiet();
            redirect('yml.php?tab=matches&sub=items');
            break;

        case 'warehouses-feed-save':
            $checkedIds = array_map('intval', (array) ($_POST['in_feed'] ?? []));
            foreach (WarehouseRepository::list() as $wh) {
                WarehouseRepository::setInFeed((int) $wh['id'], in_array((int) $wh['id'], $checkedIds, true));
            }
            flash('success', 'Состав складов в фиде сохранён.');
            rebuildQuiet();
            redirect('yml.php?tab=feed');
            break;

        case 'settings-save':
            handleSettingsSave();
            break;

        case 'token-regenerate':
            SettingsRepository::regenerateToken();
            flash('success', 'Токен перевыпущен. Старая ссылка на фид больше не работает.');
            redirect('yml.php?tab=feed');
            break;

        case 'feed-build':
            rebuildQuiet();
            flash('success', 'Фид пересобран.');
            redirect('yml.php?tab=feed');
            break;
    }
}

/* ---------- Обработчики ---------- */

function handleUploadSave(): void
{
    $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
    $warehouse = WarehouseRepository::find($warehouseId);
    if ($warehouse === null) {
        flash('error', 'Выберите склад.');
        redirect('yml.php?tab=warehouses');
    }

    $existing = UploadRepository::findByWarehouse($warehouseId);
    $type = (string) ($_POST['type'] ?? '');
    $fetchMethod = (string) ($_POST['fetch_method'] ?? '');
    $sourceUrl = trim((string) ($_POST['source_url'] ?? ''));

    $errors = [];
    if (!isset(UploadRepository::TYPES[$type])) {
        $errors[] = 'Выберите тип файла.';
    }
    if (!in_array($fetchMethod, ['upload', 'url'], true)) {
        $errors[] = 'Выберите способ получения файла.';
    }
    if ($fetchMethod === 'url' && !preg_match('~^https?://~i', $sourceUrl)) {
        $errors[] = 'Укажите корректную ссылку на файл (http:// или https://).';
    }

    $mapping = [];
    $mappingInput = (array) ($_POST['mapping'] ?? []);
    foreach (array_keys(UploadRepository::FIELDS) as $field) {
        $mapping[$field] = trim((string) ($mappingInput[$field] ?? ''));
    }

    if (in_array($type, ['excel', 'csv'], true)) {
        foreach (UploadRepository::FIELDS as $field => $label) {
            if ($mapping[$field] === '') {
                continue;
            }
            try {
                ColumnRef::toIndex($mapping[$field]);
            } catch (Throwable $exception) {
                $errors[] = $label . ': ' . $exception->getMessage();
            }
        }
    }

    $uploadedName = null;
    $hasUpload = isset($_FILES['price_file']) && ($_FILES['price_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasUpload && $errors === []) {
        try {
            $uploadedName = Fetcher::storeUpload($_FILES['price_file'], $type);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
    if ($fetchMethod === 'upload' && $uploadedName === null && ($existing === null || empty($existing['file_path']))) {
        $errors[] = 'Загрузите файл остатков.';
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
        redirect('yml.php?tab=warehouses&upload_form=' . $warehouseId);
    }

    $data = [
        'warehouse_id'  => $warehouseId,
        'type'          => $type,
        'fetch_method'  => $fetchMethod,
        'source_url'    => $fetchMethod === 'url' ? mb_substr($sourceUrl, 0, 1000) : null,
        'csv_delimiter' => (string) ($_POST['csv_delimiter'] ?? 'auto'),
        'csv_encoding'  => (string) ($_POST['csv_encoding'] ?? 'auto'),
        'skip_rows'     => max(0, (int) ($_POST['skip_rows'] ?? 1)),
        'sheet_index'   => max(1, (int) ($_POST['sheet_index'] ?? 1)),
        'skip_hidden'   => isset($_POST['skip_hidden']) ? 1 : 0,
        'mapping'       => $mapping,
    ];

    if ($existing === null) {
        $data['file_path'] = $uploadedName;
        $data['original_filename'] = $uploadedName !== null ? (string) $_FILES['price_file']['name'] : null;
        $id = UploadRepository::create($data);
    } else {
        $id = (int) $existing['id'];
        UploadRepository::update($id, $data);
        if ($uploadedName !== null) {
            UploadRepository::setFile($id, $uploadedName, (string) $_FILES['price_file']['name']);
            if (!empty($existing['file_path'])) {
                $old = APP_STORAGE . '/uploads/' . basename((string) $existing['file_path']);
                if (is_file($old)) {
                    @unlink($old);
                }
            }
        }
    }

    if (isset($_POST['save_and_import'])) {
        @set_time_limit(0);
        $saved = UploadRepository::find($id);
        if ($saved !== null) {
            $result = UploadImporter::run($saved);
            flash($result['status'] === 'ok' ? 'success' : 'error', $result['message']);
            if ($result['status'] === 'ok') {
                rebuildQuiet();
            }
        }
        redirect('yml.php?tab=warehouses');
    }

    flash('success', 'Настройки файла склада сохранены.');
    redirect('yml.php?tab=warehouses');
}

function handleSettingsSave(): void
{
    $settings = SettingsRepository::get();

    // Конструктор тегов из таблицы формы.
    $rules = [];
    $names = (array) ($_POST['map_name'] ?? []);
    $kinds = (array) ($_POST['map_kind'] ?? []);
    $types = (array) ($_POST['map_type'] ?? []);
    $refs = (array) ($_POST['map_ref'] ?? []);
    $enabled = (array) ($_POST['map_enabled'] ?? []);
    $delete = (array) ($_POST['map_delete'] ?? []);

    $errors = [];
    foreach ($names as $i => $rawName) {
        if (isset($delete[$i])) {
            continue;
        }
        $name = trim((string) $rawName);
        $kind = in_array(($kinds[$i] ?? 'tag'), ['tag', 'attribute'], true) ? (string) $kinds[$i] : 'tag';
        $type = in_array(($types[$i] ?? 'field'), ['field', 'column', 'catalog', 'constant'], true) ? (string) $types[$i] : 'field';
        $ref = trim((string) ($refs[$i] ?? ''));

        if ($name === '') {
            continue; // пустая строка конструктора — пропускаем
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $name)) {
            $errors[] = 'Некорректное имя элемента XML: «' . $name . '». Допустимы латиница, цифры, _, -, .';
            continue;
        }
        if ($type === 'column' && !preg_match('/^[A-Za-z]{1,3}$/', $ref)) {
            $errors[] = 'Для источника «столбец» укажите букву (A, B, AA), получено: «' . $ref . '».';
            continue;
        }

        $rules[] = [
            'enabled' => isset($enabled[$i]),
            'kind'    => $kind,
            'name'    => $name,
            'source'  => ['type' => $type, 'ref' => $ref],
        ];
    }

    // Кнопки «+ Добавить тег/атрибут» — сохраняем введённое и добавляем пустую строку.
    $addRow = (string) ($_POST['add_row'] ?? '');
    if ($addRow !== '') {
        $rules[] = [
            'enabled' => true,
            'kind'    => $addRow === 'attribute' ? 'attribute' : 'tag',
            'name'    => '',
            'source'  => ['type' => 'field', 'ref' => ''],
        ];
    }

    // Хотя бы один атрибут id у оффера должен остаться — иначе фид бессмысленный.
    $hasId = false;
    foreach ($rules as $rule) {
        if ($rule['enabled'] && $rule['kind'] === 'attribute' && $rule['name'] === 'id') {
            $hasId = true;
            break;
        }
    }
    if (!$hasId && $addRow === '') {
        $errors[] = 'В конструкторе нужен включённый атрибут «id» — из него берётся идентификатор оффера.';
    }

    foreach ($errors as $error) {
        flash('error', $error);
    }

    $outletsInput = (array) ($_POST['outlets'] ?? []);
    $xmlName = static fn (string $v, string $default): string =>
        preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $v) ? $v : $default;

    $outlets = [
        'enabled'    => isset($outletsInput['enabled']),
        'parent'     => $xmlName(trim((string) ($outletsInput['parent'] ?? '')), 'outlets'),
        'tag'        => $xmlName(trim((string) ($outletsInput['tag'] ?? '')), 'outlet'),
        'stock_attr' => $xmlName(trim((string) ($outletsInput['stock_attr'] ?? '')), 'instock'),
        'name_attr'  => $xmlName(trim((string) ($outletsInput['name_attr'] ?? '')), 'warehouse_name'),
    ];

    SettingsRepository::save(
        trim((string) ($_POST['shop_name'] ?? '')),
        trim((string) ($_POST['shop_company'] ?? '')),
        trim((string) ($_POST['shop_url'] ?? '')),
        trim((string) ($_POST['currency'] ?? 'KZT')) ?: 'KZT',
        $rules,
        $outlets,
        isset($_POST['only_confirmed']),
        isset($_POST['skip_zero_stock']),
        isset($_POST['oldprice_only_higher']),
        max(0, (int) ($_POST['stock_fallback'] ?? 0))
    );

    // Соответствие текстовых остатков числам («Более 5» -> 5).
    // Пустые строки в базу не пишем: pattern там UNIQUE.
    $stockRows = [];
    $seenPatterns = [];
    $stockPatterns = (array) ($_POST['stock_pattern'] ?? []);
    $stockQtys = (array) ($_POST['stock_qty'] ?? []);
    $stockDelete = (array) ($_POST['stock_delete'] ?? []);
    foreach ($stockPatterns as $i => $rawPattern) {
        if (isset($stockDelete[$i])) {
            continue;
        }
        $pattern = trim((string) $rawPattern);
        if ($pattern === '') {
            continue;
        }
        $key = mb_strtolower($pattern, 'UTF-8');
        if (isset($seenPatterns[$key])) {
            continue; // дубль — оставляем первый
        }
        $seenPatterns[$key] = true;
        $stockRows[] = ['pattern' => $pattern, 'qty' => max(0, (int) ($stockQtys[$i] ?? 0))];
    }
    StockMapRepository::replaceAll($stockRows);
    $addStockRow = isset($_POST['add_stock_row']);

    if ($errors === []) {
        rebuildQuiet();
        flash('success', $addRow !== '' || $addStockRow ? 'Строка добавлена.' : 'Настройки фида сохранены.');
    } else {
        flash('error', 'Настройки сохранены частично — исправьте отмеченные строки.');
    }
    redirect('yml.php?tab=settings' . ($addStockRow ? '&add_stock=1' : ''));
}

/* ---------- Данные для страниц ---------- */

$flash = flash();
$warehouses = WarehouseRepository::list();
$uploads = UploadRepository::list();
$matchStats = Matcher::stats();
$settings = SettingsRepository::get();
$stockMapRows = StockMapRepository::list();
if ($tab === 'settings' && isset($_GET['add_stock'])) {
    $stockMapRows[] = ['pattern' => '', 'qty' => 0]; // пустая строка по кнопке «+ Добавить соответствие»
}

$matchesSub = (string) ($_GET['sub'] ?? 'queue') === 'items' ? 'items' : 'queue';
$queue = ($tab === 'matches' && $matchesSub === 'queue') ? Matcher::queue() : [];
$confirmedRows = ($tab === 'matches' && $matchesSub === 'items') ? Matcher::confirmedList() : [];
$manualMatch = null;
$candidates = [];
if ($tab === 'matches' && $matchesSub === 'queue' && isset($_GET['match_id'])) {
    $matchId = (int) $_GET['match_id'];
    foreach (Matcher::queue(1000) as $row) {
        if ((int) $row['id'] === $matchId) {
            $manualMatch = $row;
            break;
        }
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $candidates = Matcher::candidates($q);
    }
}

$previewRows = null;
$previewUpload = null;
if ($tab === 'warehouses' && isset($_GET['preview'])) {
    $previewUpload = UploadRepository::find((int) $_GET['preview']);
    if ($previewUpload !== null) {
        try {
            $previewRows = UploadImporter::preview($previewUpload);
        } catch (Throwable $exception) {
            flash('error', 'Не удалось прочитать файл: ' . $exception->getMessage());
            $flash = array_merge($flash, flash());
        }
    }
}

$uploadFormWarehouse = isset($_GET['upload_form']) ? (int) $_GET['upload_form'] : 0;
$warehouseFormId = isset($_GET['wh_form']) ? (int) $_GET['wh_form'] : null; // 0 = новый

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'example.com');
$base = rtrim(dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/yml.php'))), '/');
$feedUrl = $scheme . '://' . $host . $base . '/feed/yml.php?token=' . $settings['token'];

$feedPreview = '';
$feedFile = FeedBuilder::path((string) $settings['token']);
if (is_file($feedFile)) {
    $feedPreview = (string) file_get_contents($feedFile, false, null, 0, 2600);
}

$pageTitle = 'Выгрузка YML';

function sel(mixed $a, mixed $b): string
{
    return $a === $b ? ' selected' : '';
}

function chk(bool $on): string
{
    return $on ? ' checked' : '';
}

$sourceTypeLabels = ['field' => 'Поле файла', 'column' => 'Столбец (буква)', 'catalog' => 'Каталог', 'constant' => 'Константа'];
$pendingCount = $matchStats['suggested'] + $matchStats['unmatched'];
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css', '../')) ?>">
<style>
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 18px; flex-wrap: wrap; }
.tabs a { padding: 9px 14px; text-decoration: none; color: var(--muted); font-weight: 600; font-size: 14px; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tabs a.is-active { color: var(--accent); border-bottom-color: var(--accent); }
.match-arrow { color: var(--muted); text-align: center; }
.feed-preview { background: #10121a; color: #d7e0f4; border-radius: 10px; padding: 14px; font-size: 12.5px; line-height: 1.55; overflow-x: auto; white-space: pre; }
</style>
</head>
<body>

<header class="a-header">
  <div class="a-header__inner">
    <a class="a-header__logo" href="index.php">Админ-панель <span>· <?= e($pageTitle) ?></span></a>
    <nav class="a-nav">
      <a href="index.php">Источники</a>
      <a href="index.php?page=runs">Журнал импортов</a>
      <a href="yml.php" style="color: var(--accent)">Выгрузка YML</a>
      <a href="../index.php">Витрина ↗</a>
    </nav>
    <div class="a-user"><?= e(Auth::username()) ?> · <a href="index.php?page=logout">выйти</a></div>
  </div>
</header>

<main class="a-main">
  <h1 class="a-title">Выгрузка YML</h1>

  <div class="tabs">
    <a href="yml.php?tab=warehouses" class="<?= $tab === 'warehouses' ? 'is-active' : '' ?>">Склады и загрузка</a>
    <a href="yml.php?tab=matches" class="<?= $tab === 'matches' ? 'is-active' : '' ?>">Сопоставление
      <?php if ($pendingCount > 0): ?><span class="pill pill--running"><?= (int) $pendingCount ?></span><?php endif; ?></a>
    <a href="yml.php?tab=settings" class="<?= $tab === 'settings' ? 'is-active' : '' ?>">Настройка фида</a>
    <a href="yml.php?tab=feed" class="<?= $tab === 'feed' ? 'is-active' : '' ?>">Готовый фид</a>
  </div>

  <?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e((string) $item['type']) ?>"><?= e((string) $item['message']) ?></div>
  <?php endforeach; ?>

<?php if ($tab === 'warehouses'): ?>
  <!-- ================= Склады и загрузка ================= -->
  <div class="toolbar">
    <div class="toolbar__stats">
      <span class="stat"><b><?= count($warehouses) ?></b> складов</span>
      <span class="stat"><b><?= (int) array_sum(array_column($warehouses, 'items_count')) ?></b> позиций</span>
      <span class="stat"><b><?= (int) $matchStats['confirmed'] ?></b> сопоставлено</span>
    </div>
    <div class="toolbar__actions">
      <a class="btn btn--primary" href="yml.php?tab=warehouses&wh_form=new">+ Новый склад</a>
    </div>
  </div>

  <div class="card table-wrap">
    <table class="table">
      <thead>
        <tr><th>Склад</th><th>Код</th><th class="num">Позиций</th><th class="num">Сопоставлено</th><th>Статус</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($warehouses === []): ?>
        <tr><td colspan="6" class="muted">Складов пока нет — создайте первый.</td></tr>
      <?php endif; ?>
      <?php foreach ($warehouses as $wh): ?>
        <?php $isVirtual = (string) ($wh['kind'] ?? 'file') === 'virtual'; ?>
        <tr>
          <td>
            <b><?= e((string) $wh['name']) ?></b>
            <?php if ($isVirtual): ?>
              <span class="pill pill--running">виртуальный · <?= (int) ($wh['default_stock'] ?? 0) ?></span>
            <?php endif; ?>
          </td>
          <td class="mono small"><?= e((string) $wh['code']) ?></td>
          <td class="num"><?= $isVirtual ? '—' : (int) $wh['items_count'] ?></td>
          <td class="num"><?= $isVirtual ? '—' : (int) $wh['confirmed_count'] ?></td>
          <td>
            <?php if ((int) $wh['is_active'] === 1): ?>
              <span class="pill pill--ok">активен</span>
            <?php else: ?>
              <span class="pill pill--disabled">отключён</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <a class="btn btn--small btn--ghost" href="yml.php?tab=warehouses&wh_form=<?= (int) $wh['id'] ?>">Изменить</a>
            <?php if (!$isVirtual): ?>
              <a class="btn btn--small btn--ghost" href="yml.php?tab=warehouses&upload_form=<?= (int) $wh['id'] ?>">Файл и маппинг</a>
            <?php endif; ?>
            <form method="post" class="inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="warehouse-toggle">
              <input type="hidden" name="id" value="<?= (int) $wh['id'] ?>">
              <input type="hidden" name="active" value="<?= (int) $wh['is_active'] === 1 ? 0 : 1 ?>">
              <button class="btn btn--small btn--ghost"><?= (int) $wh['is_active'] === 1 ? 'Отключить' : 'Включить' ?></button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('Удалить склад вместе с позициями и сопоставлениями?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="warehouse-delete">
              <input type="hidden" name="id" value="<?= (int) $wh['id'] ?>">
              <button class="btn btn--small btn--danger">Удалить</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($warehouseFormId !== null):
      $whEdit = $warehouseFormId > 0 ? WarehouseRepository::find($warehouseFormId) : null; ?>
    <div class="card">
      <h2 class="card__title"><?= $whEdit === null ? 'Новый склад' : 'Склад: ' . e((string) $whEdit['name']) ?></h2>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="warehouse-save">
        <input type="hidden" name="id" value="<?= (int) ($whEdit['id'] ?? 0) ?>">
        <div class="grid-3">
          <label class="field"><span class="field__label">Название <span class="req">*</span></span>
            <input class="input" name="name" required maxlength="190" value="<?= e((string) ($whEdit['name'] ?? '')) ?>" placeholder="склад 1">
            <span class="field__hint">Именно это название попадёт в атрибут warehouse_name фида.</span>
          </label>
          <label class="field"><span class="field__label">Код</span>
            <input class="input" name="code" maxlength="64" value="<?= e((string) ($whEdit['code'] ?? '')) ?>">
          </label>
          <label class="field"><span class="field__label">Приоритет (sort)</span>
            <input class="input" name="sort" type="number" value="<?= (int) ($whEdit['sort'] ?? 0) ?>">
            <span class="field__hint">Меньше = приоритетнее: цены оффера берутся с этого склада.</span>
          </label>
        </div>
        <div class="grid-3">
          <label class="field"><span class="field__label">Тип склада</span>
            <select class="input" name="kind">
              <option value="file"<?= sel('file', (string) ($whEdit['kind'] ?? 'file')) ?>>Из файла — остатки из прайса</option>
              <option value="virtual"<?= sel('virtual', (string) ($whEdit['kind'] ?? 'file')) ?>>Виртуальный — без файла</option>
            </select>
            <span class="field__hint">Виртуальный склад добавляется в outlets каждого оффера с постоянным остатком.</span>
          </label>
          <label class="field"><span class="field__label">Остаток виртуального склада</span>
            <input class="input" name="default_stock" type="number" min="0" value="<?= (int) ($whEdit['default_stock'] ?? 0) ?>">
            <span class="field__hint">Это число уйдёт в instock у всех офферов. В суммарный остаток для фильтра «исключать нулевые» не входит.</span>
          </label>
        </div>
        <div class="form__actions">
          <button class="btn btn--primary">Сохранить</button>
          <a class="btn btn--ghost" href="yml.php?tab=warehouses">Отмена</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <h2 class="a-subtitle">Файлы остатков</h2>
  <div class="card table-wrap">
    <table class="table">
      <thead>
        <tr><th>Склад</th><th>Файл / ссылка</th><th>Тип</th><th class="num">Позиций</th><th>Обновлён</th><th>Статус</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($uploads === []): ?>
        <tr><td colspan="7" class="muted">Файлы ещё не настроены. Нажмите «Файл и маппинг» у склада.</td></tr>
      <?php endif; ?>
      <?php foreach ($uploads as $up): ?>
        <tr>
          <td><b><?= e((string) $up['warehouse_name']) ?></b></td>
          <td class="mono small ellipsis">
            <?= $up['fetch_method'] === 'url' ? e((string) $up['source_url']) : e((string) ($up['original_filename'] ?? $up['file_path'] ?? '—')) ?>
          </td>
          <td><?= e(UploadRepository::TYPES[(string) $up['type']] ?? (string) $up['type']) ?></td>
          <td class="num"><?= (int) $up['items_count'] ?></td>
          <td class="small"><?= e(format_datetime($up['last_run_at'] ?? null)) ?></td>
          <td>
            <?php
            $pill = match ((string) $up['last_status']) {
                'ok' => '<span class="pill pill--ok">OK</span>',
                'error' => '<span class="pill pill--error">Ошибка</span>',
                'running' => '<span class="pill pill--running">Импорт…</span>',
                default => '<span class="pill pill--never">ещё не загружался</span>',
            };
            echo $pill;
            ?>
            <?php if ($up['last_status'] === 'error' && !empty($up['last_error'])): ?>
              <div class="small error-text"><?= e(mb_substr((string) $up['last_error'], 0, 200)) ?></div>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <form method="post" class="inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="upload-import">
              <input type="hidden" name="id" value="<?= (int) $up['id'] ?>">
              <button class="btn btn--small btn--primary">Загрузить</button>
            </form>
            <a class="btn btn--small btn--ghost" href="yml.php?tab=warehouses&preview=<?= (int) $up['id'] ?>">Проверить маппинг</a>
            <a class="btn btn--small btn--ghost" href="yml.php?tab=warehouses&upload_form=<?= (int) $up['warehouse_id'] ?>">Изменить</a>
            <form method="post" class="inline" onsubmit="return confirm('Удалить файл и позиции склада?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="upload-delete">
              <input type="hidden" name="id" value="<?= (int) $up['id'] ?>">
              <button class="btn btn--small btn--danger">Удалить</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($uploadFormWarehouse > 0):
      $upWh = WarehouseRepository::find($uploadFormWarehouse);
      $upEdit = UploadRepository::findByWarehouse($uploadFormWarehouse);
      $upMapping = $upEdit !== null ? UploadRepository::mappingOf($upEdit) : [];
      if ($upWh !== null): ?>
    <div class="card">
      <h2 class="card__title">Файл остатков: <?= e((string) $upWh['name']) ?></h2>
      <p class="card__hint">Тот же механизм, что и у источников каталога: Excel, CSV или YML — файлом или по ссылке (Google Таблицы, Яндекс Диск).</p>
      <form method="post" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="upload-save">
        <input type="hidden" name="warehouse_id" value="<?= (int) $uploadFormWarehouse ?>">
        <div class="grid-3">
          <label class="field"><span class="field__label">Тип файла</span>
            <select class="input" name="type">
              <?php foreach (UploadRepository::TYPES as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= sel($key, (string) ($upEdit['type'] ?? 'excel')) ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field"><span class="field__label">Способ получения</span>
            <select class="input" name="fetch_method">
              <option value="upload"<?= sel('upload', (string) ($upEdit['fetch_method'] ?? 'upload')) ?>>Загрузить файл</option>
              <option value="url"<?= sel('url', (string) ($upEdit['fetch_method'] ?? 'upload')) ?>>По ссылке</option>
            </select>
          </label>
          <label class="field"><span class="field__label">Ссылка на файл</span>
            <input class="input" name="source_url" value="<?= e((string) ($upEdit['source_url'] ?? '')) ?>" placeholder="https://…">
          </label>
        </div>
        <label class="field"><span class="field__label">Файл</span>
          <input class="input" type="file" name="price_file">
          <span class="field__hint">При ручной загрузке; новый файл заменяет прежний.</span>
        </label>

        <h3 class="a-subtitle">Маппинг полей</h3>
        <div class="grid-3">
          <?php foreach (UploadRepository::FIELDS as $field => $label): ?>
            <label class="field"><span class="field__label"><?= e($label) ?></span>
              <input class="input" name="mapping[<?= e($field) ?>]" value="<?= e((string) ($upMapping[$field] ?? '')) ?>" placeholder="A или 1 (для YML — тег)">
            </label>
          <?php endforeach; ?>
        </div>
        <div class="grid-3">
          <label class="field"><span class="field__label">Пропустить строк сверху</span>
            <input class="input" name="skip_rows" type="number" min="0" value="<?= (int) ($upEdit['skip_rows'] ?? 1) ?>">
          </label>
          <label class="field"><span class="field__label">Номер листа Excel</span>
            <input class="input" name="sheet_index" type="number" min="1" value="<?= (int) ($upEdit['sheet_index'] ?? 1) ?>">
          </label>
          <label class="field"><span class="field__label">Разделитель CSV</span>
            <input class="input" name="csv_delimiter" value="<?= e((string) ($upEdit['csv_delimiter'] ?? 'auto')) ?>">
          </label>
          <label class="field"><span class="field__label">Кодировка CSV</span>
            <input class="input" name="csv_encoding" value="<?= e((string) ($upEdit['csv_encoding'] ?? 'auto')) ?>">
          </label>
        </div>
        <label class="check-inline"><input type="checkbox" name="skip_hidden"<?= chk((int) ($upEdit['skip_hidden'] ?? 1) === 1) ?>> Не импортировать скрытые строки и столбцы</label>
        <div class="form__actions">
          <button class="btn btn--primary" name="save_and_import" value="1">Сохранить и загрузить</button>
          <button class="btn btn--ghost">Сохранить</button>
          <a class="btn btn--link" href="yml.php?tab=warehouses">Отмена</a>
        </div>
      </form>
    </div>
  <?php endif; endif; ?>

  <?php if ($previewUpload !== null && $previewRows !== null): ?>
    <div class="card table-wrap">
      <h2 class="card__title" style="padding:18px 18px 0">Проверка маппинга: <?= e((string) $previewUpload['warehouse_name'] ?? '') ?></h2>
      <p class="card__hint" style="padding:0 18px">Первые строки файла как есть. Столбцы для маппинга указывайте буквами: A — первый, B — второй и т.д.</p>
      <table class="table table--compact">
        <tbody>
        <?php foreach ($previewRows as $i => $row): ?>
          <tr>
            <td class="muted small"><?= $i + 1 ?></td>
            <?php foreach ($row as $j => $cell): ?>
              <td><span class="pill pill--never"><?= e(is_numeric($j) ? ColumnRef::toLetter((int) $j) : (string) $j) ?></span> <?= e(mb_substr((string) $cell, 0, 80)) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="form__actions" style="padding:0 18px 18px"><a class="btn btn--ghost" href="yml.php?tab=warehouses">Закрыть</a></div>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'matches'): ?>
  <!-- ================= Сопоставление ================= -->
  <div class="tabs" style="margin-bottom:14px">
    <a href="yml.php?tab=matches&sub=queue" class="<?= $matchesSub === 'queue' ? 'is-active' : '' ?>">К сопоставлению
      <?php if ($pendingCount > 0): ?><span class="pill pill--running"><?= (int) $pendingCount ?></span><?php endif; ?></a>
    <a href="yml.php?tab=matches&sub=items" class="<?= $matchesSub === 'items' ? 'is-active' : '' ?>">Товары
      <?php if ((int) $matchStats['confirmed'] > 0): ?><span class="pill pill--ok"><?= (int) $matchStats['confirmed'] ?></span><?php endif; ?></a>
  </div>

<?php if ($matchesSub === 'queue'): ?>
  <div class="toolbar">
    <div class="toolbar__stats">
      <span class="stat"><b><?= (int) $matchStats['suggested'] ?></b> ждут подтверждения</span>
      <span class="stat"><b><?= (int) $matchStats['unmatched'] ?></b> без пары</span>
      <span class="stat"><b><?= (int) $matchStats['confirmed'] ?></b> подтверждено</span>
      <span class="stat"><b><?= (int) $matchStats['rejected'] ?></b> отклонено</span>
    </div>
    <div class="toolbar__actions">
      <form method="post" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="match-bulk">
        <input class="input" style="width:90px" type="number" name="min_score" value="80" min="50" max="100" title="Минимальное совпадение, %">
        <button class="btn btn--primary">Подтвердить все ≥ %</button>
      </form>
    </div>
  </div>

  <?php if ($manualMatch !== null): ?>
    <div class="card">
      <h2 class="card__title">Ручное сопоставление</h2>
      <p class="card__hint">
        Позиция: <b><?= e((string) ($manualMatch['item_name'] ?? $manualMatch['item_sku'])) ?></b>
        <span class="mono small muted"><?= e((string) $manualMatch['item_sku']) ?></span>
        · склад «<?= e((string) $manualMatch['warehouse_name']) ?>»
      </p>
      <form method="get" class="form__actions" style="margin-bottom:14px">
        <input type="hidden" name="tab" value="matches">
        <input type="hidden" name="match_id" value="<?= (int) $manualMatch['id'] ?>">
        <input class="input" style="max-width:320px" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="Поиск по названию или артикулу каталога">
        <button class="btn btn--primary">Найти</button>
        <a class="btn btn--ghost" href="yml.php?tab=matches">Закрыть</a>
      </form>
      <?php if (trim((string) ($_GET['q'] ?? '')) !== ''): ?>
        <?php if ($candidates === []): ?>
          <p class="muted">Ничего не найдено — попробуйте другой запрос.</p>
        <?php else: ?>
          <table class="table table--compact">
            <tbody>
            <?php foreach ($candidates as $candidate): ?>
              <tr>
                <td><b><?= e((string) $candidate['name']) ?></b><br><span class="mono small muted"><?= e((string) $candidate['sku']) ?></span></td>
                <td><span class="pill <?= (int) $candidate['score'] >= 50 ? 'pill--running' : 'pill--never' ?>"><?= (int) $candidate['score'] ?>%</span></td>
                <td class="row-actions">
                  <form method="post" class="inline">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="match-confirm">
                    <input type="hidden" name="match_id" value="<?= (int) $manualMatch['id'] ?>">
                    <input type="hidden" name="product_id" value="<?= (int) $candidate['id'] ?>">
                    <button class="btn btn--small btn--primary">Подтвердить</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card table-wrap">
    <table class="table">
      <thead>
        <tr><th>Моя позиция</th><th></th><th>Кандидат из каталога</th><th>Совпадение</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($queue === []): ?>
        <tr><td colspan="5" class="muted">Очередь пуста: все позиции сопоставлены и подтверждены.</td></tr>
      <?php endif; ?>
      <?php foreach ($queue as $row): ?>
        <tr>
          <td>
            <b><?= e((string) ($row['item_name'] ?? $row['item_sku'])) ?></b><br>
            <span class="muted small mono"><?= e((string) $row['item_sku']) ?> · <?= e((string) $row['warehouse_name']) ?><?= $row['stock_qty'] !== null ? ' · остаток ' . (int) $row['stock_qty'] : '' ?></span>
          </td>
          <td class="match-arrow">→</td>
          <td>
            <?php if ($row['product_id'] !== null && $row['product_name'] !== null): ?>
              <b><?= e((string) $row['product_name']) ?></b><br>
              <span class="muted small mono"><?= e((string) $row['product_sku_live']) ?></span>
            <?php elseif ($row['product_id'] !== null): ?>
              <span class="muted">Товар удалён из каталога (был <?= e((string) $row['product_sku']) ?>)</span>
            <?php else: ?>
              <span class="muted">Автоматически пара не найдена</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['method'] === 'sku'): ?>
              <span class="pill pill--ok">артикул <?= (int) $row['score'] ?>%</span>
            <?php elseif ($row['method'] === 'name'): ?>
              <span class="pill pill--running">название <?= (int) $row['score'] ?>%</span>
            <?php else: ?>
              <span class="pill pill--never">—</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <?php if ($row['product_id'] !== null): ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="match-confirm">
                <input type="hidden" name="match_id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn--small btn--primary">Подтвердить</button>
              </form>
            <?php endif; ?>
            <a class="btn btn--small btn--ghost" href="yml.php?tab=matches&match_id=<?= (int) $row['id'] ?>"><?= $row['product_id'] !== null ? 'Другой' : 'Сопоставить' ?></a>
            <?php if ($row['product_id'] !== null): ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="match-reject">
                <input type="hidden" name="match_id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn--small btn--danger">Не сопоставлять</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>
  <!-- ================= Товары (подтверждённые сопоставления) ================= -->
  <div class="card table-wrap">
    <table class="table">
      <thead>
        <tr><th>Моя позиция</th><th></th><th>Товар каталога</th><th>Совпадение</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($confirmedRows === []): ?>
        <tr><td colspan="5" class="muted">Подтверждённых сопоставлений пока нет — они появятся здесь после подтверждения в очереди «К сопоставлению».</td></tr>
      <?php endif; ?>
      <?php foreach ($confirmedRows as $row): ?>
        <tr>
          <td>
            <b><?= e((string) ($row['item_name'] ?? $row['item_sku'])) ?></b><br>
            <span class="muted small mono"><?= e((string) $row['item_sku']) ?> · <?= e((string) $row['warehouse_name']) ?><?= $row['stock_qty'] !== null ? ' · остаток ' . (int) $row['stock_qty'] : '' ?></span>
          </td>
          <td class="match-arrow">→</td>
          <td>
            <?php if ($row['product_name'] !== null): ?>
              <b><?= e((string) $row['product_name']) ?></b><br>
              <span class="muted small mono"><?= e((string) $row['product_sku_live']) ?></span>
            <?php else: ?>
              <span class="muted">Товар удалён из каталога (был <?= e((string) $row['product_sku']) ?>)</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['method'] === 'sku'): ?>
              <span class="pill pill--ok">артикул <?= (int) $row['score'] ?>%</span>
            <?php elseif ($row['method'] === 'name'): ?>
              <span class="pill pill--running">название <?= (int) $row['score'] ?>%</span>
            <?php elseif ($row['method'] === 'manual'): ?>
              <span class="pill pill--ok">вручную</span>
            <?php else: ?>
              <span class="pill pill--never">—</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <form method="post" class="inline" onsubmit="return confirm('Удалить сопоставление? Позиция вернётся в очередь «К сопоставлению».')">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="match-unmatch">
              <input type="hidden" name="match_id" value="<?= (int) $row['id'] ?>">
              <button class="btn btn--small btn--danger">Удалить сопоставление</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($confirmedRows) >= 500): ?>
    <p class="muted small">Показаны первые 500 подтверждённых сопоставлений.</p>
  <?php endif; ?>
<?php endif; ?>

<?php elseif ($tab === 'settings'): ?>
  <!-- ================= Настройка фида ================= -->
  <form method="post">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="settings-save">

    <div class="card">
      <h2 class="card__title">Магазин (блок &lt;shop&gt;)</h2>
      <div class="grid-3">
        <label class="field"><span class="field__label">Название</span>
          <input class="input" name="shop_name" value="<?= e((string) $settings['shop_name']) ?>"></label>
        <label class="field"><span class="field__label">Компания</span>
          <input class="input" name="shop_company" value="<?= e((string) $settings['shop_company']) ?>"></label>
        <label class="field"><span class="field__label">Ссылка</span>
          <input class="input" name="shop_url" value="<?= e((string) $settings['shop_url']) ?>"></label>
      </div>
    </div>

    <div class="card table-wrap">
      <h2 class="card__title" style="padding:18px 18px 0">Конструктор оффера</h2>
      <p class="card__hint" style="padding:0 18px">
        Каждая строка — тег или атрибут внутри &lt;offer&gt;. Имя можно заменить на своё,
        значение привязать к полю файла склада (price, oldprice, min_price, sku, name, stock),
        к столбцу по букве (A, B, C…), к товару каталога (sku, name) или к константе.
      </p>
      <table class="table table--compact">
        <thead>
          <tr><th>Вкл.</th><th>Элемент</th><th>Имя в XML</th><th>Источник</th><th>Поле / буква / значение</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($settings['offer_mapping_array'] as $i => $rule):
            $src = (array) ($rule['source'] ?? []); ?>
          <tr>
            <td><input type="checkbox" name="map_enabled[<?= $i ?>]"<?= chk(!empty($rule['enabled'])) ?>></td>
            <td>
              <select class="input" name="map_kind[<?= $i ?>]">
                <option value="tag"<?= sel('tag', (string) ($rule['kind'] ?? 'tag')) ?>>тег</option>
                <option value="attribute"<?= sel('attribute', (string) ($rule['kind'] ?? 'tag')) ?>>атрибут offer</option>
              </select>
            </td>
            <td><input class="input mono" name="map_name[<?= $i ?>]" value="<?= e((string) ($rule['name'] ?? '')) ?>" placeholder="price"></td>
            <td>
              <select class="input" name="map_type[<?= $i ?>]">
                <?php foreach ($sourceTypeLabels as $key => $label): ?>
                  <option value="<?= e($key) ?>"<?= sel($key, (string) ($src['type'] ?? 'field')) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input class="input mono" name="map_ref[<?= $i ?>]" value="<?= e((string) ($src['ref'] ?? '')) ?>" placeholder="price · B · sku · текст"></td>
            <td><label class="check-inline" style="margin:0"><input type="checkbox" name="map_delete[<?= $i ?>]"> <span class="muted small">удалить</span></label></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="form__actions" style="padding:0 18px 18px">
        <button class="btn btn--ghost" name="add_row" value="tag">+ Добавить тег</button>
        <button class="btn btn--ghost" name="add_row" value="attribute">+ Добавить атрибут offer</button>
      </div>
    </div>

    <div class="card">
      <h2 class="card__title">Остатки по складам (outlets)</h2>
      <label class="check-inline"><input type="checkbox" name="outlets[enabled]"<?= chk(!empty($settings['outlets_array']['enabled'])) ?>> Включать блок остатков по складам</label>
      <div class="grid-3">
        <label class="field"><span class="field__label">Родительский тег</span>
          <input class="input mono" name="outlets[parent]" value="<?= e((string) ($settings['outlets_array']['parent'] ?? 'outlets')) ?>"></label>
        <label class="field"><span class="field__label">Тег склада</span>
          <input class="input mono" name="outlets[tag]" value="<?= e((string) ($settings['outlets_array']['tag'] ?? 'outlet')) ?>"></label>
        <label class="field"><span class="field__label">Атрибут остатка</span>
          <input class="input mono" name="outlets[stock_attr]" value="<?= e((string) ($settings['outlets_array']['stock_attr'] ?? 'instock')) ?>"></label>
        <label class="field"><span class="field__label">Атрибут имени склада</span>
          <input class="input mono" name="outlets[name_attr]" value="<?= e((string) ($settings['outlets_array']['name_attr'] ?? 'warehouse_name')) ?>"></label>
        <label class="field field--narrow"><span class="field__label">Валюта фида</span>
          <input class="input" name="currency" value="<?= e((string) $settings['currency']) ?>"></label>
      </div>
    </div>

    <div class="card table-wrap">
      <h2 class="card__title" style="padding:18px 18px 0">Соответствие остатков</h2>
      <p class="card__hint" style="padding:0 18px">
        Если поставщик передаёт остаток текстом («Более 5», «мало», «в наличии»), здесь задаётся,
        какое число уйдёт в фид в атрибут instock. Совпадение — сначала точное, затем по вхождению,
        регистр не важен. Числа из файла передаются как есть, без соответствий.
      </p>
      <table class="table table--compact">
        <thead>
          <tr><th>Значение из файла</th><th style="width:170px">Передавать количество</th><th style="width:90px"></th></tr>
        </thead>
        <tbody>
        <?php if ($stockMapRows === []): ?>
          <tr><td colspan="3" class="muted">Соответствий пока нет — добавьте первое.</td></tr>
        <?php endif; ?>
        <?php foreach ($stockMapRows as $i => $stockRow): ?>
          <tr>
            <td><input class="input" name="stock_pattern[<?= $i ?>]" maxlength="190" value="<?= e((string) $stockRow['pattern']) ?>" placeholder="Более 5"></td>
            <td><input class="input" type="number" min="0" name="stock_qty[<?= $i ?>]" value="<?= (int) $stockRow['qty'] ?>"></td>
            <td><label class="check-inline" style="margin:0"><input type="checkbox" name="stock_delete[<?= $i ?>]"> <span class="muted small">удалить</span></label></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="grid-3" style="padding:0 18px">
        <label class="field"><span class="field__label">Если соответствие не найдено — передавать</span>
          <input class="input" type="number" min="0" name="stock_fallback" value="<?= (int) ($settings['stock_fallback'] ?? 0) ?>">
          <span class="field__hint">Например 0: неизвестный текстовый остаток уйдёт в фид нулём.</span>
        </label>
      </div>
      <div class="form__actions" style="padding:0 18px 18px">
        <button class="btn btn--ghost" name="add_stock_row" value="1">+ Добавить соответствие</button>
      </div>
    </div>

    <div class="card">
      <h2 class="card__title">Прочее</h2>
      <label class="check-inline"><input type="checkbox" name="only_confirmed"<?= chk((int) $settings['only_confirmed'] === 1) ?>> Включать в фид только подтверждённые сопоставления</label>
      <label class="check-inline"><input type="checkbox" name="skip_zero_stock"<?= chk((int) $settings['skip_zero_stock'] === 1) ?>> Исключать офферы с нулевым суммарным остатком <span class="muted small">(виртуальные склады в сумме не учитываются)</span></label>
      <label class="check-inline"><input type="checkbox" name="oldprice_only_higher"<?= chk((int) $settings['oldprice_only_higher'] === 1) ?>> oldprice выводить, только если больше price</label>
      <div class="form__actions">
        <button class="btn btn--primary">Сохранить настройки</button>
      </div>
    </div>
  </form>

<?php else: ?>
  <!-- ================= Готовый фид ================= -->
  <?php if ($settings['built_at'] !== null): ?>
    <div class="alert alert--success">
      Фид собран <?= e(format_datetime($settings['built_at'])) ?> · офферов: <b><?= (int) $settings['built_offers'] ?></b>
      <?php if (is_file($feedFile)): ?>· размер: <?= number_format(filesize($feedFile) / 1024, 1, ',', ' ') ?> КБ<?php endif; ?>
    </div>
  <?php else: ?>
    <div class="alert alert--warning">Фид ещё не собирался — нажмите «Пересобрать сейчас».</div>
  <?php endif; ?>

  <div class="card">
    <h2 class="card__title">Склады в фиде</h2>
    <p class="card__hint">Только отмеченные склады попадают в YML: их остатки идут в outlets, цены оффера берутся по приоритету. Отключённые склады в фид не попадут независимо от галочки.</p>
    <form method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="warehouses-feed-save">
      <?php if ($warehouses === []): ?>
        <p class="muted">Складов пока нет — создайте их на вкладке «Склады и загрузка».</p>
      <?php endif; ?>
      <?php foreach ($warehouses as $wh): ?>
        <label class="check-inline" style="display:block">
          <input type="checkbox" name="in_feed[]" value="<?= (int) $wh['id'] ?>"<?= chk((int) ($wh['in_feed'] ?? 1) === 1) ?>>
          <b><?= e((string) $wh['name']) ?></b>
          <?php if ((string) ($wh['kind'] ?? 'file') === 'virtual'): ?>
            <span class="pill pill--running">виртуальный · <?= (int) ($wh['default_stock'] ?? 0) ?></span>
          <?php endif; ?>
          <?php if ((int) $wh['is_active'] !== 1): ?>
            <span class="pill pill--disabled">отключён</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
      <?php if ($warehouses !== []): ?>
        <div class="form__actions">
          <button class="btn btn--primary">Сохранить</button>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h2 class="card__title">Ссылка на фид</h2>
    <p class="card__hint">Отдаёт актуальный XML из кэша; пересобирается после загрузки склада, подтверждения сопоставлений и сохранения настроек.</p>
    <label class="field">
      <input class="input mono" readonly value="<?= e($feedUrl) ?>" onclick="this.select()">
      <span class="field__hint">Токен можно перевыпустить — старая ссылка перестанет работать.</span>
    </label>
    <div class="form__actions">
      <form method="post" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="feed-build">
        <button class="btn btn--primary">Пересобрать сейчас</button>
      </form>
      <form method="post" class="inline" onsubmit="return confirm('Старая ссылка перестанет работать. Перевыпустить токен?')">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="token-regenerate">
        <button class="btn btn--ghost">Перевыпустить токен</button>
      </form>
    </div>
  </div>

  <?php if ($feedPreview !== ''): ?>
    <div class="card">
      <h2 class="card__title">Предпросмотр (начало файла)</h2>
      <pre class="feed-preview"><?= e($feedPreview) ?></pre>
    </div>
  <?php endif; ?>
<?php endif; ?>

</main>
</body>
</html>
