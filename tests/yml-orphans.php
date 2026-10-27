<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Support\Db;
use App\YmlExport\{WarehouseRepository as Warehouses,UploadRepository as Uploads,Matcher};
function checkOrphans(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
$ids=[];
try {
    $wh=Warehouses::create('Orphan regression test','test',0);$ids[]=$wh;
    Db::run("INSERT INTO yx_uploads(warehouse_id,type,fetch_method,mapping) VALUES (?,'csv','upload','{}')",[$wh]);$upload=Db::lastInsertId();
    Db::run("INSERT INTO yx_items(upload_id,warehouse_id,sku,name,import_batch) VALUES (?,?,'live','Test','test')",[$upload,$wh]);
    foreach(['live','temporarily-missing'] as $sku) Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,status) VALUES (?,?,'confirmed')",[$wh,$sku]);
    checkOrphans(count(array_filter(Matcher::confirmedList(),static fn($r)=>(int)$r['warehouse_id']===$wh))===1,'List hides missing source items');
    Matcher::cleanupOrphans();
    checkOrphans((int)Db::scalar('SELECT COUNT(*) FROM yx_matches WHERE warehouse_id=?',[$wh])===2,'Keep missing-row confirmation while upload exists');
    Uploads::delete($upload);
    checkOrphans((int)Db::scalar('SELECT COUNT(*) FROM yx_matches WHERE warehouse_id=?',[$wh])===0,'Deleting file removes matches');
    checkOrphans(Warehouses::find($wh)!==null,'Deleting file preserves warehouse');
    Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,status) VALUES (?,'old-file','unmatched')",[$wh]);
    checkOrphans(array_filter(Matcher::queue(),static fn($r)=>(int)$r['warehouse_id']===$wh)===[],'Queue hides stale file item');
    Matcher::cleanupOrphans();
    checkOrphans((int)Db::scalar('SELECT COUNT(*) FROM yx_matches WHERE warehouse_id=?',[$wh])===0,'Repair old file deletion');
    Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,status) VALUES (?,'delete-warehouse','unmatched')",[$wh]);
    Warehouses::delete($wh);
    checkOrphans((int)Db::scalar('SELECT COUNT(*) FROM yx_matches WHERE warehouse_id=?',[$wh])===0,'Warehouse deletion removes matches');
    Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,status) VALUES (?,'old-warehouse','unmatched')",[$wh]);
    Matcher::cleanupOrphans();
    checkOrphans((int)Db::scalar('SELECT COUNT(*) FROM yx_matches WHERE warehouse_id=?',[$wh])===0,'Repair old warehouse deletion');
    echo "PASS: file/warehouse deletion, old orphan repair, hidden missing items, preserving confirmations for reimport\n";
} finally {
    foreach($ids as $id){Db::run('DELETE FROM yx_matches WHERE warehouse_id=?',[$id]);Warehouses::delete($id);}
}
