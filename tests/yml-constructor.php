<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Support\Db;
use App\YmlExport\{XmlMapping,SettingsRepository,StockMapRepository,WarehouseRepository,FeedBuilder};
function ensureXml(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function invalidXml(array $nodes):void {try {XmlMapping::validate($nodes);}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid tree accepted');}
$settings=SettingsRepository::get();
$nodes=$settings['offer_mapping_array'];XmlMapping::validate($nodes);
$legacy=[['kind'=>'attribute','name'=>'id','enabled'=>true,'source'=>['type'=>'field','ref'=>'sku']],['kind'=>'tag','name'=>'price','enabled'=>true,'source'=>['type'=>'catalog','ref'=>'price']]];
$outlets=['enabled'=>true,'parent'=>'outlets','tag'=>'outlet','stock_attr'=>'instock','name_attr'=>'warehouse_name'];
$tree=XmlMapping::upgrade($legacy,$outlets);XmlMapping::validate($tree);
foreach ([['type'=>'field','ref'=>'sku'],['type'=>'column','ref'=>'AA'],['type'=>'constant','ref'=>'custom value'],['type'=>'catalog','ref'=>'price'],['type'=>'catalog','ref'=>'stock'],['type'=>'resolved','ref'=>'stock'],['type'=>'warehouse','ref'=>'name']] as $sourceRule) {
    $form=XmlMapping::formSource($sourceRule);
    ensureXml(in_array($form['type'],['file','catalog','constant'],true),'Three source options');
    ensureXml(XmlMapping::sourceFromForm($form['type'],$form['ref'],$form['extra'])===$sourceRule,'Old source must round-trip without changing the feed');
}
ensureXml(XmlMapping::sourceFromForm('file','A')===['type'=>'column','ref'=>'A'],'Direct column letter');

ensureXml(XmlMapping::upgrade($tree,$outlets)===$tree,'Upgrade must not duplicate warehouse blocks');
$bad=$tree;$bad[0]['name']='bad<name';invalidXml($bad);
$bad=$tree;$bad[2]['parent']='stock_row';invalidXml($bad);
$bad=$tree;$bad[]=$tree[0];$bad[count($bad)-1]['key']='duplicate_id';invalidXml($bad);
$pdo=Db::pdo();$pdo->beginTransaction();$paths=[];
try {
    $source=App\Models\SourceRepository::create(['name'=>'XML test','type'=>'csv','fetch_method'=>'upload','mapping'=>[]]);
    Db::run('INSERT INTO products(source_id,sku,name,price,stock_text,stock_qty) VALUES (?,?,?,?,?,NULL)',[$source,'CATALOG-SKU','XML <& product',9760,'Более 5']);$product=Db::lastInsertId();
    $warehouseIds=[];
    foreach([7,13] as $i=>$stock) {
        $wh=WarehouseRepository::create('склад '.($i+1),'test',$i);$warehouseIds[]=$wh;
        Db::run('INSERT INTO yx_items(upload_id,warehouse_id,sku,name,stock_qty,price,oldprice,min_price,extra,import_batch) VALUES (?,?,?,?,?,?,?,?,?,?)',[0,$wh,'FILE-SKU','File name',$stock,9760,10736,8000,json_encode(['A'=>'raw<&']), 'test']);
        Db::run("INSERT INTO yx_matches(warehouse_id,item_sku,product_id,status) VALUES (?,?,?,'confirmed')",[$wh,'FILE-SKU',$product]);
    }
    $virtual=WarehouseRepository::create('Виртуальный','virtual',2,'virtual',3);$warehouseIds[]=$virtual;
    StockMapRepository::replaceAll([['pattern'=>'Более 5','qty'=>10]]);
    $save=static function(array $mapping,array $envelope=[]) use($settings,$outlets):void {
        SettingsRepository::save('','','','',$mapping,array_merge($outlets,$envelope),true,false,true,2);
    };
    $build=static function()use($warehouseIds,&$paths):SimpleXMLElement {
        $result=FeedBuilder::buildFeed(['id'=>0,'token'=>bin2hex(random_bytes(16)),'warehouse_ids'=>$warehouseIds]);$paths[]=$result['path'];
        $xml=simplexml_load_file($result['path']);ensureXml($xml!==false,'Generated XML parses');return $xml;
    };
    $converted=$tree;
    foreach($converted as &$node) { $form=XmlMapping::formSource($node['source']??[]);$node['source']=XmlMapping::sourceFromForm($form['type'],$form['ref'],$form['extra']); }
    unset($node);
    $converted[]=['key'=>'offer_stock','parent'=>'offer','kind'=>'tag','name'=>'quantity','enabled'=>true,'source'=>XmlMapping::sourceFromForm('catalog','stock')];
    $save($converted);$xml=$build();$offer=$xml->shop->offers->offer;
    ensureXml((string)$offer['id']==='FILE-SKU','ID comes from warehouse file');
    ensureXml((string)$offer->price==='9760','Price from catalog');
    ensureXml((string)$offer->quantity==='10','Mapped catalog stock directly in offer');
    ensureXml(count($offer->outlets->outlet)===3,'Two real warehouses and virtual');
    ensureXml((string)$offer->outlets->outlet[0]['instock']==='10'&&(string)$offer->outlets->outlet[1]['instock']==='10','Catalog text mapping: Более 5 -> 10');
    ensureXml((string)$offer->outlets->outlet[2]['instock']==='3','Virtual stock constant');
    $numericMapping=$converted;
    $numericMapping[]=['key'=>'raw_numeric','parent'=>'offer','kind'=>'tag','name'=>'raw_quantity','enabled'=>true,'source'=>['type'=>'catalog','ref'=>'stock']];
    $save($numericMapping,['numeric_stock_rule'=>['enabled'=>true,'threshold'=>5,'value'=>10]]);
    foreach([0=>0,4=>4,5=>10,6=>10,100=>10] as $input=>$expected) {
        Db::run('UPDATE products SET stock_qty=? WHERE id=?',[$input,$product]);
        $numericXml=$build();$numericOffer=$numericXml->shop->offers->offer;
        ensureXml((string)$numericOffer->quantity===(string)$expected,'Numeric boundary >= threshold');
        ensureXml((string)$numericOffer->raw_quantity===(string)$expected,'Numeric catalog source uses rule');
        ensureXml((string)$numericOffer->outlets->outlet[0]['instock']===(string)$expected,'Warehouse outlet uses numeric rule');
        ensureXml((int)Db::scalar('SELECT stock_qty FROM products WHERE id=?',[$product])===$input,'Catalog stock is not modified');
    }
    $save($numericMapping,['numeric_stock_rule'=>['enabled'=>true,'threshold'=>5,'value'=>10,'lower_enabled'=>true,'lower_threshold'=>4,'lower_value'=>0]]);
    foreach([0=>0,3=>0,4=>0,5=>10,6=>10] as $input=>$expected) {
        Db::run('UPDATE products SET stock_qty=? WHERE id=?',[$input,$product]);
        $numericXml=$build();ensureXml((string)$numericXml->shop->offers->offer->quantity===(string)$expected,'Both >= and <= inclusive boundaries');
    }
    $save($numericMapping,['numeric_stock_rule'=>['enabled'=>true,'threshold'=>5,'value'=>10,'lower_enabled'=>true,'lower_threshold'=>2,'lower_value'=>0]]);
    Db::run('UPDATE products SET stock_qty=3 WHERE id=?',[$product]);
    $numericXml=$build();ensureXml((string)$numericXml->shop->offers->offer->quantity==='3','Gap between thresholds is unchanged');
    $save($numericMapping,['numeric_stock_rule'=>['enabled'=>false,'lower_enabled'=>true,'lower_threshold'=>4,'lower_value'=>0]]);
    Db::run('UPDATE products SET stock_qty=4 WHERE id=?',[$product]);
    $numericXml=$build();ensureXml((string)$numericXml->shop->offers->offer->quantity==='0','Lower rule works independently');
    Db::run('UPDATE products SET stock_qty=100 WHERE id=?',[$product]);
    $save($numericMapping,['numeric_stock_rule'=>['enabled'=>false,'threshold'=>5,'value'=>10]]);
    $numericXml=$build();ensureXml((string)$numericXml->shop->offers->offer->quantity==='100','Disabled numeric rule');
    $save($numericMapping,['numeric_stock_rule'=>['enabled'=>true,'threshold'=>3,'value'=>2]]);
    Db::run('UPDATE products SET stock_qty=NULL WHERE id=?',[$product]);
    $numericXml=$build();ensureXml((string)$numericXml->shop->offers->offer->quantity==='10','Text map result is not thresholded');
    ensureXml((string)$numericXml->shop->offers->offer->outlets->outlet[2]['instock']==='3','Virtual stock is not thresholded');
    Db::run('UPDATE products SET stock_text=? WHERE id=?',['Unknown',$product]);
    $numericXml=$build();ensureXml((string)$numericXml->shop->offers->offer->quantity==='7','File fallback stock is not thresholded');
    Db::run('UPDATE products SET stock_qty=5 WHERE id=?',[$product]);
    $save($numericMapping,['numeric_stock_rule'=>['enabled'=>true,'threshold'=>5,'value'=>0]]);
    $numericSettings=SettingsRepository::get();$numericSettings['skip_zero_stock']=1;
    $zero=FeedBuilder::buildFeed(['id'=>0,'token'=>bin2hex(random_bytes(16)),'warehouse_ids'=>$warehouseIds],$numericSettings,null,true);$paths[]=$zero['path'];
    ensureXml($zero['offers']===0,'Numeric replacement runs before zero-stock filter');
    $current=SettingsRepository::get();
    SettingsRepository::save('','','','',$numericMapping,$current['outlets_array'],false,true,true,2);
    $apiRows=FeedBuilder::apiStocks(['warehouse_ids'=>$warehouseIds]);
    ensureXml(count($apiRows)===1&&$apiRows[0]['offer_id']==='FILE-SKU'&&$apiRows[0]['stock']===0,'API exports file SKU and transformed zero despite YML zero filter');

    Db::run('UPDATE products SET stock_qty=NULL,stock_text=? WHERE id=?',['Более 5',$product]);
    $save($converted);
    $convertedTemplate=\App\YmlExport\XmlTemplate::fromSettings(SettingsRepository::get());
    \App\YmlExport\XmlTemplate::compile($convertedTemplate['text'],$convertedTemplate['variables']);
    $settingsBefore=SettingsRepository::get();
    $save($converted,['template'=>$convertedTemplate]);
    $xml=$build();$offer=$xml->shop->offers->offer;
    ensureXml((string)$offer['id']==='FILE-SKU'&&(string)$offer->quantity==='10','Converted template retains file ID and mapped stock');
    ensureXml(count($offer->outlets->outlet)===3,'Converted template retains real and virtual warehouses');
    $previewFeed=['id'=>0,'token'=>bin2hex(random_bytes(16)),'warehouse_ids'=>$warehouseIds];
    $preview=FeedBuilder::buildFeed($previewFeed,SettingsRepository::get(),null,true);$paths[]=$preview['path'];
    ensureXml(!is_file(FeedBuilder::path($previewFeed['token']))&&$preview['offers']===1,'Dry preview does not replace a live feed');

    $userTemplate=<<<'XML'
<yml_catalog>
    <shop>
        <offers>
{% для каждого товара %}
            <offer id="{{артикул}}">
                <outlets>
                    <outlet instock="{{остаток}}" warehouse_name="Москва ОБН"></outlet>
                </outlets>
            </offer>
{% конец %}
        </offers>
    </shop>
</yml_catalog>
XML;
    $userVars=[['name'=>'артикул','source'=>['type'=>'field','ref'=>'sku']],['name'=>'остаток','source'=>['type'=>'resolved','ref'=>'stock']]];
    $save($converted,['template'=>['text'=>$userTemplate,'variables'=>$userVars]]);
    $xml=$build();$offer=$xml->shop->offers->offer;
    ensureXml(count($xml->shop->offers->offer)===1,'User template emits one offer without warehouse repetition');
    ensureXml((string)$offer['id']==='FILE-SKU','User template ID');
    ensureXml((string)$offer->outlets->outlet['instock']==='10','User template maps stock directly in offer');
    ensureXml((string)$offer->outlets->outlet['warehouse_name']==='Москва ОБН','User template literal warehouse name');
    $diagnosticSettings=SettingsRepository::get();
    $empty=FeedBuilder::buildFeed(['id'=>0,'token'=>bin2hex(random_bytes(16)),'warehouse_ids'=>[]],$diagnosticSettings,null,true);$paths[]=$empty['path'];
    ensureXml($empty['offers']===0&&$empty['diagnostic']['stats']['selected']===0,'Explain link without selected warehouses');
    Db::run("UPDATE yx_matches SET status='suggested' WHERE product_id=?",[$product]);
    $empty=FeedBuilder::buildFeed($previewFeed,$diagnosticSettings,null,true);$paths[]=$empty['path'];
    ensureXml($empty['offers']===0&&str_contains($empty['diagnostic']['message'],'нет подтверждённых'),'Explain missing confirmations');
    Db::run("UPDATE yx_matches SET status='confirmed' WHERE product_id=?",[$product]);
    Db::run('UPDATE products SET stock_qty=0 WHERE id=?',[$product]);
    $diagnosticSettings['skip_zero_stock']=1;
    $empty=FeedBuilder::buildFeed($previewFeed,$diagnosticSettings,null,true);$paths[]=$empty['path'];
    ensureXml($empty['offers']===0&&str_contains($empty['diagnostic']['message'],'нулевым'),'Explain zero-stock filter');
    Db::run('UPDATE products SET stock_qty=NULL WHERE id=?',[$product]);

    $tree[]=['key'=>'custom','parent'=>'offer','kind'=>'block','name'=>'custom','enabled'=>true,'repeat'=>'once'];
    $tree[]=['key'=>'text','parent'=>'custom','kind'=>'tag','name'=>'label','enabled'=>true,'source'=>['type'=>'constant','ref'=>'Text <& " escaped']];
    $tree[]=['key'=>'attr','parent'=>'custom','kind'=>'attribute','name'=>'code','enabled'=>true,'source'=>['type'=>'column','ref'=>'A']];
    $tree[]=['key'=>'old','parent'=>'offer','kind'=>'tag','name'=>'oldprice','enabled'=>true,'source'=>['type'=>'field','ref'=>'oldprice']];
    $tree[]=['key'=>'shop_custom','parent'=>'_shop','kind'=>'tag','name'=>'custom_shop','enabled'=>true,'source'=>['type'=>'constant','ref'=>'shop value']];
    $tree[]=['key'=>'root_custom','parent'=>'_catalog','kind'=>'attribute','name'=>'version','enabled'=>true,'source'=>['type'=>'constant','ref'=>'2']];
    XmlMapping::validate($tree);$save($tree,['root_name'=>'catalog','shop_name'=>'store','offers_name'=>'items','offer_name'=>'item']);$xml=$build();
    $offer=$xml->store->items->item;
    ensureXml((string)$xml['version']==='2'&&(string)$xml->store->custom_shop==='shop value','Custom root attributes and shop tags');
    ensureXml($xml->getName()==='catalog'&&(string)$offer->custom['code']==='raw<&','Custom root, nesting and attributes');
    ensureXml((string)$offer->custom->label==='Text <& " escaped','XML escaping');
    ensureXml((string)$offer->oldprice==='10736','oldprice rule');
    Db::run('UPDATE products SET stock_qty=0 WHERE id=?',[$product]);$xml=$build();ensureXml((string)$xml->store->items->item->outlets->outlet[0]['instock']==='0','Zero catalog stock must not fall back');
    Db::run('UPDATE products SET stock_qty=NULL,stock_text=? WHERE id=?',['Unknown',$product]);$xml=$build();ensureXml((string)$xml->store->items->item->outlets->outlet[0]['instock']==='7','File stock fallback');
    Db::run('UPDATE yx_items SET stock_qty=NULL,stock_text=? WHERE warehouse_id=?',['Unknown',$warehouseIds[0]]);$xml=$build();ensureXml((string)$xml->store->items->item->outlets->outlet[0]['instock']==='2','Final stock fallback');
    $stableFeed=['id'=>0,'token'=>bin2hex(random_bytes(16)),'warehouse_ids'=>$warehouseIds];
    $stable=FeedBuilder::buildFeed($stableFeed);$paths[]=$stable['path'];$before=file_get_contents($stable['path']);
    foreach($tree as &$node) if($node['key']==='text') $node['source']['ref']="\x01";
    unset($node);$save($tree);
    $rejected=false;
    try {FeedBuilder::buildFeed($stableFeed);}catch(RuntimeException){$rejected=true;}
    ensureXml($rejected&&file_get_contents($stable['path'])===$before,'Invalid XML must preserve previous cached feed');
    echo "PASS: legacy upgrade, validation, source SKU, catalog stock mapping, virtual warehouses, nesting, attributes, escaping, custom envelope, fallback chain\n";
} finally {
    $pdo->rollBack();foreach($paths as $path) if(is_file($path))unlink($path);
}
