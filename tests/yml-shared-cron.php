<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Support\Db;
use App\Models\SourceRepository;
use App\Import\ImportService;
use App\YmlExport\{Schema,CycleSettings,WarehouseRepository,FeedRepository,FeedBuilder};
function sharedCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['yml-cycle.json','yml-cycle-state.json','ozon-api.json'] as $file)if(is_file(APP_STORAGE.'/'.$file))throw new RuntimeException('Use a clean development instance; existing settings preserved.');
if(SourceRepository::list()!==[])throw new RuntimeException('Use a clean development database without real supplier sources.');
Schema::ensure();$source=$wh=$feed=null;$path=null;
$pingPath=APP_STORAGE.'/scheduler.json';$oldPing=is_file($pingPath)?file_get_contents($pingPath):null;
$run=static function(string $argument):string{
    $process=proc_open([getenv('PHP_BIN')?:PHP_BINARY,APP_ROOT.'/bin/import.php',$argument],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,APP_ROOT);
    if(!is_resource($process))throw new RuntimeException('Cannot start shared scheduler');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    sharedCheck($status===0,'Shared scheduler failed: '.$err);return $out;
};
try{
    $file='shared-cron-'.bin2hex(random_bytes(5)).'.csv';$path=APP_STORAGE.'/uploads/'.$file;file_put_contents($path,"CAT-SHARED;1\n");
    $source=SourceRepository::create(['name'=>'Shared cron test','type'=>'csv','fetch_method'=>'upload','file_path'=>$file,'skip_rows'=>0,'csv_delimiter'=>';','auto_import'=>0,'mapping'=>['sku'=>'A','name'=>'A','stock'=>'B']]);
    sharedCheck(ImportService::run(SourceRepository::find($source))['status']==='ok','Initial source');
    $product=(int)Db::scalar('SELECT id FROM products WHERE source_id=?',[$source]);
    $wh=WarehouseRepository::create('Shared cron test','shared-cron',0);$feed=FeedRepository::create('Shared cron test',[$wh]);
    Db::run('INSERT INTO yx_items(upload_id,warehouse_id,sku,name,import_batch) VALUES (?,?,?,?,?)',[0,$wh,'FILE-SHARED','Shared item','test']);
    Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,product_id,status) VALUES (?,?,?,'confirmed')",[$wh,'FILE-SHARED',$product]);
    CycleSettings::save(['enabled'=>1,'interval_seconds'=>300,'active_feeds'=>[$feed]]);
    file_put_contents($path,"CAT-SHARED;8\n");$run('--all');
    $state=CycleSettings::state();sharedCheck($state['status']==='ok'&&$state['result']['feeds']===1,'Same import command runs YML cycle');
    sharedCheck((int)Db::scalar('SELECT stock_qty FROM products WHERE id=?',[$product])===8,'Catalog stock imported first');
    $xml=file_get_contents(FeedBuilder::path(FeedRepository::find($feed)['token']));
    sharedCheck(str_contains($xml,'FILE-SHARED')&&str_contains($xml,'instock="8"'),'XML uses updated catalog stock');
    $before=file_get_contents(APP_STORAGE.'/yml-cycle-state.json');$output=$run('--due');
    sharedCheck(str_contains($output,'not_due')&&file_get_contents(APP_STORAGE.'/yml-cycle-state.json')===$before,'Shared cron respects YML interval when no suppliers are due');
    unlink(APP_STORAGE.'/yml-cycle-state.json');$output=$run('--due');
    sharedCheck(str_contains($output,'Нечего импортировать')&&CycleSettings::state()['status']==='ok','YML still runs when no suppliers need import');
    CycleSettings::save(['interval_seconds'=>300,'active_feeds'=>[$feed]]);$before=file_get_contents(APP_STORAGE.'/yml-cycle-state.json');
    sharedCheck(str_contains($run('--due'),'disabled')&&file_get_contents(APP_STORAGE.'/yml-cycle-state.json')===$before,'Disabled YML does not run through shared cron');
    echo "PASS: existing supplier scheduler imports stock before XML, respects YML interval/pause, and updates YML even with no supplier imports due\n";
}finally{
    if($feed!==null)FeedRepository::delete($feed);if($wh!==null)WarehouseRepository::delete($wh);if($source!==null)SourceRepository::delete($source);
    if($path!==null&&is_file($path))unlink($path);
    foreach(['yml-cycle.json','yml-cycle-state.json'] as $file)if(is_file(APP_STORAGE.'/'.$file))unlink(APP_STORAGE.'/'.$file);
    if($oldPing!==null)file_put_contents($pingPath,$oldPing);elseif(is_file($pingPath))unlink($pingPath);
}
