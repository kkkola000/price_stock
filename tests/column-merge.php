<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Import\{ColumnRef,ImportService};
use App\YmlExport\UploadImporter;
function check(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
$row=['',' Коляска ANEX ','','','',' AX-123 ',''];
check(ColumnRef::toIndices(' b, F,aa ')===[1,5,26],'Ordered mixed-case references');
check(ColumnRef::joinedValue($row,ColumnRef::toIndices('B,F'))==='Коляска ANEX AX-123','Example');
check(ColumnRef::joinedValue($row,ColumnRef::toIndices('F,G,B'))==='AX-123 Коляска ANEX','Order and blank middle');
check(ColumnRef::joinedValue(['0','', '2'],[0,1,2])==='0 2','Zero is not empty');
check(ColumnRef::joinedValue($row,[1,6,5],false)==='Коляска ANEX  AX-123','Keep blank slot');
check(ColumnRef::joinedValue($row,[1,99,5])==='Коляска ANEX AX-123','Missing cells');
foreach(['B,,F',',B','B,','B;F','0','B,0','B,?'] as $ref){
    try{ColumnRef::toIndices($ref);throw new RuntimeException('Accepted invalid mapping: '.$ref);}catch(InvalidArgumentException){}
}
$mapRow=new ReflectionMethod(ImportService::class,'mapRow');
$buildItem=new ReflectionMethod(UploadImporter::class,'buildItem');
foreach(['1'=>'Коляска ANEX AX-123','0'=>'Коляска ANEX  AX-123'] as $skip=>$expected){
    $mapping=['name'=>'B,G,F','sku'=>'F','_merge_skip_empty'=>(string)$skip];
    $errors=[];
    $source=['type'=>'csv','mapping_array'=>$mapping];
    $args=[$row,$source,&$errors];
    $product=$mapRow->invokeArgs(null,$args);
    check($product['name']===$expected && $product['sku']==='AX-123','Catalog merge / checkbox '.$skip);
    $item=$buildItem->invoke(null,$row,$mapping);
    check($item['name']===$expected && $item['sku']==='AX-123','Warehouse merge / checkbox '.$skip);
}
// Existing single references and YML tags keep their behavior.
$errors=[];$args=[$row,['type'=>'csv','mapping_array'=>['name'=>'B','sku'=>'F']],&$errors];
check($mapRow->invokeArgs(null,$args)['name']==='Коляска ANEX','Single reference');
$yml=['name'=>['Коляска ANEX'],'@id'=>['AX-123']];
check($buildItem->invoke(null,$yml,['name'=>'name','sku'=>'@id'])['name']==='Коляска ANEX','YML warehouse unchanged');
$method=new ReflectionMethod(ImportService::class,'mapOffer');$errors=[];
$args=[$yml,['type'=>'yml','mapping_array'=>['name'=>'name','sku'=>'@id']],&$errors];
check($method->invokeArgs(null,$args)['sku']==='AX-123','YML catalog unchanged');
// Real reader and preview share the mapping path, including the checkbox.
$file='column-merge-'.bin2hex(random_bytes(6)).'.csv';$path=APP_STORAGE.'/uploads/'.$file;
try {
    $handle=fopen($path,'wb');fputcsv($handle,$row,';','"','');fclose($handle);
    $preview=ImportService::preview(['type'=>'csv','fetch_method'=>'upload','file_path'=>$file,'skip_rows'=>0,
        'csv_delimiter'=>';','csv_encoding'=>'UTF-8','mapping_array'=>['name'=>'B,G,F','sku'=>'F','_merge_skip_empty'=>'0']]);
    check($preview['mapped'][0]['name']==='Коляска ANEX  AX-123','CSV preview preserves blank slot');
}finally{if(is_file($path))unlink($path);}
echo "PASS: column order, multiple columns, empty-cell checkbox, zero, invalid references, catalog/warehouse mapping, CSV preview and YML compatibility\n";
