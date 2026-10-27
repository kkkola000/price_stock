<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Import\ColumnRef;
use App\Import\Fetcher;
use App\Import\ImportService;
use App\Import\ValueParser;
use App\Models\ImportRunRepository;
use App\Models\ProductRepository;
use App\Models\SourceRepository;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Migrator;
use App\Support\Scheduler;

/**
 * Любая необработанная ошибка в админке должна показывать текст, а не пустую
 * страницу: подробности видны только авторизованному администратору.
 */
set_exception_handler(static function (Throwable $exception): void {
    error_log('[admin] ' . $exception);
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
        . '</div><p class="muted small">Подробности записаны в storage/logs/php-error.log.<br>'
        . 'Если вы только что обновили файлы проекта, выполните <code>./deploy/update.sh</code> '
        . 'или откройте админку и нажмите «Обновить базу».</p>'
        . '<p><a class="btn btn--ghost" href="index.php">Вернуться в админку</a></p></main></body></html>';
});

try {
    if (!Db::tableExists('sources')) {
        redirect('../install.php');
    }
} catch (PDOException $exception) {
    error_log('[admin] ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    exit('<h1>Нет соединения с базой данных</h1><p>Проверьте секцию <code>db</code> в config.php. '
        . 'Текст ошибки записан в storage/logs/php-error.log.</p>');
}

$page = (string) ($_GET['page'] ?? 'sources');

/* ---------- Аутентификация ---------- */

if ($page === 'login') {
    if (Auth::check()) {
        redirect('index.php');
    }
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::check();
        $login = trim((string) ($_POST['username'] ?? ''));
        if (!Auth::attempt($login, (string) ($_POST['password'] ?? ''))) {
            $error = 'Неверный логин или пароль.';
            usleep(400000);
        } else {
            redirect('index.php');
        }
    }
    render('admin/login', ['error' => $error], 'Вход в админ-панель', false);
    exit;
}

if ($page === 'logout') {
    Auth::logout();
    redirect('index.php?page=login');
}

Auth::requireLogin();
Csrf::check();

/**
 * Частый случай: файлы обновили, а структуру базы — нет. Раньше это давало
 * пустую страницу с кодом 500 при сохранении, теперь — понятное сообщение
 * и кнопка «Обновить базу» прямо в админке.
 */
$pendingMigrations = Migrator::pending();

if ($page === 'migrate') {
    try {
        $result = Migrator::migrate();
        flash('success', $result['applied'] === []
            ? 'База данных уже обновлена.'
            : 'База данных обновлена: ' . implode(', ', $result['applied']) . '. '
              . 'Запустите импорт, чтобы заполнить новые поля.');
    } catch (Throwable $exception) {
        flash('error', 'Не удалось обновить базу: ' . $exception->getMessage());
    }
    redirect('index.php');
}

$writeActions = ['source-save', 'source-delete', 'source-toggle', 'import-run', 'import-all'];
if ($pendingMigrations !== [] && in_array($page, $writeActions, true)) {
    flash('error', 'Структура базы данных устарела, поэтому изменения не сохранены. '
        . 'Нажмите «Обновить базу» вверху страницы (или выполните ./deploy/update.sh) и повторите.');
    redirect('index.php');
}

/* ---------- Действия ---------- */

switch ($page) {
    case 'settings':
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            try {
                App\Support\TimezoneSettings::save(trim((string)($_POST['timezone']??'')));
                flash('success','Часовой пояс сохранён.');
            } catch (Throwable $exception) {flash('error',$exception->getMessage());}
            redirect('index.php?page=settings');
        }
        render('admin/settings', [], 'Настройки сервиса');
        exit;

    case 'source-save':
        handleSourceSave();
        break;

    case 'source-delete':
        $id = (int) ($_POST['id'] ?? 0);
        SourceRepository::delete($id);
        flash('success', 'Источник удалён вместе с его товарами.');
        redirect('index.php');
        break;

    case 'source-toggle':
        $id = (int) ($_POST['id'] ?? 0);
        $active = (int) ($_POST['active'] ?? 0) === 1;
        SourceRepository::setActive($id, $active);
        flash('success', $active ? 'Источник включён.' : 'Источник отключён (товары скрыты с витрины, данные сохранены).');
        redirect('index.php');
        break;

    case 'import-run':
        handleImportRun();
        break;

    case 'import-all':
        @set_time_limit(0);
        $sources = SourceRepository::list(['only_active' => true]);
        $results = ImportService::runMany($sources, 'manual');
        $ok = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'ok'));
        flash($ok === count($results) ? 'success' : 'warning', sprintf(
            'Обновлено источников: %d из %d.',
            $ok,
            count($results)
        ));
        foreach ($results as $result) {
            if ($result['status'] !== 'ok') {
                flash('error', $result['source_name'] . ': ' . $result['message']);
            }
        }
        redirect('index.php');
        break;
}

/* ---------- Страницы ---------- */

switch ($page) {
    case 'source-form':
        $id = (int) ($_GET['id'] ?? 0);
        $source = $id > 0 ? SourceRepository::find($id) : null;
        if ($id > 0 && $source === null) {
            flash('error', 'Источник не найден.');
            redirect('index.php');
        }
        render('admin/source_form', [
            'source' => $source,
            'old'    => $_SESSION['old_input'] ?? null,
        ], $source === null ? 'Новый источник' : 'Редактирование источника');
        unset($_SESSION['old_input']);
        break;

    case 'source-preview':
        $id = (int) ($_GET['id'] ?? 0);
        $source = SourceRepository::find($id);
        if ($source === null) {
            flash('error', 'Источник не найден.');
            redirect('index.php');
        }
        $preview = null;
        $error = null;
        try {
            $preview = ImportService::preview($source, 8);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
        render('admin/preview', [
            'source'  => $source,
            'preview' => $preview,
            'error'   => $error,
            'sample'  => ProductRepository::sampleBySource($id, 10),
        ], 'Проверка маппинга: ' . $source['name']);
        break;

    case 'runs':
        $sourceId = (int) ($_GET['source_id'] ?? 0);
        render('admin/runs', [
            'runs'     => ImportRunRepository::recent($sourceId > 0 ? $sourceId : null, 100),
            'sourceId' => $sourceId,
            'sources'  => SourceRepository::list(),
        ], 'Журнал импортов');
        break;

    case 'sources':
    default:
        $sources = SourceRepository::list();
        $autoSources = array_filter($sources, static fn (array $source): bool =>
            (int) $source['auto_import'] === 1
            && (int) $source['is_active'] === 1
            && $source['fetch_method'] === 'url');

        render('admin/sources', [
            'sources'       => $sources,
            'totalProducts' => ProductRepository::totalCount(),
            'lastRuns'      => ImportRunRepository::recent(null, 5),
            'scheduler'     => [
                'ping'         => Scheduler::lastPing(),
                'minutes'      => Scheduler::minutesSinceLastPing(),
                'auto_sources' => count($autoSources),
            ],
        ], 'Источники данных');
        break;
}

/* ---------- Обработчики ---------- */

function handleSourceSave(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $existing = $id > 0 ? SourceRepository::find($id) : null;

    $name        = trim((string) ($_POST['name'] ?? ''));
    $type        = (string) ($_POST['type'] ?? '');
    $fetchMethod = (string) ($_POST['fetch_method'] ?? '');
    $sourceUrl   = trim((string) ($_POST['source_url'] ?? ''));

    $mappingInput = (array) ($_POST['mapping'] ?? []);
    $mapping = [];
    foreach (array_keys(SourceRepository::FIELDS) as $field) {
        $mapping[$field] = trim((string) ($mappingInput[$field] ?? ''));
    }


    $errors = [];

    if ($name === '') {
        $errors[] = 'Укажите название поставщика.';
    }
    if (!isset(SourceRepository::TYPES[$type])) {
        $errors[] = 'Выберите тип источника.';
    }
    if (!isset(SourceRepository::FETCH_METHODS[$fetchMethod])) {
        $errors[] = 'Выберите способ получения файла.';
    }
    if ($fetchMethod === 'url' && !preg_match('~^https?://~i', $sourceUrl)) {
        $errors[] = 'Укажите корректную ссылку на файл (http:// или https://).';
    }

    if (in_array($type, ['excel', 'csv'], true)) {
        foreach (SourceRepository::FIELDS as $field => $meta) {
            if ($mapping[$field] === '') {
                continue;
            }
            try {
                ColumnRef::toIndex($mapping[$field]);
            } catch (Throwable $exception) {
                $errors[] = $meta['label'] . ': ' . $exception->getMessage();
            }
        }
    }

    // Файл: обязателен при ручной загрузке, если ранее не загружался.
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
        $errors[] = 'Загрузите файл прайса.';
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
        $_SESSION['old_input'] = $_POST;
        redirect('index.php?page=source-form' . ($id > 0 ? '&id=' . $id : ''));
    }

    $data = [
        'name'                    => mb_substr($name, 0, 190),
        'type'                    => $type,
        'fetch_method'            => $fetchMethod,
        'source_url'              => $fetchMethod === 'url' ? mb_substr($sourceUrl, 0, 1000) : null,
        'is_active'               => isset($_POST['is_active']) ? 1 : 0,
        'auto_import'             => isset($_POST['auto_import']) ? 1 : 0,
        'import_interval_minutes' => max(15, (int) ($_POST['import_interval_minutes'] ?? 1440)),
        'csv_delimiter'           => (string) ($_POST['csv_delimiter'] ?? 'auto'),
        'csv_encoding'            => (string) ($_POST['csv_encoding'] ?? 'auto'),
        'skip_rows'               => max(0, (int) ($_POST['skip_rows'] ?? 1)),
        'sheet_index'             => max(1, (int) ($_POST['sheet_index'] ?? 1)),
        'skip_hidden'             => isset($_POST['skip_hidden']) ? 1 : 0,
        // Приводим к тому же виду, что и валюту из файла: «руб» -> RUB, «тг» -> KZT
        'currency_code'           => trim((string) ($_POST['currency_code'] ?? '')) === ''
            ? ''
            : ValueParser::currency((string) $_POST['currency_code'], ''),
        'mapping'                 => $mapping,
    ];

    if ($uploadedName !== null) {
        $data['file_path'] = $uploadedName;
        $data['original_filename'] = mb_substr((string) $_FILES['price_file']['name'], 0, 255);

        if ($existing !== null && !empty($existing['file_path'])) {
            $old = APP_STORAGE . '/uploads/' . $existing['file_path'];
            if (is_file($old)) {
                @unlink($old);
            }
        }
    }

    if ($id > 0) {
        SourceRepository::update($id, $data);
        flash('success', 'Источник сохранён.');
    } else {
        $id = SourceRepository::create($data);
        flash('success', 'Источник создан. Запустите импорт, чтобы загрузить товары.');
    }

    if (isset($_POST['save_and_import'])) {
        @set_time_limit(0);
        $saved = SourceRepository::find($id);
        if ($saved !== null) {
            $result = ImportService::run($saved, 'manual');
            flash($result['status'] === 'ok' ? 'success' : 'error', $saved['name'] . ': ' . $result['message']);
        }
        redirect('index.php');
    }
    redirect('index.php?page=source-form&id=' . $id);
}

function handleImportRun(): void
{
    @set_time_limit(0);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('index.php');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $source = SourceRepository::find($id);
    if ($source === null) {
        flash('error', 'Источник не найден.');
        redirect('index.php');
    }

    $result = ImportService::run($source, 'manual');
    flash($result['status'] === 'ok' ? 'success' : 'error', $source['name'] . ': ' . $result['message']);
    redirect('index.php');
}

/**
 * Отрисовка страницы админки через общий макет.
 *
 * @param array<string,mixed> $data
 */
function render(string $view, array $data, string $title, bool $withNav = true): void
{
    extract($data, EXTR_SKIP);
    $pageTitle = $title;
    $showNav = $withNav;
    $pendingMigrations = $GLOBALS['pendingMigrations'] ?? [];
    $content = APP_ROOT . '/app/Views/' . $view . '.php';
    require APP_ROOT . '/app/Views/admin/layout.php';
}
