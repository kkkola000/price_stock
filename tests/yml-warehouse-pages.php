<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Support\Db;
use App\YmlExport\{Schema,WarehouseRepository,WarehouseItems,Matcher};
function checkPages(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
Schema::ensure();$pdo=Db::pdo();$pdo->beginTransaction();
try{
    $a=WarehouseRepository::create('Scope A','scope-a',0);$b=WarehouseRepository::create('Scope B','scope-b',1);
    $source=App\Models\SourceRepository::create(['name'=>'Scope products','type'=>'csv','fetch_method'=>'upload','mapping'=>[]]);
    Db::run('INSERT INTO products(source_id,sku,name) VALUES (?,?,?)',[$source,'SCOPE-P','Scope product']);$product=Db::lastInsertId();
    foreach([$a,$b] as $wh)foreach(['confirmed','suggested','unmatched','rejected'] as $i=>$status){
        $sku='SCOPE-'.$wh.'-'.$i;
        Db::run('INSERT INTO yx_items(upload_id,warehouse_id,sku,name,stock_text,import_batch) VALUES (?,?,?,?,?,?)',[0,$wh,$sku,'Only warehouse '.$wh.' '.$status,'05.11','test']);
        Db::run('INSERT INTO yx_matches(warehouse_id,item_sku,product_id,status,score) VALUES (?,?,?,?,?)',[$wh,$sku,$status==='unmatched'?null:$product,$status,100]);
    }
    checkPages(count(Matcher::queue(300,'','all',$a))===2,'Queue scoped');
    checkPages(count(Matcher::confirmedList(500,'','all',$a))===1,'Confirmations scoped');
    checkPages(Matcher::stats($a)===['suggested'=>1,'confirmed'=>1,'rejected'=>1,'unmatched'=>1],'Stats scoped');
    $items=WarehouseItems::search($a);checkPages($items['total']===4,'Products include unmatched and rejected');
    foreach($items['items'] as $item)checkPages((int)$item['warehouse_id']===$a&&$item['stock_text']==='05.11','Product scope and original stock text');
    checkPages(Matcher::clearConfirmed($a)===1&&Matcher::stats($b)['confirmed']===1,'Clear cannot affect another warehouse');
    checkPages(Matcher::bulkConfirm(80,1,$a)===2&&Matcher::stats($b)['suggested']===1,'Bulk confirmation stays scoped');
    for($i=0;$i<55;$i++)Db::run('INSERT INTO yx_items(upload_id,warehouse_id,sku,name,import_batch) VALUES (?,?,?,?,?)',[0,$a,'PAGE-'.$i,'Pagination item '.$i,'test']);
    $page=WarehouseItems::search($a,'Pagination','name',2);checkPages($page['total']===55&&count($page['items'])===5&&$page['pages']===2,'Full product pagination');
    checkPages(WarehouseItems::search($a,'PAGE-1','sku')['total']===11,'SKU search');
    checkPages(WarehouseItems::search($b,'Pagination','name')['total']===0,'Search cannot cross warehouses');
    checkPages(WarehouseItems::search($a,'%','all')['total']===0,'Literal wildcard search');
    echo "PASS: per-warehouse queues/stats, all products, pagination/search, preserved stock and isolated clear/bulk operations\n";
}finally{$pdo->rollBack();}
