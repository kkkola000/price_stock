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
use App\YmlExport\OzonScheduler;

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
        . '<title>Ошибка</title><link rel="stylesheet" href="' . e(asset('assets/css/admin.css', '../')) . '"></head><body class="yml-page">'
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
if(!in_array($tab,['warehouses','items','matches','settings','feed','schedule','api'],true))$tab='warehouses';
$warehouseId=in_array($tab,['warehouses','items','matches'],true)?max(0,(int)($_GET['warehouse_id']??$_POST['warehouse_id']??0)):0;
if($tab==='warehouses'&&$warehouseId===0&&isset($_GET['wh_form'])&&ctype_digit((string)$_GET['wh_form']))$warehouseId=(int)$_GET['wh_form'];
$warehouseContext=$warehouseId>0?WarehouseRepository::find($warehouseId):null;
if($warehouseContext!==null)$warehouseContext['items_count']=(int)Db::scalar('SELECT COUNT(*) FROM yx_items WHERE warehouse_id=?',[$warehouseId]);
if($warehouseId>0&&$warehouseContext===null){flash('error','Склад не найден.');redirect('yml.php?tab=warehouses');}
if($tab==='items'&&$warehouseContext===null)redirect('yml.php?tab=warehouses');
if(str_starts_with((string)($_POST['action']??''),'match-')&&$warehouseId>0&&isset($_POST['match_id'])){
    $scopedMatch=Matcher::find((int)$_POST['match_id']);
    if($scopedMatch===null||(int)$scopedMatch['warehouse_id']!==$warehouseId){http_response_code(404);exit('Сопоставление не найдено в выбранном складе.');}
}
function matchReturnUrl(string $sub='queue'):string {
    return 'yml.php?'.http_build_query(['tab'=>'matches','sub'=>$sub,'warehouse_id'=>(int)($GLOBALS['warehouseId']??0),'list_q'=>(string)($_POST['list_q']??''),'list_field'=>(string)($_POST['list_field']??'all')]);
}

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
        case 'cycle-save':
            $cycleLock=fopen(APP_STORAGE.'/yml-cycle.lock','c');
            try {
                if($cycleLock===false||!flock($cycleLock,LOCK_EX|LOCK_NB))throw new RuntimeException('Цикл уже выполняется. Сохраните настройки после его завершения.');
                App\YmlExport\CycleSettings::save($_POST);
                $activeWarehouses=array_map('intval',(array)($_POST['active_warehouses']??[]));
                foreach(WarehouseRepository::list() as $warehouse)WarehouseRepository::setActive((int)$warehouse['id'],in_array((int)$warehouse['id'],$activeWarehouses,true));
                flash('success','Общее расписание раздела YML сохранено.');
            } catch(Throwable $exception){flash('error',$exception->getMessage());}
            finally{if(is_resource($cycleLock)){flock($cycleLock,LOCK_UN);fclose($cycleLock);}}
            redirect('yml.php?tab=schedule');
        case 'cycle-run':
            @set_time_limit(0);
            $cycle=App\YmlExport\CycleScheduler::tick(true);
            flash($cycle['status']==='ok'?'success':'warning','Обновление YML: '.$cycle['status'].'. Файлов: '.$cycle['uploads'].', фидов: '.$cycle['feeds'].'. '.($cycle['message']??''));
            redirect('yml.php?tab=schedule');

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
                $started=time();OzonScheduler::claim($ozon,$started);
                $result=OzonUpdater::send(new OzonClient($ozon['client_id'],$ozon['api_key']),$rows);
                $result['source']='manual';
                try {OzonScheduler::recordResult($ozon,$result,$started);}catch(Throwable $exception){flash('warning','Не удалось сохранить время отправки для расписания. Проверьте права на storage.');}
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
                redirect($warehouseId>0?'yml.php?tab=warehouses&wh_form='.$warehouseId:'yml.php?tab=warehouses');
            }
            $result = UploadImporter::run($upload);
            flash($result['status'] === 'ok' ? 'success' : 'error', $result['message']);
            if ($result['status'] === 'ok') {
                rebuildQuiet();
            }
            redirect($warehouseId>0?'yml.php?tab=warehouses&wh_form='.$warehouseId:'yml.php?tab=warehouses');
            break;

        case 'upload-delete':
            UploadRepository::delete((int) ($_POST['id'] ?? 0));
            flash('success', 'Файл склада и его позиции удалены.');
            rebuildQuiet();
            redirect($warehouseId>0?'yml.php?tab=warehouses&wh_form='.$warehouseId:'yml.php?tab=warehouses');
            break;

        case 'match-confirm':
            $matchId = (int) ($_POST['match_id'] ?? 0);
            $productId = (int) ($_POST['product_id'] ?? 0);
            Matcher::confirm($matchId, $productId > 0 ? $productId : null, $adminId);
            flash('success', 'Сопоставление подтверждено.');
            rebuildQuiet();
            redirect(matchReturnUrl(($_POST['return_sub']??'')==='items'?'items':'queue'));
            break;

        case 'match-clear':
            $count = Matcher::clearConfirmed($warehouseId);
            rebuildQuiet();
            flash('success', 'Снято подтверждений: ' . $count . '. Позиции возвращены в очередь.');
            redirect(matchReturnUrl('items'));
            break;

        case 'match-bulk':
            $minScore = max(Matcher::NAME_THRESHOLD, min(100, (int) ($_POST['min_score'] ?? 80)));
            $count = Matcher::bulkConfirm($minScore, $adminId, $warehouseId);
            flash('success', 'Подтверждено пар: ' . $count . ' (совпадение от ' . $minScore . '%).');
            rebuildQuiet();
            redirect(matchReturnUrl());
            break;

        case 'match-reject':
            Matcher::reject((int) ($_POST['match_id'] ?? 0));
            flash('success', 'Пара отклонена — позиция не попадёт в фид.');
            rebuildQuiet();
            redirect(matchReturnUrl());
            break;

        case 'match-reset':
            Matcher::resetToSuggested((int) ($_POST['match_id'] ?? 0));
            flash('success', 'Подтверждение снято.');
            rebuildQuiet();
            redirect(matchReturnUrl());
            break;

        case 'match-unmatch':
            Matcher::unmatch((int) ($_POST['match_id'] ?? 0));
            flash('success', 'Сопоставление удалено — позиция вернулась в очередь «К сопоставлению».');
            rebuildQuiet();
            redirect(matchReturnUrl('items'));
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
                $feedId=FeedRepository::create($feedName, $feedWarehouseIds, $feedMode);
                flash('success', 'Ссылка «' . $feedName . '» создана — фид по ней уже доступен.');
            }
            rebuildQuiet();
            redirect('yml.php?tab=feed&preview_feed='.$feedId);
            break;

        case 'feed-token':
            $feedId = (int) ($_POST['id'] ?? 0);
            if (FeedRepository::find($feedId) !== null) {
                FeedRepository::regenerateToken($feedId);
                rebuildQuiet();
                flash('success', 'Токен перевыпущен. Старая ссылка на этот фид больше не работает.');
            }
            redirect('yml.php?tab=feed&preview_feed='.$feedId);
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
            $buildFeedId=max(0,(int)($_POST['feed_id']??0));
            foreach(FeedRepository::list() as $feed) {
                if($buildFeedId>0&&(int)$feed['id']!==$buildFeedId)continue;
                try {
                    $built=FeedBuilder::buildFeed($feed);$builtOffers+=$built['offers'];
                    if($built['diagnostic']!==null)flash('warning','Фид «'.$feed['name'].'»: '.$built['diagnostic']['message']);
                }catch(Throwable $exception){flash('error','Фид «'.$feed['name'].'»: '.$exception->getMessage());}
            }
            flash('success', 'Сборка завершена. Товаров в фидах: '.$builtOffers.'.');
            redirect('yml.php?tab=feed'.($buildFeedId>0?'&preview_feed='.$buildFeedId:''));
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
            if(array_key_exists('is_active',$_POST))WarehouseRepository::setActive($warehouseId,(string)$_POST['is_active']==='1');
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

    $mapping['_merge_skip_empty'] = (string) ($mappingInput['_merge_skip_empty'] ?? '1') === '0' ? '0' : '1';

    if (in_array($type, ['excel', 'csv'], true)) {
        foreach (UploadRepository::FIELDS as $field => $label) {
            if ($mapping[$field] === '') {
                continue;
            }
            try {
                ColumnRef::toIndices($mapping[$field]);
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
            if(array_key_exists('is_active',$_POST))WarehouseRepository::setActive($warehouseId,(string)$_POST['is_active']==='1');
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
    $_SESSION['yml_settings_panel']=($_POST['settings_panel']??'')==='stock'?'stock':'structure';
    $settingsReturn='yml.php?tab=settings&preview_feed='.max(0,(int)($_POST['preview_feed_id']??0));
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
        redirect($settingsReturn);
    }
    if($isCheck) {
        $_SESSION['yml_settings_draft']=['settings'=>$draft,'stocks'=>$stockRows];
        flash('success','Шаблон корректен. Предпросмотр обновлён; настройки и действующие фиды не изменены.');
        redirect($settingsReturn);
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
    redirect($settingsReturn . (isset($_POST['add_stock_row']) ? '&add_stock=1' : ''));

}

/* ---------- Данные для страниц ---------- */

$flash = flash();
$warehouses = WarehouseRepository::list();
$uploads = UploadRepository::list();
$matchStats = Matcher::stats($warehouseId);
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
$queue = ($tab === 'matches' && $matchesSub === 'queue') ? Matcher::queue(300, $listQuery, $listField, $warehouseId) : [];
$confirmedRows = ($tab === 'matches' && $matchesSub === 'items') ? Matcher::confirmedList(500, $listQuery, $listField, $warehouseId) : [];
$manualMatch = $tab === 'matches' && isset($_GET['match_id']) ? Matcher::find((int) $_GET['match_id']) : null;
if($manualMatch!==null&&$warehouseId>0&&(int)$manualMatch['warehouse_id']!==$warehouseId)$manualMatch=null;
$candidateQuery = trim((string) ($_GET['q'] ?? ''));
$candidateField = $searchField((string) ($_GET['field'] ?? 'all'));
$candidates = $manualMatch !== null ? Matcher::candidates($candidateQuery, 50, $candidateField) : [];

$previewRows = null;
$previewUpload = null;
if ($tab === 'warehouses' && isset($_GET['preview'])) {
    $previewUpload = UploadRepository::find((int) $_GET['preview']);
    if($previewUpload!==null&&$warehouseId>0&&(int)$previewUpload['warehouse_id']!==$warehouseId)$previewUpload=null;
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
if($tab==='warehouses'&&$warehouseId>0&&$warehouseFormId===null)$warehouseFormId=$warehouseId;
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
$previewFeedId = isset($_GET['preview_feed']) ? (int) $_GET['preview_feed'] : ($feedEdit!==null?(int)$feedEdit['id']:(int)($feeds[0]['id']??0));
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
<body class="yml-page">

<?php $adminSection='yml';require APP_ROOT.'/app/Views/admin/header.php'; ?>

<main class="a-main">
  <h1 class="a-title">Выгрузка YML</h1>

  <nav class="tabs yml-primary-tabs" aria-label="Разделы выгрузки">
    <a href="yml.php?tab=warehouses" class="<?= in_array($tab,['warehouses','items','matches'],true)?'is-active':'' ?>">Источники</a>
    <a href="yml.php?tab=feed" class="<?= in_array($tab,['feed','settings'],true)?'is-active':'' ?>">YML-фиды</a>
    <a href="yml.php?tab=schedule" class="<?= $tab==='schedule'?'is-active':'' ?>">Автообновление</a>
    <a href="yml.php?tab=api" class="<?= $tab==='api'?'is-active':'' ?>">API</a>
  </nav>

  <?php foreach ($flash as $item): ?>
    <div class="alert alert--<?= e((string) $item['type']) ?>"><?= e((string) $item['message']) ?></div>
  <?php endforeach; ?>

<?php
if($warehouseContext!==null)require APP_ROOT.'/app/Views/admin/yml/warehouse_header.php';
if($tab==='warehouses')require APP_ROOT.'/app/Views/admin/yml/'.($warehouseFormId===null?'sources':'warehouse_settings').'.php';
elseif($tab==='items')require APP_ROOT.'/app/Views/admin/yml/items.php';
elseif($tab==='matches')require APP_ROOT.'/app/Views/admin/yml/matches.php';
elseif(in_array($tab,['settings','feed'],true))require APP_ROOT.'/app/Views/admin/yml/feed_workspace.php';
elseif($tab==='schedule')require APP_ROOT.'/app/Views/admin/yml_schedule.php';
elseif($tab==='api')require APP_ROOT.'/app/Views/admin/ozon.php';
?>

</main>
<script src="<?= e(asset('assets/js/admin.js', '../')) ?>" defer></script>
<script src="<?= e(asset('assets/js/yml.js', '../')) ?>" defer></script>
</body>
</html>
