<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Support\Db;
use App\YmlExport\{CycleSettings,CycleScheduler,AutoUpdater,OzonScheduler,Schema,WarehouseRepository,FeedRepository,FeedBuilder};
function checkCycle(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$files=['yml-cycle.json','yml-cycle-state.json','ozon-api.json','ozon-api-result.json','ozon-api-schedule.json'];
foreach($files as $file)if(is_file(APP_STORAGE.'/'.$file))throw new RuntimeException('Use a clean development environment; existing cycle settings preserved.');
if(is_file(APP_STORAGE.'/ozon-api.json'))throw new RuntimeException('Existing API credentials preserved. Test requires no API configuration.');
$hadLock=is_file(APP_STORAGE.'/yml-cycle.lock');$paths=[];
Schema::ensure();$pdo=Db::pdo();$pdo->beginTransaction();
try{
    $wh=WarehouseRepository::create('Cycle active','cycle-active',0);
    $offWh=WarehouseRepository::create('Cycle inactive','cycle-inactive',1);WarehouseRepository::setActive($offWh,false);
    $feed=FeedRepository::create('Cycle active',[$wh,$offWh]);$offFeed=FeedRepository::create('Cycle inactive',[$wh]);
    CycleSettings::save(['enabled'=>'1','interval_seconds'=>'10','active_feeds'=>[$feed]]);
    checkCycle(CycleSettings::feedActive($feed)&&!CycleSettings::feedActive($offFeed),'Per-feed activity');
    $before=file_get_contents(APP_STORAGE.'/yml-cycle.json');
    try{CycleSettings::save(['interval_seconds'=>0]);throw new RuntimeException('Invalid interval accepted');}catch(InvalidArgumentException){}
    checkCycle(file_get_contents(APP_STORAGE.'/yml-cycle.json')===$before,'Invalid interval preserves settings');
    $calls=0;$work=static function()use(&$calls):array{$calls++;return ['uploads'=>2,'failed'=>0,'feeds'=>1,'offers'=>30,'ozon'=>['status'=>'sent']];};
    checkCycle(CycleScheduler::tick(false,$work,1000)['next_run']===1010&&$calls===1,'First full cycle');
    checkCycle(CycleScheduler::tick(false,$work,1009)['status']==='not_due'&&$calls===1,'No early full cycle');
    checkCycle(CycleScheduler::tick(false,$work,1010)['status']==='ok'&&$calls===2,'Seconds boundary');
    $lock=fopen(APP_STORAGE.'/yml-cycle.lock','c');flock($lock,LOCK_EX);
    try{checkCycle(CycleScheduler::tick(false,$work,1020)['status']==='busy'&&$calls===2,'Shared cycle lock');}finally{flock($lock,LOCK_UN);fclose($lock);}
    $bad=static function():array{throw new RuntimeException('Test source failure');};
    checkCycle(CycleScheduler::tick(false,$bad,1020)['next_run']===1080,'Failure pauses entire cycle');
    checkCycle(CycleScheduler::tick(false,$work,1030)['status']==='not_due','Backoff');
    checkCycle(CycleScheduler::tick(false,$work,1080)['status']==='ok','Recovery');
    checkCycle(OzonScheduler::tick()['status']==='managed_by_yml','Standalone API disabled when unified schedule exists');
    CycleSettings::save(['interval_seconds'=>'10','active_feeds'=>[$feed]]);
    checkCycle(CycleScheduler::tick(false,$work,2000)['status']==='disabled','Disabled schedule');
    checkCycle(CycleScheduler::tick(true,$work,2000)['status']==='ok','Manual full cycle when paused');
    // Real assembly filters out inactive feeds. No external API credentials exist.
    $stats=AutoUpdater::execute(true);
    checkCycle($stats['feeds']===1&&$stats['uploads']===0&&$stats['ozon']['status']==='disabled','Real cycle only builds enabled feed');
    $paths[]=FeedBuilder::path(FeedRepository::find($feed)['token']);
    checkCycle(is_file($paths[0])&&!is_file(FeedBuilder::path(FeedRepository::find($offFeed)['token'])),'Inactive feed is not rebuilt');
    // URL of an inactive source must never be requested.
    Db::run("INSERT INTO yx_uploads(warehouse_id,type,fetch_method,source_url,mapping) VALUES (?,'csv','url','invalid://inactive-source','{}')",[$offWh]);
    $stats=AutoUpdater::execute(true);checkCycle($stats['uploads']===0,'Inactive warehouse excluded from import');
    WarehouseRepository::setActive($offWh,true);
    $stats=AutoUpdater::execute(true);
    checkCycle($stats['uploads']===1&&$stats['failed']===1&&$stats['feeds']===0&&$stats['ozon']['status']==='skipped','Failed source blocks XML publication and API');
    WarehouseRepository::setActive($offWh,false);
    $source=App\Models\SourceRepository::create(['name'=>'Cycle API source','type'=>'csv','fetch_method'=>'upload','mapping'=>[]]);
    Db::run('INSERT INTO products(source_id,sku,name,stock_qty) VALUES (?,?,?,?)',[$source,'CAT-CYCLE','Cycle API item',3]);$product=Db::lastInsertId();
    Db::run('INSERT INTO yx_items(upload_id,warehouse_id,sku,name,import_batch) VALUES (?,?,?,?,?)',[0,$wh,'FILE-CYCLE','Cycle API item','test']);
    Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,product_id,status) VALUES (?,?,?,'confirmed')",[$wh,'FILE-CYCLE',$product]);
    App\YmlExport\OzonSettings::save(['client_id'=>'123','api_key'=>'cycle-test-not-real','feed_id'=>$feed]);
    App\YmlExport\OzonSettings::storeWarehouses([['warehouse_id'=>12345,'name'=>'Cycle target']]);
    App\YmlExport\OzonSettings::save(['client_id'=>'123','feed_id'=>$feed,'warehouse_id'=>12345,'auto_enabled'=>1,'interval_seconds'=>99999]);
    $apiCalls=0;$xmlPath=$paths[0];
    $client=new App\YmlExport\OzonClient('123','cycle-test-not-real',static function($path,$body)use(&$apiCalls,$xmlPath):array{
        $apiCalls++;
        checkCycle(is_file($xmlPath)&&str_contains(file_get_contents($xmlPath),'FILE-CYCLE'),'XML is rebuilt BEFORE API');
        checkCycle($body['stocks'][0]['offer_id']==='FILE-CYCLE'&&$body['stocks'][0]['stock']===3,'API uses file SKU and current catalog stock');
        return ['status'=>200,'body'=>json_encode(['result'=>array_map(static fn($row)=>$row+['updated'=>true,'errors'=>[]],$body['stocks'])])];
    });
    checkCycle(AutoUpdater::execute(true,$client)['ozon']['status']==='sent'&&$apiCalls===1,'Real pipeline then mocked API');
    checkCycle(AutoUpdater::execute(true,$client)['ozon']['status']==='sent'&&$apiCalls===2,'Full-cycle cadence replaces old API-only interval');
    $failingClient=new App\YmlExport\OzonClient('123','cycle-test-not-real',static fn()=>['status'=>429,'body'=>'{"message":"rate limit"}']);
    checkCycle(AutoUpdater::execute(true,$failingClient)['ozon']['status']==='error','API failure recorded');
    checkCycle(App\YmlExport\OzonSettings::scheduleState()['retry_after']<=time()+61,'API backoff uses common cycle interval instead of legacy interval');
    CycleSettings::save(['enabled'=>1,'interval_seconds'=>10,'active_feeds'=>[]]);
    $none=AutoUpdater::execute(true,$client);
    checkCycle($none['feeds']===0&&$none['uploads']===0&&$none['ozon']['status']==='inactive_feed'&&$apiCalls===2,'Inactive feed skips all work including API');
    echo "PASS: full-cycle cadence, disabled/forced runs, shared lock, failure backoff, per-feed/source activity, real XML build and standalone API coordination\n";
}finally{
    $pdo->rollBack();foreach(array_merge($files,$paths) as $file){$path=str_starts_with($file,'/')?$file:APP_STORAGE.'/'.$file;if(is_file($path))unlink($path);}
    if(!$hadLock&&is_file(APP_STORAGE.'/yml-cycle.lock'))unlink(APP_STORAGE.'/yml-cycle.lock');
}
