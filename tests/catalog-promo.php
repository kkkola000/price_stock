<?php
declare(strict_types=1);
// Integration check against the configured development DB; creates and removes only its own sources.
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Models\SourceRepository as Sources;
use App\Models\ProductRepository as Products;
use App\Import\ImportService;
use App\Support\Db;
use App\View\Catalog;
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$ids = [];
$files = [];
try {
    $suffix = bin2hex(random_bytes(6));
    $csv = 'promo-test-' . $suffix . '.csv';
    $path = APP_STORAGE . '/uploads/' . $csv;
    $files[] = $path;
    $longName = trim(str_repeat('Длинное название товара ', 12));
    $rows = [
        ['promo', $longName, '1 200,50', '1', '999,50'],
        ['plain', 'Обычный товар', '1200', '', ''],
        ['zero', 'Нулевая акция', '1200', 'под заказ', '0'],
        ['invalid', 'Некорректная акция', '1200', '5', 'нет'],
    ];
    $write = static function (array $rows) use ($path): void {
        $f = fopen($path, 'wb');
        fputcsv($f, ['sku','name','price','stock','promo'], ';', '"', '');
        foreach ($rows as $row) { fputcsv($f, $row, ';', '"', ''); }
        fclose($f);
    };
    $write($rows);
    $id = Sources::create(['name'=>'Promo test '.$suffix, 'type'=>'csv','fetch_method'=>'upload',
        'file_path'=>$csv, 'csv_delimiter'=>';', 'mapping'=>['sku'=>'A','name'=>'B','price'=>'C','stock'=>'D','promo_price'=>'E']]);
    $ids[]=$id;
    $preview=ImportService::preview(Sources::find($id),8);
    check($preview['mapped'][0]['promo_price']===999.5,'CSV promo preview');
    for($i=0;$i<2;$i++) {
        $result=ImportService::run(Sources::find($id));
        check($result['status']==='ok' && $result['rows_imported']===4,'CSV import/reimport');
        check(Products::countBySource($id)===4,'No duplicate products');
    }
    $items=Products::search(['suppliers'=>[$id]])['items'];
    $bySku=array_column($items,null,'sku');
    if ($baseUrl=getenv('CATALOG_TEST_BASE_URL')) {
        $payload=json_decode(file_get_contents(rtrim($baseUrl,'/').'/api/products.php?format=json&supplier='.$id),true,512,JSON_THROW_ON_ERROR);
        check($payload['ok'] && $payload['total']===4,'HTTP API product count');
        $apiItems=array_column($payload['items'],null,'sku');
        check((float)$apiItems['promo']['promo_price']===999.5,'HTTP API promo value');
        check((float)$apiItems['promo']['price']===1200.5,'HTTP API regular price unchanged');
    }

    $promo=Catalog::card($bySku['promo']);
    check(str_contains($promo,'<s class="card__price-old">'),'Old price is struck out');
    check(str_contains($promo,'Цена по акции'),'Promo caption');
    check(str_contains($promo,e($longName)),'Full long title retained');
    check(str_contains($promo,'data-price="'.e(price_label('999,50',999.5,'RUB')).'"'),'Order uses promo price');
    foreach(['plain','zero','invalid'] as $sku) {
        check(!str_contains(Catalog::card($bySku[$sku]),'card__price--promo'),'No promo for '.$sku);
    }
    if ($fixture=getenv('CATALOG_TEST_HTML')) {
        file_put_contents($fixture,'<!doctype html><meta charset="utf-8"><style>'.file_get_contents(APP_ROOT.'/public/assets/css/catalog.css').'</style><main class="grid">'.$promo.Catalog::card($bySku['plain']).'</main>');
    }
    // Removing the optional mapping must clear a previously imported promo price.
    Sources::update($id,['mapping'=>['sku'=>'A','name'=>'B','price'=>'C','stock'=>'D']]);
    check(ImportService::run(Sources::find($id))['status']==='ok','Import without promo mapping');
    check(Db::scalar('SELECT promo_price FROM products WHERE source_id=? AND sku=?',[$id,'promo'])===null,'Stale promo cleared');
    check((float)Db::scalar('SELECT price FROM products WHERE source_id=? AND sku=?',[$id,'promo'])===1200.5,'Regular price unchanged');
    $xml='promo-test-'.$suffix.'.xml'; $xmlPath=APP_STORAGE.'/uploads/'.$xml; $files[]=$xmlPath;
    file_put_contents($xmlPath,'<?xml version="1.0"?><yml_catalog><shop><offers><offer id="xml"><name>XML товар</name><price>1200</price><sale>900</sale><count>2</count></offer></offers></shop></yml_catalog>');
    $yid=Sources::create(['name'=>'YML promo test '.$suffix,'type'=>'yml','fetch_method'=>'upload','file_path'=>$xml,'mapping'=>['sku'=>'@id','name'=>'name','price'=>'price','promo_price'=>'sale','stock'=>'count']]);
    $ids[]=$yid;
    check(ImportService::run(Sources::find($yid))['status']==='ok','YML promo import');
    check((float)Db::scalar('SELECT promo_price FROM products WHERE source_id=?',[$yid])===900.0,'YML promo persisted');
    echo "PASS: CSV preview/import/reimport, optional/empty/zero/invalid promo, clearing promo, YML import, rendering and order price\n";
} finally {
    foreach($ids as $id) { Db::run('DELETE FROM sources WHERE id=?',[$id]); }
    foreach($files as $file) { if(is_file($file)) unlink($file); }
}
