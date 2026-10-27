<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Support\Db;
use App\Models\SourceRepository;
use App\YmlExport\Matcher;
use App\YmlExport\WarehouseRepository;
function expectMatch(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
// All changes, including bulk clearing, are rolled back. Run against a development database.
$pdo=Db::pdo();$pdo->beginTransaction();
try {
    $key='match'.bin2hex(random_bytes(6));
    $source=SourceRepository::create(['name'=>$key.'Supplier','type'=>'csv','fetch_method'=>'upload','mapping'=>[]]);
    Db::run('INSERT INTO products(source_id,sku,name,price,currency) VALUES (?,?,?,?,?)',[$source,$key.'SKU',$key.'Name',100,'RUB']);
    $product=Db::lastInsertId();
    $wh=WarehouseRepository::create($key.'Warehouse','test',0);
    Db::run('INSERT INTO yx_items(upload_id,warehouse_id,sku,name,import_batch) VALUES (?,?,?,?,?)',[0,$wh,$key.'Item',$key.'ItemName','test']);
    Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,product_id,status) VALUES (?,?,?,'confirmed')",[$wh,$key.'Item',$product]);
    $match=Db::lastInsertId();
    expectMatch(count(Matcher::candidates($key.'SKU',8,'sku'))===1,'Candidate SKU search');
    expectMatch(Matcher::candidates($key.'SKU',8,'name')===[],'Name mode excludes SKU');
    expectMatch(count(Matcher::candidates($key.'Name',8,'name'))===1,'Candidate name search');
    expectMatch(count(Matcher::candidates($key.'Supplier',8,'all'))===1,'All fields includes supplier');
    expectMatch(Matcher::candidates($key.'%',8,'all')===[],'Wildcard treated literally');
    expectMatch(count(Matcher::confirmedList(500,$key.'SKU','sku'))===1,'Confirmed list searches catalog SKU');
    expectMatch(count(Matcher::confirmedList(500,$key.'Item','sku'))===1,'Confirmed list searches warehouse SKU');
    expectMatch(Matcher::confirmedList(500,$key.'SKU','name')===[],'List field isolation');
    expectMatch(Matcher::find($match)!==null,'Direct match lookup');
    Matcher::clearConfirmed();
    expectMatch(Matcher::confirmedList(500,$key,'all')===[],'Confirmed list cleared');
    expectMatch(count(Matcher::queue(300,$key.'Warehouse','all'))===1,'Returned to queue');
    expectMatch((int)Db::scalar('SELECT COUNT(*) FROM products WHERE id=?',[$product])===1,'Catalog preserved');
    expectMatch((int)Db::scalar('SELECT COUNT(*) FROM yx_items WHERE warehouse_id=?',[$wh])===1,'Warehouse items preserved');
    Matcher::confirm($match,$product,1);
    expectMatch(Matcher::find($match)['status']==='confirmed','Manual selection confirms');
    echo "PASS: field-specific/literal search, supplier search, direct lookup, clearing confirmations, preserving products/items, manual selection\n";
} finally { $pdo->rollBack(); }
