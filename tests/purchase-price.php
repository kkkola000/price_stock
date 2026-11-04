<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Models\{SourceRepository,ProductRepository};
use App\Import\ImportService;
use App\Import\ValueParser;
use App\Models\OrderPurchasePrices;
use App\View\Catalog;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$ids=[];$paths=[];
try{
    foreach(['77.900руб'=>77900.0,'77 900 ₽'=>77900.0,'77,900'=>77900.0,'77.900,50 руб'=>77900.5,'77,900.50'=>77900.5,'1.234.567'=>1234567.0,'137.25'=>137.25,'137,25'=>137.25,'0'=>0.0] as $raw=>$expected){
        check(ValueParser::purchasePrice((string)$raw)===$expected,'Purchase number: '.$raw);
    }
    check(ValueParser::purchasePrice('нет')===null&&ValueParser::purchasePrice('-100')===null,'Invalid purchase price');
    $summary=OrderPurchasePrices::totals([['id'=>1,'qty'=>3],['id'=>2,'qty'=>2],['id'=>3,'qty'=>1]],[
        '1'=>['cents'=>7790000,'currency'=>'RUB'],'2'=>['cents'=>13725,'currency'=>'USD'],'3'=>['cents'=>null,'currency'=>'RUB']]);
    check($summary===['totals'=>['RUB'=>23370000,'USD'=>27450],'missing'=>1],'Quantity, currency separation and missing cost');
    $suffix=bin2hex(random_bytes(5));
    foreach(['csv','yml'] as $type){
        $file='purchase-test-'.$suffix.'.'.$type;$path=APP_STORAGE.'/uploads/'.$file;$paths[]=$path;
        file_put_contents($path,$type==='csv'?"sku;Name;1 000 руб.;800 руб.;77.900руб;05.11\nno-cost;No cost;500;;\n":'<yml_catalog><shop><offers><offer id="sku"><name>Name</name><price>1 000 руб.</price><promo>800 руб.</promo><count>05.11</count><cost>77.900руб</cost></offer><offer id="no-cost"><name>No cost</name><price>500</price></offer></offers></shop></yml_catalog>');
        $mapping=$type==='csv'?['sku'=>'A','name'=>'B','price'=>'C','promo_price'=>'D','purchase_price'=>'E','stock'=>'F']:['sku'=>'@id','name'=>'name','price'=>'price','promo_price'=>'promo','purchase_price'=>'cost','stock'=>'count'];
        $id=SourceRepository::create(['name'=>'Purchase test '.$suffix,'type'=>$type,'fetch_method'=>'upload','file_path'=>$file,'skip_rows'=>0,'csv_delimiter'=>';','mapping'=>$mapping]);$ids[]=$id;
        $source=SourceRepository::find($id);$preview=ImportService::preview($source);
        check($preview['mapped'][0]['purchase_price']===77900.0,$type.' preview purchase price');
        for($i=0;$i<2;$i++)check(ImportService::run($source)['status']==='ok',$type.' import/reimport');
        $bySku=array_column(ProductRepository::search(['suppliers'=>[$id]])['items'],null,'sku');
        check((float)$bySku['sku']['purchase_price']===77900.0,$type.' persisted');
        check($bySku['no-cost']['purchase_price']===null,$type.' missing cost');
        check((float)$bySku['sku']['price']===1000.0&&(float)$bySku['sku']['promo_price']===800.0,'Retail/promo unchanged');
        check($bySku['sku']['price_text']==='1 000 руб.'&&$bySku['sku']['promo_price_text']==='800 руб.'&&$bySku['sku']['stock_text']==='05.11','Original retail/promo/stock text preserved');
        check(!str_contains(Catalog::card($bySku['sku']),'77.900'),'No purchase price in catalog HTML');
        unset($mapping['purchase_price']);SourceRepository::update($id,['mapping'=>$mapping]);
        check(ImportService::run(SourceRepository::find($id))['status']==='ok','Reimport without cost mapping');
        check(ProductRepository::search(['suppliers'=>[$id]])['items'][0]['purchase_price']===null,'Removed mapping clears price');
    }
    echo "PASS: CSV/YML purchase price preview, import/reimport, missing/removed mapping, catalog privacy and unchanged retail/promo\n";
}finally{foreach($ids as $id)SourceRepository::delete($id);foreach($paths as $path)if(is_file($path))unlink($path);}
