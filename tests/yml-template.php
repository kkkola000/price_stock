<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\YmlExport\XmlTemplate;
function templateCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$variables=[['name'=>'артикул','source'=>['type'=>'field','ref'=>'sku']],['name'=>'цена','source'=>['type'=>'catalog','ref'=>'price']],['name'=>'остаток','source'=>['type'=>'resolved','ref'=>'stock']],['name'=>'склад','source'=>['type'=>'warehouse','ref'=>'name']],['name'=>'текст','source'=>['type'=>'constant','ref'=>'<&" test']]];
$text='<yml_catalog><shop><offers>{% для каждого товара %}<offer id="{{артикул}}"><price>{{цена}}</price><label>{{текст}}</label><outlets>{% для каждого склада %}<outlet instock="{{остаток}}" warehouse_name="{{склад}}"/>{% конец %}</outlets></offer>{% конец %}</offers></shop></yml_catalog>';
$doc=XmlTemplate::compile($text,$variables);$xml=new XMLWriter();$xml->openMemory();$xml->startDocument();
XmlTemplate::write($xml,$doc,$variables,static function(callable $emit):void{
 foreach(['sku-A','sku-B']as $sku)$emit(static fn($source,$wh)=>match($source['type']){'field'=>$sku,'catalog'=>'9760','resolved'=>(string)$wh['qty'],'warehouse'=>$wh['name'],default=>''},[['qty'=>10,'name'=>'склад 1'],['qty'=>13,'name'=>'склад 2']]);
},true);$xml->endDocument();$result=simplexml_load_string($xml->outputMemory());
templateCheck(count($result->shop->offers->offer)===2,'Product repetition');
templateCheck((string)$result->shop->offers->offer[1]['id']==='sku-B','Product variable');
templateCheck((string)$result->shop->offers->offer->outlets->outlet[1]['instock']==='13','Warehouse repetition');
templateCheck((string)$result->shop->offers->offer->label==='<&" test','Escaping');
foreach([str_replace('{{цена}}','{{неизвестная}}',$text),str_replace('{% конец %}','',$text),str_replace('для каждого склада','неизвестная инструкция',$text),'<!DOCTYPE x SYSTEM "file:///etc/passwd">'.$text,str_replace('<price>','<broken>',$text),'<x>{{артикул}}'.$text.'</x>',str_replace('<price>{{цена}}</price>','<price>{{склад}}</price>',$text)]as $bad){
 $rejected=false;try{XmlTemplate::compile($bad,$variables);}catch(InvalidArgumentException){$rejected=true;}templateCheck($rejected,'Invalid template accepted');
}
$duplicate=$variables;$duplicate[]=$variables[0];$rejected=false;try{XmlTemplate::compile($text,$duplicate);}catch(InvalidArgumentException){$rejected=true;}templateCheck($rejected,'Duplicate variable rejected');
echo "PASS: template loops, product/warehouse scope, escaping, unknown/duplicate variables, malformed XML and forbidden DTD\n";
