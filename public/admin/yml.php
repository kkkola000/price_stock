<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Import\ColumnRef;
use App\Import\Fetcher;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Db;
use App\YmlExport\FeedBuilder;
use App\YmlExport\FeedRepository;
use App\YmlExport\Matcher;
use App\YmlExport\Schema;
use App\YmlExport\SettingsRepository;
use App\YmlExport\StockMapRepository;
use App\YmlExport\UploadImporter;
use App\YmlExport\UploadRepository;
use App\YmlExport\WarehouseRepository;
use App\YmlExport\XmlMapping;
use App\YmlExport\XmlTemplate;
use App\YmlExport\OzonSettings;
use App\YmlExport\OzonClient;
use App\YmlExport\OzonUpdater;

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
FeedRepository::ensureDefault();

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$tab = (string) ($_GET['tab'] ?? 'warehouses');
Matcher::cleanupOrphans();

/** Пересборка фидов без падения страницы: ошибка уйдёт в лог и flash. */
function rebuildQuiet(): void
{
    try {
        FeedBuilder::buildAll();
    } catch (Throwable $exception) {
        error_log('[admin-yml] сборка фида: ' . $exception->getMessage());
        flash('error', 'Фид не пересобрался: ' . $exception->getMessage());
    }
}

/* ---------- Действия (POST) ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'ozon-save':
        case 'ozon-load':
            try {
                $ozon=OzonSettings::save($_POST);
                unset($_SESSION['ozon_plan']);
                if($action==='ozon-load') {
                    $warehouses=(new OzonClient($ozon['client_id'],$ozon['api_key']))->warehouses();
                    OzonSettings::storeWarehouses($warehouses,OzonSettings::fingerprint($ozon));
                    flash('success','Получено складов Ozon: '.count($warehouses).'. Выберите склад и сохраните настройки.');
                } else flash('success','Настройки Ozon сохранены.');
            }catch(Throwable $exception){flash('error',$exception->getMessage());}
            redirect('yml.php?tab=api');
            break;
        case 'ozon-preview':
            unset($_SESSION['ozon_plan']);
            try {
                $ozon=OzonSettings::get();$rows=OzonUpdater::prepare($ozon);
                $_SESSION['ozon_plan']=['id'=>bin2hex(random_bytes(16)),'created'=>time(),'fingerprint'=>OzonSettings::fingerprint($ozon),'hash'=>OzonUpdater::hash($rows),'rows'=>$rows];
            }catch(Throwable $exception){flash('error',$exception->getMessage());}
            redirect('yml.php?tab=api');
            break;
        case 'ozon-send':
            $lock=null;
            try {
                $plan=$_SESSION['ozon_plan']??null;
                if(!is_array($plan)||!hash_equals($plan['id'],(string)($_POST['plan_id']??''))||time()-$plan['created']>600)throw new RuntimeException('Предпросмотр устарел. Подготовьте обновление заново.');
                $ozon=OzonSettings::get();$rows=OzonUpdater::prepare($ozon);
                if(!hash_equals($plan['fingerprint'],OzonSettings::fingerprint($ozon))||!hash_equals($plan['hash'],OzonUpdater::hash($rows)))throw new RuntimeException('Настройки или остатки изменились. Подготовьте новый предпросмотр.');
                $lock=fopen(APP_STORAGE.'/ozon-api.lock','c');
                if($lock===false||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Другая отправка в Ozon уже выполняется.');
                unset($_SESSION['ozon_plan']);
                @set_time_limit(0);
                $result=OzonUpdater::send(new OzonClient($ozon['client_id'],$ozon['api_key']),$rows);
                $result['warehouse_id']=(int)$ozon['warehouse_id'];$result['warehouse_name']=$ozon['warehouse_name'];$result['client_id']=$ozon['client_id'];
                try {OzonSettings::write('ozon-api-result.json',$result);}catch(Throwable $exception){flash('warning','Ответ Ozon получен, но журнал не удалось сохранить. Проверьте итоговые счётчики и остатки Ozon перед повторной отправкой.');}
                flash($result['failed']+$result['not_sent']===0?'success':'warning','Ozon: подтверждено '.$result['success'].', не подтверждено '.$result['failed'].', не отправлено '.$result['not_sent'].'.');
            }catch(Throwable $exception){flash('error',$exception->getMessage());}
            finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
            redirect('yml.php?tab=api');
            break;

        case 'warehouse-save':
            handleUploadSave(true);
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
            redirect('yml.php?' . http_build_query([
                'tab'=>'matches', 'sub'=>(($_POST['return_sub'] ?? '') === 'items' ? 'items' : 'queue'),
                'list_q'=>(string) ($_POST['list_q'] ?? ''), 'list_field'=>(string) ($_POST['list_field'] ?? 'all'),
            ]));
            break;

        case 'match-clear':
            $count = Matcher::clearConfirmed();
            rebuildQuiet();
            flash('success', 'Снято подтверждений: ' . $count . '. Позиции возвращены в очередь.');
            redirect('yml.php?tab=matches&sub=items');
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

        case 'feed-save':
            $feedId = (int) ($_POST['id'] ?? 0);
            $feedName = trim((string) ($_POST['name'] ?? ''));
            if ($feedName === '') {
                flash('error', 'Укажите название ссылки.');
                redirect('yml.php?tab=feed&feed_form=' . ($feedId > 0 ? $feedId : 'new'));
            }
            $feedWarehouseIds = array_map('intval', (array) ($_POST['warehouse_ids'] ?? []));
            $feedMode=($_POST['warehouse_mode']??'selected')==='all'?'all':'selected';
            if($feedMode==='selected'&&$feedWarehouseIds===[]) {
                flash('error','Выберите хотя бы один склад или режим «Все активные склады».');
                redirect('yml.php?tab=feed&feed_form='.($feedId>0?$feedId:'new'));
            }
            if ($feedId > 0 && FeedRepository::find($feedId) !== null) {
                FeedRepository::update($feedId, $feedName, $feedWarehouseIds, $feedMode);
                flash('success', 'Ссылка «' . $feedName . '» сохранена.');
            } else {
                FeedRepository::create($feedName, $feedWarehouseIds, $feedMode);
                flash('success', 'Ссылка «' . $feedName . '» создана — фид по ней уже доступен.');
            }
            rebuildQuiet();
            redirect('yml.php?tab=feed');
            break;

        case 'feed-token':
            $feedId = (int) ($_POST['id'] ?? 0);
            if (FeedRepository::find($feedId) !== null) {
                FeedRepository::regenerateToken($feedId);
                rebuildQuiet();
                flash('success', 'Токен перевыпущен. Старая ссылка на этот фид больше не работает.');
            }
            redirect('yml.php?tab=feed');
            break;

        case 'feed-delete':
            FeedRepository::delete((int) ($_POST['id'] ?? 0));
            flash('success', 'Ссылка удалена.');
            redirect('yml.php?tab=feed');
            break;

        case 'settings-save':
            handleSettingsSave();
            break;

        case 'feed-build':
            $builtOffers=0;
            foreach(FeedRepository::list() as $feed) {
                try {
                    $built=FeedBuilder::buildFeed($feed);$builtOffers+=$built['offers'];
                    if($built['diagnostic']!==null)flash('warning','Фид «'.$feed['name'].'»: '.$built['diagnostic']['message']);
                }catch(Throwable $exception){flash('error','Фид «'.$feed['name'].'»: '.$exception->getMessage());}
            }
            flash('success', 'Сборка завершена. Товаров в фидах: '.$builtOffers.'.');
            redirect('yml.php?tab=feed');
            break;
    }
}

/* ---------- Обработчики ---------- */

function handleUploadSave(bool $combined = false): void
{
    $warehouseId = (int) ($_POST[$combined ? 'id' : 'warehouse_id'] ?? 0);
    $warehouse = $warehouseId > 0 ? WarehouseRepository::find($warehouseId) : null;
    if ((!$combined || $warehouseId > 0) && $warehouse === null) {
        flash('error', 'Склад не найден.');
        redirect('yml.php?tab=warehouses');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));
    $sort = (int) ($_POST['sort'] ?? 0);
    $kind = (string) ($_POST['kind'] ?? 'file') === 'virtual' ? 'virtual' : 'file';
    $defaultStock = max(0, (int) ($_POST['default_stock'] ?? 0));
    $returnUrl = 'yml.php?tab=warehouses&wh_form=' . ($warehouseId > 0 ? $warehouseId : 'new');
    if ($combined) {
        // Restore all entered settings on validation errors, without retaining a file upload.
        $_SESSION['yml_warehouse_form'] = $_POST;
        unset($_SESSION['yml_warehouse_form']['csrf_token']);
        if ($name === '') {
            flash('error', 'Укажите название склада.');
            redirect($returnUrl);
        }
        if ($kind === 'virtual') {
            if ($warehouseId > 0) {
                WarehouseRepository::update($warehouseId, $name, $code, $sort, $kind, $defaultStock);
            } else {
                $warehouseId = WarehouseRepository::create($name, $code, $sort, $kind, $defaultStock);
            }
            // Keep any existing file and matches when switching to virtual and back.
            unset($_SESSION['yml_warehouse_form']);
            rebuildQuiet();
            flash('success', 'Виртуальный склад сохранён.');
            redirect('yml.php?tab=warehouses');
        }
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
        redirect($combined ? $returnUrl : 'yml.php?tab=warehouses&upload_form=' . $warehouseId);
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

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        if ($combined) {
            if ($warehouseId > 0) {
                WarehouseRepository::update($warehouseId, $name, $code, $sort, $kind, $defaultStock);
            } else {
                $warehouseId = WarehouseRepository::create($name, $code, $sort, $kind, $defaultStock);
            }
            $data['warehouse_id'] = $warehouseId;
        }
        if ($existing === null) {
            $data['file_path'] = $uploadedName;
            $data['original_filename'] = $uploadedName !== null ? (string) $_FILES['price_file']['name'] : null;
            $id = UploadRepository::create($data);
        } else {
            $id = (int) $existing['id'];
            UploadRepository::update($id, $data);
            if ($uploadedName !== null) {
                UploadRepository::setFile($id, $uploadedName, (string) $_FILES['price_file']['name']);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($uploadedName !== null) @unlink(APP_STORAGE . '/uploads/' . basename($uploadedName));
        throw $exception;
    }
    if ($uploadedName !== null && !empty($existing['file_path'])) {
        $old = APP_STORAGE . '/uploads/' . basename((string) $existing['file_path']);
        if (is_file($old)) @unlink($old);
    }
    unset($_SESSION['yml_warehouse_form']);
    if ($combined && !isset($_POST['save_and_import'])) rebuildQuiet();

    if (isset($_POST['save_and_preview'])) {
        redirect('yml.php?tab=warehouses&wh_form=' . $warehouseId . '&preview=' . $id);
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

    flash('success', $combined ? 'Склад и настройки файла сохранены.' : 'Настройки файла склада сохранены.');
    redirect('yml.php?tab=warehouses');
}

function handleSettingsSave(): void
{
    $settings = SettingsRepository::get();

    $rules=$settings['offer_mapping_array'];
    $outlets=$settings['outlets_array'];
    $variables=[];$errors=[];
    foreach((array)($_POST['var_name']??[])as $i=>$name) {
        $type=(string)($_POST['var_type'][$i]??'constant');
        $variables[]=['name'=>trim((string)$name),'source'=>XmlTemplate::sourceFromForm($type,trim((string)($_POST['var_ref'][$i]??'')),(string)($_POST['var_extra'][$i]??''))];
    }
    $template=['text'=>(string)($_POST['xml_template']??''),'variables'=>$variables];
    try {XmlTemplate::compile($template['text'],$variables);}catch(Throwable $exception){$errors[]=$exception->getMessage();}
    $outlets['template']=$template;
    $numericEnabled=isset($_POST['numeric_stock_enabled']);
    $numericThreshold=trim((string)($_POST['numeric_stock_threshold']??'5'));
    $numericValue=trim((string)($_POST['numeric_stock_value']??'10'));
    foreach([$numericThreshold,$numericValue] as $number) {
        if($numericEnabled&&(!preg_match('/^[0-9]+$/',$number)||(float)$number>2147483647)) {
            $errors[]='Порог и число замены должны быть целыми числами от 0 до 2147483647.';
            break;
        }
    }
    $outlets['numeric_stock_rule']=['enabled'=>$numericEnabled,'threshold'=>$numericThreshold,'value'=>$numericValue];
    $lowerEnabled=isset($_POST['numeric_stock_lower_enabled']);
    $lowerThreshold=trim((string)($_POST['numeric_stock_lower_threshold']??'4'));
    $lowerValue=trim((string)($_POST['numeric_stock_lower_value']??'0'));
    foreach([$lowerThreshold,$lowerValue] as $number) {
        if($lowerEnabled&&(!preg_match('/^[0-9]+$/',$number)||(float)$number>2147483647)) {
            $errors[]='Порог и число замены должны быть целыми числами от 0 до 2147483647.';
            break;
        }
    }
    if($numericEnabled&&$lowerEnabled&&(float)$lowerThreshold>=(float)$numericThreshold) {
        $errors[]='Диапазоны пересекаются: порог «≤» должен быть меньше порога «≥».';
    }
    $outlets['numeric_stock_rule']+=['lower_enabled'=>$lowerEnabled,'lower_threshold'=>$lowerThreshold,'lower_value'=>$lowerValue];


    $draft = array_merge($settings, [
        'shop_name'=>trim((string)($_POST['shop_name']??'')),
        'shop_company'=>trim((string)($_POST['shop_company']??'')),
        'shop_url'=>trim((string)($_POST['shop_url']??'')),
        'currency'=>trim((string)($_POST['currency']??'')),
        'offer_mapping_array'=>$rules, 'outlets_array'=>$outlets, 'template_config'=>$template,
        'only_confirmed'=>isset($_POST['only_confirmed'])?1:0,
        'skip_zero_stock'=>isset($_POST['skip_zero_stock'])?1:0,
        'oldprice_only_higher'=>isset($_POST['oldprice_only_higher'])?1:0,
        'stock_fallback'=>max(0,(int)($_POST['stock_fallback']??0)),
    ]);

    // Соответствие текстовых остатков числам, например «Более 5» -> 10.
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
    $isCheck=isset($_POST['check_template']);
    if($errors===[]) {
        try {
            $feeds=FeedRepository::list();
            $selected=null;
            foreach($feeds as $feed)if((int)$feed['id']===(int)($_POST['preview_feed_id']??0))$selected=$feed;
            $selected=$selected??($feeds[0]??null);
            if($selected!==null) {
                $previewSettings=$draft;
                if($isCheck)$previewSettings['preview_product_id']=max(0,(int)($_POST['preview_product_id']??0));
                $previewMap=array_map(static fn($row)=>['pattern'=>trim((string)preg_replace('/\s+/u',' ',str_replace('ё','е',mb_strtolower($row['pattern'],'UTF-8')))),'qty'=>$row['qty']],$stockRows);
                $preview=FeedBuilder::buildFeed($selected,$previewSettings,$previewMap,true);
                try {$truncated=filesize($preview['path'])>16000;$xml=(string)file_get_contents($preview['path'],false,null,0,16000);}finally{@unlink($preview['path']);}
                $_SESSION['yml_template_preview']=['xml'=>$xml,'truncated'=>$truncated,'diagnostic'=>$preview['diagnostic'],'offers'=>$preview['offers'],'feed'=>(int)$selected['id'],'product'=>(int)($_POST['preview_product_id']??0)];
            } else $_SESSION['yml_template_preview']=['xml'=>'','offers'=>0,'feed'=>0,'product'=>0];
        }catch(Throwable $exception){$errors[]=$exception->getMessage();}
    }
    if ($errors !== []) {
        $_SESSION['yml_settings_draft']=['settings'=>$draft,'stocks'=>$stockRows];
        foreach ($errors as $error) flash('error',$error);
        flash('error','Настройки не сохранены. Исправьте ошибки; действующий фид не изменён.');
        redirect('yml.php?tab=settings');
    }
    if($isCheck) {
        $_SESSION['yml_settings_draft']=['settings'=>$draft,'stocks'=>$stockRows];
        flash('success','Шаблон корректен. Предпросмотр обновлён; настройки и действующие фиды не изменены.');
        redirect('yml.php?tab=settings');
    }
    $outlets['numeric_stock_rule']['threshold']=max(0,(int)$numericThreshold);
    $outlets['numeric_stock_rule']['value']=max(0,(int)$numericValue);
    $outlets['numeric_stock_rule']['lower_threshold']=max(0,(int)$lowerThreshold);
    $outlets['numeric_stock_rule']['lower_value']=max(0,(int)$lowerValue);
    $pdo=Db::pdo();
    $pdo->beginTransaction();
    try {
        SettingsRepository::save($draft['shop_name'],$draft['shop_company'],$draft['shop_url'],$draft['currency'],
            $rules,$outlets,(bool)$draft['only_confirmed'],(bool)$draft['skip_zero_stock'],(bool)$draft['oldprice_only_higher'],$draft['stock_fallback']);
        StockMapRepository::replaceAll($stockRows);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    rebuildQuiet();
    flash('success','Настройки фида сохранены. Предпросмотр показывает сохранённый XML.');
    redirect('yml.php?tab=settings' . (isset($_POST['add_stock_row']) ? '&add_stock=1' : ''));

}

/* ---------- Данные для страниц ---------- */

$flash = flash();
$warehouses = WarehouseRepository::list();
$uploads = UploadRepository::list();
$matchStats = Matcher::stats();
$settings = SettingsRepository::get();
$stockMapRows = StockMapRepository::list();
if ($tab === 'settings' && isset($_SESSION['yml_settings_draft'])) {
    $settings=$_SESSION['yml_settings_draft']['settings'];
    $stockMapRows=$_SESSION['yml_settings_draft']['stocks'];
    unset($_SESSION['yml_settings_draft']);
}
$templatePreview=$tab==='settings'?($_SESSION['yml_template_preview']??null):null;
if($tab==='settings')unset($_SESSION['yml_template_preview']);
if ($tab === 'settings' && isset($_GET['add_stock'])) {
    $stockMapRows[] = ['pattern' => '', 'qty' => 0]; // пустая строка по кнопке «+ Добавить соответствие»
}

$matchesSub = (string) ($_GET['sub'] ?? 'queue') === 'items' ? 'items' : 'queue';
$listQuery = trim((string) ($_GET['list_q'] ?? ''));
$searchField = static fn(string $value): string => in_array($value, ['sku','name'], true) ? $value : 'all';
$listField = $searchField((string) ($_GET['list_field'] ?? 'all'));
$queue = ($tab === 'matches' && $matchesSub === 'queue') ? Matcher::queue(300, $listQuery, $listField) : [];
$confirmedRows = ($tab === 'matches' && $matchesSub === 'items') ? Matcher::confirmedList(500, $listQuery, $listField) : [];
$manualMatch = $tab === 'matches' && isset($_GET['match_id']) ? Matcher::find((int) $_GET['match_id']) : null;
$candidateQuery = trim((string) ($_GET['q'] ?? ''));
$candidateField = $searchField((string) ($_GET['field'] ?? 'all'));
$candidates = $manualMatch !== null ? Matcher::candidates($candidateQuery, 50, $candidateField) : [];

$previewRows = null;
$previewUpload = null;
if ($tab === 'warehouses' && isset($_GET['preview'])) {
    $previewUpload = UploadRepository::find((int) $_GET['preview']);
    if ($previewUpload !== null) {
        $previewWarehouse = WarehouseRepository::find((int) $previewUpload['warehouse_id']);
        $previewUpload['warehouse_name'] = (string) ($previewWarehouse['name'] ?? '');
        try {
            $previewRows = UploadImporter::preview($previewUpload);
        } catch (Throwable $exception) {
            flash('error', 'Не удалось прочитать файл: ' . $exception->getMessage());
            $flash = array_merge($flash, flash());
        }
    }
}

$uploadFormWarehouse = isset($_GET['upload_form']) ? (int) $_GET['upload_form'] : 0;
$warehouseFormId = isset($_GET['wh_form']) ? (int) $_GET['wh_form'] : ($uploadFormWarehouse > 0 ? $uploadFormWarehouse : null);
$formOld = $_SESSION['yml_warehouse_form'] ?? null;
unset($_SESSION['yml_warehouse_form']);
if ($warehouseFormId !== null && $warehouseFormId > 0 && WarehouseRepository::find($warehouseFormId) === null) {
    $warehouseFormId = null;
    flash('error', 'Склад не найден.');
}
$uploadsByWarehouse = array_column($uploads, null, 'warehouse_id');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'example.com');
$base = rtrim(dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/yml.php'))), '/');
$feedBaseUrl = $scheme . '://' . $host . $base . '/feed/yml.php?token=';

$feeds = FeedRepository::list();

// Форма ссылки: ?feed_form=new — новая, ?feed_form=<id> — редактирование.
$feedFormId = null;
if (isset($_GET['feed_form'])) {
    $feedFormId = (string) $_GET['feed_form'] === 'new' ? 0 : (int) $_GET['feed_form'];
}
$feedEdit = null;
if ($feedFormId !== null && $feedFormId > 0) {
    $feedEdit = FeedRepository::find($feedFormId);
    if ($feedEdit === null) {
        $feedFormId = null;
    }
}

// Предпросмотр: выбранная ссылка (?preview_feed=<id>) или первая в списке.
$previewFeedId = isset($_GET['preview_feed']) ? (int) $_GET['preview_feed'] : (int) ($feeds[0]['id'] ?? 0);
$previewFeed = null;
foreach ($feeds as $feedRow) {
    if ((int) $feedRow['id'] === $previewFeedId) {
        $previewFeed = $feedRow;
        break;
    }
}
$feedPreview = '';
$feedFile = $previewFeed !== null ? FeedBuilder::path((string) $previewFeed['token']) : null;
if ($feedFile !== null && is_file($feedFile)) {
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

$sourceTypeLabels = ['file'=>'Файл', 'catalog'=>'Витрина', 'constant'=>'Константа'];
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
      <a href="index.php?page=settings">Настройки</a>
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
    <a href="yml.php?tab=api" class="<?= $tab === 'api' ? 'is-active' : '' ?>">Обновление по API</a>
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
        <tr><th>Склад</th><th>Код</th><th>Файл / ссылка</th><th class="num">Позиций</th><th class="num">Сопоставлено</th><th>Обновлён</th><th>Статус</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($warehouses === []): ?>
        <tr><td colspan="8" class="muted">Складов пока нет — создайте первый.</td></tr>
      <?php endif; ?>
      <?php foreach ($warehouses as $wh): ?>
        <?php $isVirtual = (string) ($wh['kind'] ?? 'file') === 'virtual'; $up = $uploadsByWarehouse[(int) $wh['id']] ?? null; ?>
        <tr>
          <td>
            <b><?= e((string) $wh['name']) ?></b>
            <?php if ($isVirtual): ?>
              <span class="pill pill--running">виртуальный · <?= (int) ($wh['default_stock'] ?? 0) ?></span>
            <?php endif; ?>
          </td>
          <td class="mono small"><?= e((string) $wh['code']) ?></td>
          <td class="small warehouse-file"><?= $isVirtual ? '—' : e($up === null ? 'Не настроен' : (string) ($up['fetch_method'] === 'url' ? $up['source_url'] : ($up['original_filename'] ?? '—'))) ?></td>
          <td class="num"><?= $isVirtual ? '—' : (int) $wh['items_count'] ?></td>
          <td class="num"><?= $isVirtual ? '—' : (int) $wh['confirmed_count'] ?></td>
          <td class="small"><?= $isVirtual || $up === null ? '—' : e(format_datetime($up['last_run_at'] ?? null)) ?></td>
          <td>
            <?php if (!$isVirtual && $up !== null): ?>
              <span class="pill <?= $up['last_status'] === 'error' ? 'pill--error' : ($up['last_status'] === 'ok' ? 'pill--ok' : 'pill--never') ?>"><?= e(['ok'=>'OK', 'error'=>'Ошибка', 'running'=>'Импорт…', 'never'=>'Ещё не загружался'][$up['last_status']] ?? $up['last_status']) ?></span>
              <?php if (!empty($up['last_error'])): ?><div class="small error-text"><?= e(mb_substr((string) $up['last_error'], 0, 200)) ?></div><?php endif; ?>
            <?php endif; ?>
            <?php if ((int) $wh['is_active'] === 1): ?>
              <span class="pill pill--ok">активен</span>
            <?php else: ?>
              <span class="pill pill--disabled">отключён</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <a class="btn btn--small btn--ghost" href="yml.php?tab=warehouses&wh_form=<?= (int) $wh['id'] ?>">Изменить</a>
            <?php if (!$isVirtual && $up !== null): ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?><input type="hidden" name="action" value="upload-import"><input type="hidden" name="id" value="<?= (int) $up['id'] ?>">
                <button class="btn btn--small btn--primary">Загрузить</button>
              </form>
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
      $whEdit = $warehouseFormId > 0 ? WarehouseRepository::find($warehouseFormId) : null;
      $upEdit = $warehouseFormId > 0 ? UploadRepository::findByWarehouse($warehouseFormId) : null;
      if (is_array($formOld) && (int) ($formOld['id'] ?? -1) === $warehouseFormId) {
          $whEdit = array_merge($whEdit ?? [], $formOld);
          $upEdit = array_merge($upEdit ?? [], array_intersect_key($formOld, array_flip(['type', 'fetch_method', 'source_url', 'csv_delimiter', 'csv_encoding', 'skip_rows', 'sheet_index', 'skip_hidden'])));
          $upEdit['skip_hidden'] = isset($formOld['skip_hidden']) ? 1 : 0;
      }
      $upMapping = is_array($formOld) && (int) ($formOld['id'] ?? -1) === $warehouseFormId
          ? (array) ($formOld['mapping'] ?? []) : ($upEdit !== null ? UploadRepository::mappingOf($upEdit) : []);
  ?>
    <div class="card">
      <h2 class="card__title"><?= $warehouseFormId === 0 ? 'Новый склад' : 'Склад: ' . e((string) $whEdit['name']) ?></h2>
      <form method="post" enctype="multipart/form-data" id="warehouse-settings">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="warehouse-save">
        <input type="hidden" name="id" value="<?= $warehouseFormId ?>">
        <h3 class="a-subtitle">Параметры склада</h3>
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
        <fieldset class="warehouse-file-settings">
          <legend>Файл остатков</legend>
          <p class="card__hint">Excel, CSV или YML — файлом или по ссылке (Google Таблицы, Яндекс Диск).</p>
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
        </fieldset>
        <div class="form__actions">
          <button class="btn btn--primary warehouse-import-button" name="save_and_import" value="1">Сохранить и загрузить</button>
          <button class="btn btn--ghost">Сохранить</button>
          <?php if ($upEdit !== null && !empty($upEdit['id'])): ?>
            <button class="btn btn--ghost warehouse-import-button" name="save_and_preview" value="1">Проверить маппинг</button>
          <?php endif; ?>
          <a class="btn btn--link" href="yml.php?tab=warehouses">Отмена</a>
        </div>
      </form>
      <?php if ($upEdit !== null && !empty($upEdit['id'])): ?>
      <form method="post" class="form__actions warehouse-file-delete" onsubmit="return confirm('Удалить только файл и его позиции? Сам склад сохранится.')">
        <?= Csrf::field() ?><input type="hidden" name="action" value="upload-delete"><input type="hidden" name="id" value="<?= (int) $upEdit['id'] ?>">
        <button class="btn btn--small btn--danger">Удалить файл и позиции</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($previewUpload !== null && $previewRows !== null): ?>
    <div class="card table-wrap">
      <h2 class="card__title" style="padding:18px 18px 0">Проверка маппинга: <?= e((string) ($previewUpload['warehouse_name'] ?? '')) ?></h2>
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

  <div class="toolbar">
    <form method="get" class="form__actions match-search">
      <input type="hidden" name="tab" value="matches"><input type="hidden" name="sub" value="<?= e($matchesSub) ?>">
      <input class="input" name="list_q" value="<?= e($listQuery) ?>" placeholder="Артикул или название" aria-label="Поиск сопоставлений">
      <select class="input match-search-field" name="list_field" aria-label="Искать в">
        <?php foreach (['all'=>'По всем полям','sku'=>'По артикулу','name'=>'По названию'] as $key=>$label): ?>
          <option value="<?= e($key) ?>"<?= sel($key,$listField) ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn--primary">Найти</button>
      <?php if ($listQuery !== ''): ?><a class="btn btn--ghost" href="?tab=matches&sub=<?= e($matchesSub) ?>">Сбросить</a><?php endif; ?>
    </form>
    <button type="button" class="btn btn--danger" data-open-dialog="match-clear"<?= $matchStats['confirmed'] === 0 ? ' disabled' : '' ?>>Очистить список товаров</button>
  </div>
  <dialog class="match-dialog match-dialog--small" id="match-clear" aria-labelledby="match-clear-title">
    <h2 class="card__title" id="match-clear-title">Очистить список товаров?</h2>
    <p>Все подтверждённые сопоставления будут сняты, включая скрытые поиском. Позиции вернутся в очередь «К сопоставлению».</p>
    <p class="muted">Файлы складов и товары каталога сохранятся. Эти позиции не попадут в фиды до повторного подтверждения.</p>
    <form method="post" class="form__actions">
      <?= Csrf::field() ?><input type="hidden" name="action" value="match-clear">
      <button type="button" class="btn btn--ghost" data-close-dialog>Отмена</button>
      <button class="btn btn--danger">Очистить список</button>
    </form>
  </dialog>
  <?php if ($manualMatch !== null): ?>
    <dialog class="match-dialog" id="match-picker" aria-labelledby="match-picker-title">
      <div class="match-dialog__header"><h2 class="card__title" id="match-picker-title">Выбрать другой товар</h2><button type="button" class="btn btn--ghost" data-close-dialog aria-label="Закрыть">×</button></div>
      <p class="card__hint">
        Позиция: <b><?= e((string) ($manualMatch['item_name'] ?? $manualMatch['item_sku'])) ?></b>
        <span class="mono small muted"><?= e((string) $manualMatch['item_sku']) ?></span>
        · склад «<?= e((string) $manualMatch['warehouse_name']) ?>»
      </p>
      <form method="get" class="form__actions" style="margin-bottom:14px">
        <input type="hidden" name="tab" value="matches">
        <input type="hidden" name="sub" value="<?= e($matchesSub) ?>">
        <input type="hidden" name="match_id" value="<?= (int) $manualMatch['id'] ?>">
        <input type="hidden" name="list_q" value="<?= e($listQuery) ?>">
        <input type="hidden" name="list_field" value="<?= e($listField) ?>">
        <input class="input" style="max-width:320px" name="q" value="<?= e((string) ($_GET['q'] ?? '')) ?>" placeholder="Поиск по названию или артикулу каталога">
        <select class="input match-search-field" name="field" aria-label="Искать в">
          <?php foreach (['all'=>'По всем полям','sku'=>'По артикулу','name'=>'По названию'] as $key=>$label): ?>
            <option value="<?= e($key) ?>"<?= sel($key,$candidateField) ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--primary">Найти</button>
        <button class="btn btn--ghost" type="button" data-close-dialog>Отмена</button>
      </form>
      <?php if (trim((string) ($_GET['q'] ?? '')) !== ''): ?>
        <?php if ($candidates === []): ?>
          <p class="muted">Ничего не найдено — попробуйте другой запрос.</p>
        <?php else: ?>
          <div class="table-wrap"><table class="table table--compact"><thead><tr><th>Наименование / артикул</th><th>Поставщик</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($candidates as $candidate): ?>
              <tr>
                <td><b><?= e((string) $candidate['name']) ?></b><br><span class="mono small muted"><?= e((string) $candidate['sku']) ?></span></td>
                <td><?= e((string) $candidate['supplier_name']) ?></td>
                <td class="row-actions">
                  <form method="post" class="inline">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="match-confirm">
                    <input type="hidden" name="match_id" value="<?= (int) $manualMatch['id'] ?>">
        <input type="hidden" name="list_q" value="<?= e($listQuery) ?>">
        <input type="hidden" name="list_field" value="<?= e($listField) ?>">
                    <input type="hidden" name="product_id" value="<?= (int) $candidate['id'] ?>">
                    <input type="hidden" name="return_sub" value="<?= e($matchesSub) ?>">
                    <input type="hidden" name="list_q" value="<?= e($listQuery) ?>">
                    <input type="hidden" name="list_field" value="<?= e($listField) ?>">
                    <button class="btn btn--small btn--primary">Выбрать</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      <?php endif; ?>
    </dialog>
  <?php endif; ?>

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

  <div class="card table-wrap">
    <table class="table">
      <thead>
        <tr><th>Моя позиция</th><th></th><th>Кандидат из каталога</th><th>Совпадение</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($queue === []): ?>
        <tr><td colspan="5" class="muted"><?= $listQuery !== '' ? 'Ничего не найдено — измените запрос.' : 'Очередь пуста: все позиции сопоставлены и подтверждены.' ?></td></tr>
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
            <a class="btn btn--small btn--ghost" href="yml.php?tab=matches&match_id=<?= (int) $row['id'] ?>&list_q=<?= e(rawurlencode($listQuery)) ?>&list_field=<?= e($listField) ?>"><?= $row['product_id'] !== null ? 'Другой' : 'Сопоставить' ?></a>
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
            <a class="btn btn--small btn--ghost" href="yml.php?tab=matches&sub=items&match_id=<?= (int) $row['id'] ?>&list_q=<?= e(rawurlencode($listQuery)) ?>&list_field=<?= e($listField) ?>">Другой</a>
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
  <form method="post" id="yml-template-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="settings-save">

    <?php foreach(['shop_name','shop_company','shop_url','currency'] as $field): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e((string)$settings[$field]) ?>"><?php endforeach; ?>
    <div class="card">
      <div class="toolbar"><h2 class="card__title">Значения для шаблона</h2><button type="button" class="btn btn--primary" id="template-add-variable">+ Добавить значение</button></div>
      <p class="card__hint">Задайте переменные и вставляйте их в структуру XML.</p>
      <div class="table-wrap"><table class="table table--compact" id="template-variables"><thead><tr><th>Значение / переменная</th><th>Источник</th><th>Поле / константа</th><th></th></tr></thead><tbody>
        <?php foreach($settings['template_config']['variables'] as $i=>$variable) {require APP_ROOT.'/app/Views/admin/template_variable.php';} ?>
      </tbody></table></div>
      <p class="field__hint">Источники: Файл, Витрина, Константа. «Текущий склад» доступен только в повторении складов. Системная переменная {{дата_фида}} подставляет дату сборки.</p>
      <template id="template-variable-row"><?php $i='__INDEX__';$variable=['name'=>'','source'=>['type'=>'constant','ref'=>'']];require APP_ROOT.'/app/Views/admin/template_variable.php'; ?></template>
    </div>
    <div class="card">
      <h2 class="card__title">Структура YML</h2>
      <div class="form__actions template-toolbar">
        <select class="input" id="template-insert-variable" aria-label="Переменная для вставки"><option value="">Выберите переменную</option></select>
        <button type="button" class="btn btn--ghost" id="template-insert">+ Вставить переменную</button>
        <button type="button" class="btn btn--ghost" data-template-loop="warehouse">+ Повторение по складам</button>
        <button type="button" class="btn btn--ghost" data-template-loop="product">+ Повторение товаров</button>
      </div>
      <p class="field__hint">Вставка в место курсора. Выделенный текст можно обернуть повторением. Для каждого товара нужен один блок повторения; склады — внутри него.</p>
      <div class="template-editor">
        <pre class="template-lines" aria-hidden="true"></pre><pre class="template-highlight" aria-hidden="true"></pre>
        <textarea name="xml_template" id="xml-template" spellcheck="false" wrap="off" aria-label="Структура YML"><?= e($settings['template_config']['text']) ?></textarea>
      </div>
      <p class="muted small">Проверка XML и переменных выполняется кнопкой «Проверить шаблон». Данные подставляются с безопасным XML-экранированием.</p>
    </div>

    <div class="card">
      <h2 class="card__title">Остатки для выгрузки</h2>
      <p>Основной источник — <b>витрина, сопоставленный товар</b>. Число передаётся напрямую; текстовый остаток — по таблице соответствий ниже.</p>
      <p class="muted small">Если остаток витрины не определён: остаток из файла склада → соответствия текста файла → запасное значение. Виртуальные склады используют постоянный остаток из своих настроек.</p>
      <p class="muted small">Склады выбираются для каждой ссылки во вкладке «Готовый фид». Блок «Для каждого склада» повторяется для этих складов.</p>
      <?php $numericRule=(array)($settings['outlets_array']['numeric_stock_rule']??[]); ?>
      <h3 class="a-subtitle">Правило для числового остатка с витрины</h3>
      <label class="check-inline"><input type="checkbox" name="numeric_stock_enabled"<?= chk(!empty($numericRule['enabled'])) ?>> Заменять числовой остаток по порогу</label>
      <div class="grid-2">
        <label class="field"><span class="field__label">Если остаток ≥</span><input class="input" type="number" min="0" max="2147483647" step="1" name="numeric_stock_threshold" value="<?= e((string)($numericRule['threshold']??5)) ?>"></label>
        <label class="field"><span class="field__label">Передавать в YML</span><input class="input" type="number" min="0" max="2147483647" step="1" name="numeric_stock_value" value="<?= e((string)($numericRule['value']??10)) ?>"></label>
      </div>
      <label class="check-inline"><input type="checkbox" name="numeric_stock_lower_enabled"<?= chk(!empty($numericRule['lower_enabled'])) ?>> Заменять малый числовой остаток</label>
      <div class="grid-2">
        <label class="field"><span class="field__label">Если остаток ≤</span><input class="input" type="number" min="0" max="2147483647" step="1" name="numeric_stock_lower_threshold" value="<?= e((string)($numericRule['lower_threshold']??4)) ?>"></label>
        <label class="field"><span class="field__label">Передавать в YML</span><input class="input" type="number" min="0" max="2147483647" step="1" name="numeric_stock_lower_value" value="<?= e((string)($numericRule['lower_value']??0)) ?>"></label>
      </div>
      <p class="field__hint">Например: остаток ≥ 5 → передавать 10, остаток ≤ 4 → передавать 0. Диапазоны не должны пересекаться. Значения между диапазонами передаются как есть. Остаток на витрине не меняется; текстовые соответствия, файл склада и виртуальные склады обрабатываются отдельно. Замена применяется до фильтра нулевых остатков.</p>


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
          <tr><th>Остаток на витрине / в файле</th><th style="width:170px">Передавать количество</th><th style="width:90px"></th></tr>
        </thead>
        <tbody>
        <?php if ($stockMapRows === []): ?>
          <tr><td colspan="3" class="muted">Соответствий пока нет — добавьте первое.</td></tr>
        <?php endif; ?>
        <?php foreach ($stockMapRows as $i => $stockRow): ?>
          <tr>
            <td><input class="input" name="stock_pattern[<?= $i ?>]" maxlength="190" value="<?= e((string) $stockRow['pattern']) ?>" placeholder="Более 5"></td>
            <td><input class="input" type="number" min="0" name="stock_qty[<?= $i ?>]" value="<?= (int) $stockRow['qty'] ?>"></td>
            <td><button type="button" class="btn btn--small btn--danger" data-remove-stock aria-label="Удалить соответствие">×</button></td>
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
        <button type="button" class="btn btn--ghost" id="template-add-stock">+ Добавить соответствие</button>
      </div>
    </div>

    <div class="card">
      <h2 class="card__title">Прочее</h2>
      <label class="check-inline"><input type="checkbox" name="only_confirmed"<?= chk((int) $settings['only_confirmed'] === 1) ?>> Включать в фид только подтверждённые сопоставления</label>
      <label class="check-inline"><input type="checkbox" name="skip_zero_stock"<?= chk((int) $settings['skip_zero_stock'] === 1) ?>> Исключать офферы с нулевым суммарным остатком <span class="muted small">(виртуальные склады в сумме не учитываются)</span></label>
      <label class="check-inline"><input type="checkbox" name="oldprice_only_higher"<?= chk((int) $settings['oldprice_only_higher'] === 1) ?>> oldprice выводить, только если больше price</label>
      <div class="form__actions">
        <button class="btn btn--primary">Сохранить настройки</button>
        <button class="btn btn--ghost" name="check_template" value="1">Проверить шаблон</button>
      </div>
    </div>
  </form>

  <div class="card">
    <h2 class="card__title">Предпросмотр готового XML</h2>
    <div class="form__actions">
      <label>Фид <select class="input" name="preview_feed_id" form="yml-template-form"><?php foreach($feeds as $feed): ?><option value="<?= (int)$feed['id'] ?>"<?= sel((int)$feed['id'],(int)($templatePreview['feed']??$previewFeedId)) ?>><?= e($feed['name']) ?></option><?php endforeach; ?></select></label>
      <label>Товар для примера <select class="input" name="preview_product_id" form="yml-template-form"><option value="0">Все товары выбранного фида</option><?php foreach(\App\Models\ProductRepository::search(['per_page'=>96])['items'] as $product): ?><option value="<?= (int)$product['id'] ?>"<?= sel((int)$product['id'],(int)($templatePreview['product']??0)) ?>><?= e($product['sku'].' — '.$product['name']) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn--ghost" name="check_template" value="1" form="yml-template-form">Проверить шаблон</button>
    </div>
    <?php if($templatePreview!==null): ?>
      <p class="<?= $templatePreview['offers']>0?'template-status':'muted' ?>"><?= $templatePreview['offers']>0?'Шаблон корректен · Товаров: '.(int)$templatePreview['offers']:'Шаблон корректен, но товары не попали в выгрузку.' ?></p>
      <?php if(!empty($templatePreview['diagnostic'])): ?>
        <div class="alert alert--warning"><?= e($templatePreview['diagnostic']['message']) ?><br><span class="small">Выбрано складов: <?= (int)$templatePreview['diagnostic']['stats']['selected'] ?> · активных: <?= (int)$templatePreview['diagnostic']['stats']['active'] ?> · позиций: <?= (int)$templatePreview['diagnostic']['stats']['items'] ?> · подтверждено: <?= (int)$templatePreview['diagnostic']['stats']['confirmed'] ?></span></div>
      <?php endif; ?>
      <?php if(!empty($templatePreview['truncated'])): ?><p class="muted small">Показаны первые 16 КБ XML. Для подробного примера выберите один товар.</p><?php endif; ?>
      <pre class="feed-preview"><?= e($templatePreview['xml']) ?></pre>
    <?php else: ?><p class="muted">Нажмите «Проверить шаблон»: предпросмотр использует текущий текст, переменные и соответствия остатков без сохранения настроек.</p><?php endif; ?>
  </div>

<?php elseif ($tab === 'api'): ?>
  <?php require APP_ROOT.'/app/Views/admin/ozon.php'; ?>

<?php else: ?>
  <!-- ================= Готовый фид ================= -->
  <div class="card table-wrap">
    <h2 class="card__title" style="padding:18px 18px 0">Ссылки на фиды</h2>
    <p class="card__hint" style="padding:0 18px">
      Каждая ссылка — отдельный YML со своим набором складов: например, один фид для Маркета,
      другой — для своего сайта. Отдаётся актуальный XML из кэша; фиды пересобираются
      после загрузки склада, подтверждения сопоставлений и сохранения настроек.
    </p>
    <table class="table">
      <thead>
        <tr><th>Название</th><th>Ссылка</th><th class="num">Складов</th><th>Собран</th><th></th></tr>
      </thead>
      <tbody>
      <?php if ($feeds === []): ?>
        <tr><td colspan="5" class="muted">Ссылок пока нет — создайте первую.</td></tr>
      <?php endif; ?>
      <?php foreach ($feeds as $feedRow): ?>
        <tr>
          <td><b><?= e((string) $feedRow['name']) ?></b></td>
          <td><input class="input mono" readonly style="min-width:340px" value="<?= e($feedBaseUrl . $feedRow['token']) ?>" onclick="this.select()"></td>
          <td class="num"><?= count((array) $feedRow['warehouse_ids']) ?><?php if(($feedRow['warehouse_mode']??'selected')==='all'): ?><div class="small muted">Все активные</div><?php endif; ?></td>
          <td class="small">
            <?php if ($feedRow['built_at'] !== null): ?>
              <?= e(format_datetime($feedRow['built_at'])) ?> · офферов: <b><?= (int) $feedRow['built_offers'] ?></b>
            <?php else: ?>
              <span class="muted">ещё не собирался</span>
            <?php endif; ?>
          </td>
          <td class="row-actions">
            <a class="btn btn--small btn--ghost" href="yml.php?tab=feed&feed_form=<?= (int) $feedRow['id'] ?>">Изменить</a>
            <a class="btn btn--small btn--ghost" href="<?= e($feedBaseUrl . $feedRow['token']) ?>" target="_blank" rel="noopener">XML ↗</a>
            <form method="post" class="inline" onsubmit="return confirm('Старая ссылка на этот фид перестанет работать. Перевыпустить токен?')">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="feed-token">
              <input type="hidden" name="id" value="<?= (int) $feedRow['id'] ?>">
              <button class="btn btn--small btn--ghost">Перевыпустить токен</button>
            </form>
            <?php if (count($feeds) > 1): ?>
              <form method="post" class="inline" onsubmit="return confirm('Удалить эту ссылку? Фид по ней перестанет открываться.')">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="feed-delete">
                <input type="hidden" name="id" value="<?= (int) $feedRow['id'] ?>">
                <button class="btn btn--small btn--danger">Удалить</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="form__actions" style="padding:0 18px 18px">
      <a class="btn btn--ghost" href="yml.php?tab=feed&feed_form=new">+ Новая ссылка</a>
      <form method="post" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="feed-build">
        <button class="btn btn--primary">Пересобрать все сейчас</button>
      </form>
    </div>
  </div>

  <?php if ($feedFormId !== null):
      $formWarehouseIds = $feedEdit !== null ? array_map('intval', (array) $feedEdit['warehouse_ids']) : []; ?>
    <div class="card">
      <h2 class="card__title"><?= $feedFormId > 0 ? 'Ссылка: ' . e((string) ($feedEdit['name'] ?? '')) : 'Новая ссылка' ?></h2>
      <p class="card__hint">Отмеченные склады попадут в этот YML: их остатки идут в outlets, значения тегов оффера берутся по приоритету складов. Отключённые склады не попадут в фид независимо от галочки.</p>
      <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="feed-save">
        <input type="hidden" name="id" value="<?= (int) $feedFormId ?>">
        <label class="field" style="max-width:420px"><span class="field__label">Название ссылки</span>
          <input class="input" name="name" required maxlength="190" value="<?= e((string) ($feedEdit['name'] ?? '')) ?>" placeholder="Например: Маркет"></label>
        <label class="field" style="max-width:420px"><span class="field__label">Откуда брать товары и остатки</span><select class="input" name="warehouse_mode"><option value="all"<?= sel('all',$feedEdit['warehouse_mode']??'all') ?>>Все активные склады</option><option value="selected"<?= sel('selected',$feedEdit['warehouse_mode']??'all') ?>>Только выбранные ниже</option></select><span class="field__hint">Товары — подтверждённые сопоставления этих складов из раздела «Товары». В режиме «Все активные» новые склады подключаются автоматически.</span></label>
        <?php if ($warehouses === []): ?>
          <p class="muted">Складов пока нет — создайте их на вкладке «Склады и загрузка».</p>
        <?php endif; ?>
        <?php foreach ($warehouses as $wh): ?>
          <label class="check-inline" style="display:block">
            <input type="checkbox" name="warehouse_ids[]" value="<?= (int) $wh['id'] ?>"<?= chk(in_array((int) $wh['id'], $formWarehouseIds, true)) ?>>
            <b><?= e((string) $wh['name']) ?></b>
            <?php if ((string) ($wh['kind'] ?? 'file') === 'virtual'): ?>
              <span class="pill pill--running">виртуальный · <?= (int) ($wh['default_stock'] ?? 0) ?></span>
            <?php endif; ?>
            <?php if ((int) $wh['is_active'] !== 1): ?>
              <span class="pill pill--disabled">отключён</span>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
        <div class="form__actions">
          <button class="btn btn--primary"><?= $feedFormId > 0 ? 'Сохранить' : 'Создать ссылку' ?></button>
          <a class="btn btn--ghost" href="yml.php?tab=feed">Отмена</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($previewFeed !== null): ?>
    <div class="card">
      <h2 class="card__title">Предпросмотр: <?= e((string) $previewFeed['name']) ?></h2>
      <?php if (count($feeds) > 1): ?>
        <p class="card__hint">
          <?php foreach ($feeds as $feedRow): ?>
            <a href="yml.php?tab=feed&preview_feed=<?= (int) $feedRow['id'] ?>" style="margin-right:12px;<?= (int) $feedRow['id'] === $previewFeedId ? 'font-weight:700' : '' ?>"><?= e((string) $feedRow['name']) ?></a>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
      <?php if ($feedPreview !== ''): ?>
        <pre class="feed-preview"><?= e($feedPreview) ?></pre>
      <?php else: ?>
        <p class="muted">Файл ещё не собран — нажмите «Пересобрать все сейчас».</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

</main>
<script src="<?= e(asset('assets/js/admin.js', '../')) ?>" defer></script>
</body>
</html>
