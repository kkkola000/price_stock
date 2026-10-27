<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Support\Db;
use App\YmlExport\{OzonSettings,OzonClient,OzonScheduler,FeedRepository,WarehouseRepository};
function checkSchedule(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$files=['ozon-api.json','ozon-api-result.json','ozon-api-schedule.json'];
foreach($files as $file)if(is_file(APP_STORAGE.'/'.$file))throw new RuntimeException('Run scheduler integration test only in a clean development environment; existing Ozon data preserved.');
$hadLock=is_file(APP_STORAGE.'/ozon-api.lock');$pdo=Db::pdo();$pdo->beginTransaction();
try {
    $wh=WarehouseRepository::create('Schedule test','schedule',0);
    $feed=FeedRepository::create('Schedule test',[$wh]);
    $source=App\Models\SourceRepository::create(['name'=>'Schedule source','type'=>'csv','fetch_method'=>'upload','mapping'=>[]]);
    Db::run('INSERT INTO products(source_id,sku,name,stock_qty) VALUES (?,?,?,?)',[$source,'CAT-SCHEDULE','Schedule item',3]);$product=Db::lastInsertId();
    Db::run('INSERT INTO yx_items(upload_id,warehouse_id,sku,name,import_batch) VALUES (?,?,?,?,?)',[0,$wh,'FILE-SCHEDULE','Schedule item','test']);
    Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,product_id,status) VALUES (?,?,?,'confirmed')",[$wh,'FILE-SCHEDULE',$product]);
    OzonSettings::save(['client_id'=>'123','api_key'=>'schedule-test-not-real','feed_id'=>$feed,'warehouse_id'=>0]);
    OzonSettings::storeWarehouses([['warehouse_id'=>22142605386000,'name'=>'Target warehouse']]);
    OzonSettings::save(['client_id'=>'123','api_key'=>'','feed_id'=>$feed,'warehouse_id'=>22142605386000,'auto_enabled'=>'1','interval_seconds'=>'10']);
    checkSchedule(OzonSettings::get()['warehouse_id']===22142605386000&&OzonSettings::get()['interval_seconds']===10,'Destination and interval persisted');
    $before=file_get_contents(APP_STORAGE.'/ozon-api.json');$rejected=false;
    try{OzonSettings::save(['client_id'=>'123','feed_id'=>$feed,'warehouse_id'=>22142605386000,'interval_seconds'=>'0']);}catch(InvalidArgumentException){$rejected=true;}
    checkSchedule($rejected&&file_get_contents(APP_STORAGE.'/ozon-api.json')===$before,'Invalid interval does not overwrite settings');
    $calls=0;$client=new OzonClient('123','schedule-test-not-real',static function($path,$body)use(&$calls):array {
        $calls++;checkSchedule($body['stocks'][0]['warehouse_id']===22142605386000,'Scheduled request uses selected target');
        return ['status'=>200,'body'=>json_encode(['result'=>array_map(static fn($row)=>$row+['updated'=>true,'errors'=>[]],$body['stocks'])])];
    });
    $first=OzonScheduler::tick($client,1000);checkSchedule($first['status']==='sent'&&$first['next_run']===1010&&$calls===1,'First due run and interval');
    checkSchedule(OzonScheduler::tick($client,1009)['status']==='not_due'&&$calls===1,'No early dispatch');
    checkSchedule(OzonScheduler::tick($client,1010)['status']==='sent'&&$calls===2,'Exact second boundary');
    $lock=fopen(APP_STORAGE.'/ozon-api.lock','c');flock($lock,LOCK_EX);
    try {checkSchedule(OzonScheduler::tick($client,1020)['status']==='busy'&&$calls===2,'Shared lock prevents duplicate dispatch');}finally{flock($lock,LOCK_UN);fclose($lock);}
    OzonScheduler::recordResult(OzonSettings::get(),['failed'=>0,'not_sent'=>0,'errors'=>[],'source'=>'manual'],1020,1022);
    checkSchedule(OzonScheduler::tick($client,1031)['status']==='not_due','Manual sending shifts next scheduled run');
    $failing=new OzonClient('123','schedule-test-not-real',static fn()=>['status'=>429,'body'=>'{"message":"rate limit"}']);
    $failure=OzonScheduler::tick($failing,1032);checkSchedule($failure['status']==='error'&&$failure['next_run']===1092,'API failures back off at least 60 seconds');
    checkSchedule(OzonScheduler::tick($client,1042)['status']==='not_due'&&$calls===2,'Backoff prevents rapid retries');
    checkSchedule(OzonScheduler::tick($client,1092)['status']==='sent'&&$calls===3,'Schedule recovers after backoff');
    $settings=OzonSettings::get();$settings['auto_enabled']=false;OzonSettings::write('ozon-api.json',$settings);
    checkSchedule(OzonScheduler::tick($client,2000)['status']==='disabled'&&$calls===3,'Disabled mode never sends');
    echo "PASS: warehouse/interval persistence, seconds boundary, disabled mode, shared lock, manual/auto coordination, error backoff and recovery\n";
}finally{
    $pdo->rollBack();foreach($files as $file)if(is_file(APP_STORAGE.'/'.$file))unlink(APP_STORAGE.'/'.$file);
    if(!$hadLock&&is_file(APP_STORAGE.'/ozon-api.lock'))unlink(APP_STORAGE.'/ozon-api.lock');
}
