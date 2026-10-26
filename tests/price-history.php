<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Models\SourceRepository as Sources;
use App\Models\PriceChangeRepository as History;
use App\Import\ImportService;
use App\Support\Db;
function verify(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
$id=null;
$file=APP_STORAGE.'/uploads/history-test-'.bin2hex(random_bytes(6)).'.csv';
try {
    $id=Sources::create(['name'=>'History test','type'=>'csv','fetch_method'=>'upload','file_path'=>basename($file),'csv_delimiter'=>';',
        'mapping'=>['sku'=>'A','name'=>'B','price'=>'C','promo_price'=>'D']]);
    $write=static function(string $price,string $promo) use($file):void {
        $f=fopen($file,'wb');
        fputcsv($f,['sku','name','price','promo'],';','"','');
        fputcsv($f,['history-sku','Товар <test>',$price,$promo],';','"','');
        fclose($f);
    };
    $run=static function()use($id):void {verify(ImportService::run(Sources::find($id))['status']==='ok','Import failed');};
    $count=static fn():int=>(int)Db::scalar('SELECT COUNT(*) FROM price_changes WHERE source_id=?',[$id]);
    $write('1200','');$run();verify($count()===0,'New product must not create an event');
    $run();verify($count()===0,'Unchanged import must not create an event');
    $write('1 200,00','');$run();verify($count()===0,'Formatting-only change must not create an event');
    $write('1100','999');$run();verify($count()===1,'Changed price and promo must create one event');
    $first=Db::first('SELECT * FROM price_changes WHERE source_id=?',[$id]);
    verify((float)$first['old_price']===1200.0 && (float)$first['new_price']===1100.0,'Old and new regular values');
    verify($first['old_promo_price']===null && (float)$first['new_promo_price']===999.0,'Promo started');
    $write('1100','899');$run();verify($count()===2,'Promo-only change');
    $write('1100','');$run();verify($count()===3,'Promo ended');
    $events=array_values(array_filter(History::recent()['items'],static fn($r)=>(int)$r['source_id']===$id));
    verify(count($events)===3 && $events[0]['new_promo_price']===null,'Newest first with stable same-second ordering');
    $latest=$events[0];
    Db::run('UPDATE price_changes SET changed_at=DATE_SUB(NOW(),INTERVAL 8 DAY) WHERE id=?',[$first['id']]);
    $events=array_filter(History::recent()['items'],static fn($r)=>(int)$r['source_id']===$id);
    verify(count($events)===2,'Events older than seven days excluded');
    Db::run("UPDATE import_runs SET status='error' WHERE id=?",[$latest['import_run_id']]);
    $events=array_filter(History::recent()['items'],static fn($r)=>(int)$r['source_id']===$id);
    verify(count($events)===1,'Failed imports excluded');
    Db::run('UPDATE sources SET is_active=0 WHERE id=?',[$id]);
    verify(array_filter(History::recent()['items'],static fn($r)=>(int)$r['source_id']===$id)===[],'Inactive suppliers hidden');
    Db::run('UPDATE sources SET is_active=1 WHERE id=?',[$id]);
    if($url=getenv('CATALOG_TEST_BASE_URL')) {
        $html=file_get_contents(rtrim($url,'/').'/price-changes.php');
        verify(str_contains($html,'Товар &lt;test&gt;'),'HTTP history renders and escapes title');
        verify(str_contains($html,'Промо цена'),'HTTP promo row');
        if($output=getenv('HISTORY_TEST_HTML')) file_put_contents($output,$html);
    }
    echo "PASS: first/unchanged/formatted import, regular and promo changes, promo removal, seven-day window, newest-first, failed imports, inactive suppliers, HTTP rendering\n";
} finally {
    if($id!==null) Db::run('DELETE FROM sources WHERE id=?',[$id]);
    if(is_file($file)) unlink($file);
}
