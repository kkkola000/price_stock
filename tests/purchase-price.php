<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Models\{SourceRepository,ProductRepository};
use App\Import\ImportService;
use App\View\Catalog;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$ids=[];$paths=[];
try{
    $suffix=bin2hex(random_bytes(5));
    foreach(['csv','yml'] as $type){
        $file='purchase-test-'.$suffix.'.'.$type;$path=APP_STORAGE.'/uploads/'.$file;$paths[]=$path;
        file_put_contents($path,$type==='csv'?"sku;Name;1000;800;137,25\nno-cost;No cost;500;;\n":'<yml_catalog><shop><offers><offer id="sku"><name>Name</name><price>1000</price><promo>800</promo><cost>137.25</cost></offer><offer id="no-cost"><name>No cost</name><price>500</price></offer></offers></shop></yml_catalog>');
        $mapping=$type==='csv'?['sku'=>'A','name'=>'B','price'=>'C','promo_price'=>'D','purchase_price'=>'E']:['sku'=>'@id','name'=>'name','price'=>'price','promo_price'=>'promo','purchase_price'=>'cost'];
        $id=SourceRepository::create(['name'=>'Purchase test '.$suffix,'type'=>$type,'fetch_method'=>'upload','file_path'=>$file,'skip_rows'=>0,'csv_delimiter'=>';','mapping'=>$mapping]);$ids[]=$id;
        $source=SourceRepository::find($id);$preview=ImportService::preview($source);
        check($preview['mapped'][0]['purchase_price']===137.25,$type.' preview purchase price');
        for($i=0;$i<2;$i++)check(ImportService::run($source)['status']==='ok',$type.' import/reimport');
        $bySku=array_column(ProductRepository::search(['suppliers'=>[$id]])['items'],null,'sku');
        check((float)$bySku['sku']['purchase_price']===137.25,$type.' persisted');
        check($bySku['no-cost']['purchase_price']===null,$type.' missing cost');
        check((float)$bySku['sku']['price']===1000.0&&(float)$bySku['sku']['promo_price']===800.0,'Retail/promo unchanged');
        check(!str_contains(Catalog::card($bySku['sku']),'137'),'No purchase price in catalog HTML');
        unset($mapping['purchase_price']);SourceRepository::update($id,['mapping'=>$mapping]);
        check(ImportService::run(SourceRepository::find($id))['status']==='ok','Reimport without cost mapping');
        check(ProductRepository::search(['suppliers'=>[$id]])['items'][0]['purchase_price']===null,'Removed mapping clears price');
    }
    echo "PASS: CSV/YML purchase price preview, import/reimport, missing/removed mapping, catalog privacy and unchanged retail/promo\n";
}finally{foreach($ids as $id)SourceRepository::delete($id);foreach($paths as $path)if(is_file($path))unlink($path);}
