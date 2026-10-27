<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Support\Db;
use App\YmlExport\{FeedRepository,FeedBuilder,SettingsRepository,StockMapRepository,WarehouseRepository,Matcher};
function scopeCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$pdo=Db::pdo();$pdo->beginTransaction();$paths=[];
try {
    // Simulate first visit: primary link exists before any test warehouse.
    Db::run('DELETE FROM yx_feeds');
    FeedRepository::ensureDefault();$primary=FeedRepository::list()[0];
    scopeCheck($primary['warehouse_mode']==='all','New primary link uses dynamic active warehouses');
    $wh=WarehouseRepository::create('30 item test','test',0);
    $other=WarehouseRepository::create('Other scope','other',1);
    $selected=FeedRepository::create('Selected test',[$other]);
    $source=App\Models\SourceRepository::create(['name'=>'Scope source','type'=>'csv','fetch_method'=>'upload','mapping'=>[]]);
    for($i=1;$i<=30;$i++) {
        Db::run('INSERT INTO products(source_id,sku,name,stock_qty,stock_text,currency) VALUES (?,?,?,?,?,?)',[$source,'CAT-'.$i,'Product '.$i,$i===1?0:null,'Более 5','RUB']);$product=Db::lastInsertId();
        Db::run('INSERT INTO yx_items(upload_id,warehouse_id,sku,name,import_batch) VALUES (?,?,?,?,?)',[0,$wh,'FILE-'.$i,'Product '.$i,'test']);
        Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,product_id,status) VALUES (?,?,?,'confirmed')",[$wh,'FILE-'.$i,$product]);
    }
    scopeCheck(count(Matcher::confirmedList(500,'30 item test','all'))===30,'Thirty confirmed items visible');
    $settings=SettingsRepository::get();$outlets=$settings['outlets_array'];
    $outlets['template']=['text'=>'<yml_catalog><shop><offers>{% для каждого товара %}<offer id="{{артикул}}"><outlets><outlet instock="{{остаток}}" warehouse_name="Москва ОБН"/></outlets></offer>{% конец %}</offers></shop></yml_catalog>',
        'variables'=>[['name'=>'артикул','source'=>['type'=>'field','ref'=>'sku']],['name'=>'остаток','source'=>['type'=>'resolved','ref'=>'stock']]]];
    SettingsRepository::save('','','','',$settings['offer_mapping_array'],$outlets,true,false,true,0);
    StockMapRepository::replaceAll([['pattern'=>'Более 5','qty'=>10]]);
    $primary=FeedRepository::find((int)$primary['id']);
    scopeCheck(in_array($wh,$primary['warehouse_ids'],true),'Warehouse added after primary link is included');
    $built=FeedBuilder::buildFeed($primary);$paths[]=$built['path'];$xml=simplexml_load_file($built['path']);
    scopeCheck($built['offers']===30&&count($xml->shop->offers->offer)===30,'All thirty confirmed products reach user template');
    scopeCheck((string)$xml->shop->offers->offer[0]['id']==='FILE-1','ID from file');
    scopeCheck((string)$xml->shop->offers->offer[0]->outlets->outlet['instock']==='0','Zero stock included with filter off');
    scopeCheck((string)$xml->shop->offers->offer[1]->outlets->outlet['instock']==='10','Mapped catalog stock');
    $apiRows=\App\YmlExport\OzonUpdater::prepare(['feed_id'=>$primary['id'],'warehouse_id'=>22142605386000]);
    scopeCheck(count($apiRows)===30&&$apiRows[0]['offer_id']==='FILE-1'&&$apiRows[0]['stock']===0&&$apiRows[0]['warehouse_id']===22142605386000,'API export contains 30 confirmed file IDs, zero, destination warehouse');

    $built=FeedBuilder::buildFeed(FeedRepository::find($selected));$paths[]=$built['path'];
    scopeCheck($built['offers']===0,'Explicit selection does not leak other warehouse products');
    WarehouseRepository::setActive($wh,false);
    scopeCheck(!in_array($wh,FeedRepository::find((int)$primary['id'])['warehouse_ids'],true),'Inactive warehouse excluded');
    echo "PASS: primary link before warehouses, 30 confirmed items -> 30 offers, file SKUs, mapped/zero stock, explicit feed isolation, inactive warehouse\n";
}finally{$pdo->rollBack();foreach($paths as $path)if(is_file($path))unlink($path);}
