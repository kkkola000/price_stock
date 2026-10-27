<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\YmlExport\{OzonClient,OzonUpdater};
function checkOzon(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$page=0;
$client=new OzonClient('123','test-secret',static function($path,$body)use(&$page):array {
 checkOzon($path==='/v2/warehouse/list'&&$body['limit']===100,'Warehouse endpoint and limit');
 $page++;checkOzon($body['cursor']===($page===1?'':'next'),'Pagination cursor');
 return ['status'=>200,'body'=>json_encode(['warehouses'=>[['warehouse_id'=>20605650762000+$page,'name'=>'Warehouse '.$page]],'has_next'=>$page===1,'cursor'=>$page===1?'next':''])];
});
checkOzon(count($client->warehouses())===2&&$page===2,'Pagination reads both pages');
$rows=[];for($i=0;$i<205;$i++)$rows[]=['offer_id'=>'SKU-'.$i,'stock'=>$i===0?0:10,'warehouse_id'=>22142605386000];
$calls=0;$client=new OzonClient('123','test-secret',static function($path,$body)use(&$calls):array{
 checkOzon($path==='/v2/products/stocks','Stocks endpoint');checkOzon(count($body['stocks'])<=100,'Batch at most 100');$calls++;
 foreach($body['stocks'] as $row)checkOzon(!isset($row['product_id'])&&$row['warehouse_id']===22142605386000,'No local product ID; destination warehouse preserved');
 return ['status'=>200,'body'=>json_encode(['result'=>array_map(static fn($r)=>$r+['updated'=>true,'errors'=>[]],$body['stocks'])])];
});
$r=OzonUpdater::send($client,$rows);checkOzon($calls===3&&$r['success']===205&&$r['failed']===0&&$r['not_sent']===0,'All batches acknowledged');
$calls=0;$client=new OzonClient('123','test-secret',static function($path,$body)use(&$calls):array{
 if(++$calls===2)return ['status'=>429,'body'=>'{"message":"rate limited test-secret"}'];
 return ['status'=>200,'body'=>json_encode(['result'=>array_map(static fn($r)=>$r+['updated'=>true,'errors'=>[]],$body['stocks'])])];
});
$r=OzonUpdater::send($client,$rows);checkOzon($r['success']===100&&$r['failed']===100&&$r['not_sent']===5&&$calls===2,'HTTP error stops later batches; no silent retry');
checkOzon(!str_contains(json_encode($r),'test-secret'),'API key redacted');
$client=new OzonClient('123','test-secret',static fn()=>['status'=>200,'body'=>json_encode(['result'=>[['offer_id'=>'SKU-0','updated'=>false,'errors'=>[['code'=>'INVALID','message'=>'Bad SKU']]]]])]);
$r=OzonUpdater::send($client,array_slice($rows,0,2));checkOzon($r['success']===0&&$r['failed']===2,'Partial/missing item results are not success');
$client=new OzonClient('123','test-secret',static fn()=>['status'=>200,'body'=>'invalid-json']);
$r=OzonUpdater::send($client,array_slice($rows,0,1));checkOzon($r['failed']===1,'Malformed JSON handled');
echo "PASS: Ozon pagination, stock payload, zero stock, 100-item batching, partial responses, rate-limit stop and credential redaction\n";
