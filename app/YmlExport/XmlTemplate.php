<?php
declare(strict_types=1);
namespace App\YmlExport;
use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use XMLWriter;

/** Small XML-only template language. No PHP, expressions, entities or external includes. */
final class XmlTemplate
{
    private const LOOP='yml-template-loop';
    public static function sourceFromForm(string $type,string $ref,string $extra=''): array
    {
        if($type==='warehouse')return ['type'=>'warehouse','ref'=>'name'];
        if($type==='file'&&$ref==='warehouse_name')return ['type'=>'field','ref'=>'warehouse_name'];
        return XmlMapping::sourceFromForm($type,$ref,$extra);
    }

    public static function compile(string $text, array $variables): DOMDocument
    {
        if(strlen($text)>100000) throw new InvalidArgumentException('Шаблон слишком большой (максимум 100 КБ).');
        $known=['дата_фида'=>['type'=>'system','ref'=>'date']];
        if(count($variables)>200) throw new InvalidArgumentException('Допускается не более 200 переменных.');
        foreach($variables as $variable) {
            $name=(string)($variable['name']??'');
            if(!preg_match('/^[\p{L}_][\p{L}\p{N}_-]*$/u',$name)||isset($known[$name])) throw new InvalidArgumentException('Некорректная или повторяющаяся переменная: '.$name);
            $source=$variable['source']??[];
            if(!in_array($source['type']??'', ['field','column','catalog','constant','warehouse','resolved'],true)) throw new InvalidArgumentException('Неизвестный источник переменной: '.$name);
            if(($source['type']??'')==='column'&&!preg_match('/^[A-Za-z]{1,3}$/',(string)($source['ref']??''))) throw new InvalidArgumentException('Для '.$name.' укажите букву столбца A, B, AA.');
            $known[$name]=$source;
        }
        if(preg_match('/<!\s*(DOCTYPE|ENTITY)|<\s*\/?\s*yml-template-loop\b|<\?(?!xml\s)/i',$text)) throw new InvalidArgumentException('DOCTYPE, ENTITY, инструкции и служебные теги в шаблоне запрещены.');
        $stack=[];$products=0;
        $xml=preg_replace_callback('/\{%\s*(.*?)\s*%\}/us',static function(array $m)use(&$stack,&$products):string {
            $instruction=trim($m[1]);
            if($instruction==='конец') {
                if($stack===[]) throw new InvalidArgumentException('Лишний блок {% конец %}.');
                array_pop($stack);return '</'.self::LOOP.'>';
            }
            $type=match($instruction){'для каждого товара'=>'product','для каждого склада'=>'warehouse',default=>null};
            if($type===null) throw new InvalidArgumentException('Неизвестная инструкция: '.$instruction);
            if($type==='product') {
                if($stack!==[]||++$products>1) throw new InvalidArgumentException('В шаблоне нужен один блок повторения товаров без вложенного повторения товаров.');
            } elseif(!in_array('product',$stack,true)||in_array('warehouse',$stack,true)) throw new InvalidArgumentException('Повторение складов допускается только внутри повторения товаров.');
            $stack[]=$type;return '<'.self::LOOP.' kind="'.$type.'">';
        },$text);
        if($stack!==[]) throw new InvalidArgumentException('Не закрыт блок повторения: добавьте {% конец %}.');
        if($products!==1) throw new InvalidArgumentException('Добавьте один блок {% для каждого товара %} ... {% конец %}.');
        if(str_contains((string)$xml,'{%')||str_contains((string)$xml,'%}')) throw new InvalidArgumentException('Незавершённая инструкция повторения.');
        $old=libxml_use_internal_errors(true);libxml_clear_errors();$doc=new DOMDocument();
        try {
            if(!$doc->loadXML((string)$xml,LIBXML_NONET)) {
                $error=libxml_get_last_error();throw new InvalidArgumentException('Ошибка XML в строке '.($error->line??'?').': '.trim($error->message??'некорректная структура'));
            }
        } finally {libxml_clear_errors();libxml_use_internal_errors($old);}
        $realProducts=0;foreach($doc->getElementsByTagName(self::LOOP) as $loop)if($loop->getAttribute('kind')==='product')$realProducts++;
        if($realProducts!==1)throw new InvalidArgumentException('Повторение товаров должно быть XML-блоком, а не частью комментария или CDATA.');
        if($doc->documentElement?->tagName===self::LOOP) throw new InvalidArgumentException('Повторение должно находиться внутри корневого элемента XML.');
        $walk=static function(DOMNode $node, bool $product=false,bool $warehouse=false,int $depth=0)use(&$walk,$known):void {
            if($depth>40) throw new InvalidArgumentException('Слишком большая вложенность XML.');
            if($node instanceof DOMElement && $node->tagName===self::LOOP) {
                if($node->getAttribute('kind')==='product') $product=true; else $warehouse=true;
            } else {
                $values=$node instanceof DOMElement ? array_map(static fn($a)=>$a->value,iterator_to_array($node->attributes)) : ($node->nodeType===XML_TEXT_NODE||$node->nodeType===XML_CDATA_SECTION_NODE?[$node->nodeValue]:[]);
                foreach($values as $value) {
                    preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/u',(string)$value,$matches);
                    foreach($matches[1] as $name) {
                        $name=trim($name);
                        if(!isset($known[$name])) throw new InvalidArgumentException('Строка '.$node->getLineNo().': неизвестная переменная «'.$name.'».');
                        $type=$known[$name]['type'];
                        if(!in_array($type,['constant','system'],true)&&!$product) throw new InvalidArgumentException('Переменная «'.$name.'» должна быть внутри повторения товаров.');
                        if($type==='warehouse'&&!$warehouse) throw new InvalidArgumentException('Переменная «'.$name.'» доступна только в повторении складов.');
                    }
                    $remaining=preg_replace('/\{\{\s*[^{}]+?\s*\}\}/u','',(string)$value);
                    if(str_contains((string)$remaining,'{{')||str_contains((string)$remaining,'}}')) throw new InvalidArgumentException('Строка '.$node->getLineNo().': некорректная переменная.');
                }
            }
            foreach($node->childNodes as $child) $walk($child,$product,$warehouse,$depth+1);
        };
        $walk($doc);return $doc;
    }

    public static function write(XMLWriter $xml, DOMDocument $doc, array $variables, callable $eachProduct, bool $oldpriceOnlyHigher): void
    {
        $sources=array_column($variables,'source','name');
        $interpolate=static function(string $text, ?callable $resolve, ?array $warehouse)use($sources):string {
            return (string)preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/u',static function(array $m)use($sources,$resolve,$warehouse):string {
                $name=trim($m[1]);if($name==='дата_фида')return date('Y-m-d H:i');
                $source=$sources[$name];
                return ($source['type']??'')==='constant'?(string)($source['ref']??''):($resolve===null?'':$resolve($source,$warehouse));
            },$text);
        };
        $emit=static function(DOMNode $node, ?callable $resolve=null,array $warehouses=[],?array $warehouse=null)use(&$emit,$xml,$eachProduct,$interpolate,$oldpriceOnlyHigher):void {
            if($node instanceof DOMElement) {
                if($node->tagName===self::LOOP) {
                    if($node->getAttribute('kind')==='product') $eachProduct(static function(callable $resolver,array $stocks)use($node,&$emit):void {foreach($node->childNodes as $child)$emit($child,$resolver,$stocks);});
                    else foreach($warehouses as $stock) foreach($node->childNodes as $child)$emit($child,$resolve,$warehouses,$stock);
                    return;
                }
                $hasElements=false;foreach($node->childNodes as $child) if($child instanceof DOMElement)$hasElements=true;
                $text=$interpolate($node->textContent,$resolve,$warehouse);
                // Preserve the previous constructor's omission of absent mapped values.
                if(!$hasElements&&preg_match('/^\s*\{\{[^{}]+\}\}\s*$/u',$node->textContent)&&$text==='')return;
                if($oldpriceOnlyHigher&&$node->tagName==='oldprice'&&is_numeric($text)) {
                    foreach($node->parentNode->childNodes as $sibling) if($sibling instanceof DOMElement&&$sibling->tagName==='price') {
                        $price=$interpolate($sibling->textContent,$resolve,$warehouse);
                        if(is_numeric($price)&&(float)$text<=(float)$price)return;
                    }
                }
                $xml->startElement($node->tagName);
                foreach($node->attributes as $attribute) {
                    $value=$interpolate($attribute->value,$resolve,$warehouse);
                    if($value!==''||!str_contains($attribute->value,'{{'))$xml->writeAttribute($attribute->name,$value);
                }
                foreach($node->childNodes as $child)$emit($child,$resolve,$warehouses,$warehouse);
                $xml->endElement();
            } elseif($node->nodeType===XML_TEXT_NODE||$node->nodeType===XML_CDATA_SECTION_NODE) $xml->text($interpolate((string)$node->nodeValue,$resolve,$warehouse));
            elseif($node->nodeType===XML_COMMENT_NODE)$xml->writeComment((string)$node->nodeValue);
        };
        $emit($doc->documentElement);
    }

    /** Convert the existing mapping to a complete editable template without writing settings. */
    public static function fromSettings(array $settings): array
    {
        $nodes=$settings['offer_mapping_array'];$out=$settings['outlets_array'];$variables=[];$used=['дата_фида'=>true];
        $names=['id'=>'артикул','price'=>'цена','oldprice'=>'старая_цена','min_price'=>'мин_цена','instock'=>'остаток','warehouse_name'=>'склад','name'=>'название'];
        $refs=[];
        foreach($nodes as $node) if($node['kind']!=='block'&&!empty($node['enabled'])) {
            $base=$names[$node['name']]??preg_replace('/[^\p{L}\p{N}_-]/u','_',$node['name']);
            if(!preg_match('/^[\p{L}_]/u',(string)$base))$base='поле_'.$base;
            $name=$base;$n=2;while(isset($used[$name]))$name=$base.'_'.$n++;
            $source=$node['source'];
            if(($source['type']??'')==='warehouse') {
                $parent=$node['parent'];$inWarehouse=false;$visited=[];$byKey=array_column($nodes,null,'key');
                while(isset($byKey[$parent])&&!isset($visited[$parent])) {
                    $visited[$parent]=true;$ancestor=$byKey[$parent];
                    if(($ancestor['repeat']??'once')==='warehouse')$inWarehouse=true;
                    $parent=$ancestor['parent'];
                }
                if(!$inWarehouse)$source=['type'=>'field','ref'=>'warehouse_name'];
            }
            $used[$name]=true;$variables[]=['name'=>$name,'source'=>$source];$refs[$node['key']]='{{'.$name.'}}';
        }
        $encode=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_XML1,'UTF-8');
        $contents=static function(string $parent,int $depth)use(&$contents,$nodes,$refs):string {
            $text='';foreach($nodes as $node)if(!empty($node['enabled'])&&$node['parent']===$parent&&$node['kind']!=='attribute') {
                $pad=str_repeat('  ',$depth);$loop=$node['kind']==='block'&&($node['repeat']??'once')==='warehouse';
                if($loop)$text.=$pad."{% для каждого склада %}\n";
                $text.=$pad.'<'.$node['name'];
                foreach($nodes as $attr)if(!empty($attr['enabled'])&&$attr['parent']===$node['key']&&$attr['kind']==='attribute')$text.=' '.$attr['name'].'="'.$refs[$attr['key']].'"';
                $text.='>';
                if($node['kind']==='block')$text.="\n".$contents($node['key'],$depth+1).$pad;else $text.=$refs[$node['key']];
                $text.='</'.$node['name'].">\n";if($loop)$text.=$pad."{% конец %}\n";
            }return $text;
        };
        $start=static function(string $name,string $parent,int $depth)use($nodes,$refs):string {
            $text=str_repeat('  ',$depth).'<'.$name;
            foreach($nodes as $attr)if(!empty($attr['enabled'])&&$attr['parent']===$parent&&$attr['kind']==='attribute')$text.=' '.$attr['name'].'="'.$refs[$attr['key']].'"';
            if($parent==='_catalog'&&!preg_match('/\sdate="/',$text))$text.=' date="{{дата_фида}}"';
            return $text.">\n";
        };
        $root=$out['root_name']??'yml_catalog';$shop=$out['shop_name']??'shop';$offers=$out['offers_name']??'offers';$offer=$out['offer_name']??'offer';
        $text=$start($root,'_catalog',0).$contents('_catalog',1).$start($shop,'_shop',1).$contents('_shop',2);
        foreach(['shop_name'=>'name','shop_company'=>'company','shop_url'=>'url']as $field=>$tag)if($settings[$field]!=='')$text.='    <'.$tag.'>'.$encode($settings[$field]).'</'.$tag.">\n";
        if($settings['currency']!=='')$text.='    <currencies><currency id="'.$encode($settings['currency']).'" rate="1"/></currencies>' . "\n";
        $text.=$start($offers,'_offers',2).$contents('_offers',3)."      {% для каждого товара %}\n".$start($offer,'offer',3).$contents('offer',4).'      </'.$offer.">\n      {% конец %}\n    </".$offers.">\n  </".$shop.">\n</".$root.">";
        // Remove unused definitions belonging to disabled branches.
        $variables=array_values(array_filter($variables,static fn($v)=>str_contains($text,'{{'.$v['name'].'}}')));
        return ['text'=>$text,'variables'=>$variables];
    }
}
