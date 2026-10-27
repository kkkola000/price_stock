<?php
declare(strict_types=1);
namespace App\YmlExport;
use InvalidArgumentException;
use XMLWriter;

/** Flat, ordered tree stored in the existing mapping JSON; legacy mappings remain readable. */
final class XmlMapping
{
    private const ROOTS = ['offer','_catalog','_shop','_offers'];
    public static function upgrade(array $rules, array $outlets): array
    {
        $nodes=[];
        foreach ($rules as $i=>$rule) {
            if (!isset($rule['key']) && trim((string)($rule['name']??''))==='') continue;
            $rule['key']=(string)($rule['key'] ?? 'legacy_'.$i);
            $rule['parent']=(string)($rule['parent'] ?? 'offer');
            $rule['repeat']=(string)($rule['repeat'] ?? 'once');
            $nodes[]=$rule;
        }
        // A saved tree owns its own warehouse blocks; do not append legacy outlets twice.
        $isTree = isset($rules[0]['key']);
        if (!$isTree && !empty($outlets['enabled'])) {
            $nodes[]=['key'=>'stock_block','parent'=>'offer','kind'=>'block','name'=>$outlets['parent']??'outlets','enabled'=>true,'repeat'=>'once'];
            $nodes[]=['key'=>'stock_row','parent'=>'stock_block','kind'=>'block','name'=>$outlets['tag']??'outlet','enabled'=>true,'repeat'=>'warehouse'];
            $nodes[]=['key'=>'stock_qty','parent'=>'stock_row','kind'=>'attribute','name'=>$outlets['stock_attr']??'instock','enabled'=>true,'source'=>['type'=>'resolved','ref'=>'stock']];
            $nodes[]=['key'=>'stock_name','parent'=>'stock_row','kind'=>'attribute','name'=>$outlets['name_attr']??'warehouse_name','enabled'=>true,'source'=>['type'=>'warehouse','ref'=>'name']];
        }
        foreach ($nodes as &$node) $node['repeat']=$node['repeat']??'once';
        unset($node);
        return $nodes;
    }

    public static function validate(array $nodes): void
    {
        if(count($nodes)>200) throw new InvalidArgumentException('В конструкторе допускается не более 200 элементов.');
        $byKey=[];
        foreach($nodes as $node) {
            $key=(string)($node['key']??'');
            if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/',$key)||in_array($key,self::ROOTS,true)||isset($byKey[$key])) throw new InvalidArgumentException('Некорректный или повторяющийся идентификатор элемента.');
            if(!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/',(string)($node['name']??''))) throw new InvalidArgumentException('Некорректное имя XML: '.($node['name']??''));
            if(!in_array($node['kind']??'', ['tag','attribute','block'],true)) throw new InvalidArgumentException('Неизвестный вид элемента.');
            if(!in_array($node['repeat']??'once',['once','warehouse'],true)) throw new InvalidArgumentException('Неизвестный режим повторения.');
            if(($node['repeat']??'once')==='warehouse'&&$node['kind']!=='block') throw new InvalidArgumentException('Повторять по складам можно только блок.');
            $byKey[$key]=$node;
        }
        $attributes=[];$hasId=false;
        foreach($nodes as $node) {
            $parent=(string)($node['parent']??'offer');$seen=[$node['key']=>true];$depth=0;$warehouse=($node['repeat']??'once')==='warehouse';
            $active=!empty($node['enabled']);
            while(!in_array($parent,self::ROOTS,true)) {
                if(isset($seen[$parent])||!isset($byKey[$parent])||++$depth>16) throw new InvalidArgumentException('Цикл, отсутствующий родитель или слишком большая вложенность XML.');
                $seen[$parent]=true;$p=$byKey[$parent];
                if($p['kind']!=='block') throw new InvalidArgumentException('Родителем может быть только вложенный блок.');
                if(($p['repeat']??'once')==='warehouse') {
                    if($warehouse) throw new InvalidArgumentException('Нельзя вкладывать повторение по складам в другое повторение по складам.');
                    $warehouse=true;
                }
                $active=$active&&!empty($p['enabled']);$parent=$p['parent']??'offer';
            }
            if($node['kind']==='attribute'&&$active) {
                $attr=($node['parent']??'offer').'/'.$node['name'];
                if(isset($attributes[$attr])) throw new InvalidArgumentException('Повторяющийся атрибут: '.$node['name']);
                $attributes[$attr]=true;
                if(($node['parent']??'offer')==='offer'&&$node['name']==='id') $hasId=true;
            }
            $source=$node['source']??[];
            if ($parent!=='offer' && ($warehouse || ($node['kind']!=='block' && ($source['type']??'')!=='constant'))) throw new InvalidArgumentException('Вне товара можно использовать только постоянные значения и обычные блоки.');
            if ($node['kind']!=='block' && !in_array($source['type']??'', ['field','column','catalog','constant','resolved','warehouse'], true)) throw new InvalidArgumentException('Неизвестный источник значения.');
            if($node['kind']!=='block'&&($source['type']??'')==='column'&&!preg_match('/^[A-Za-z]{1,3}$/',(string)($source['ref']??''))) throw new InvalidArgumentException('Столбец файла указывается буквой: A, B, AA.');
            if($node['kind']!=='block'&&in_array($source['type']??'', ['warehouse','resolved'],true)&&!$warehouse&&($source['ref']??'')!=='total_stock') throw new InvalidArgumentException('Источник склада доступен внутри блока «Для каждого склада».');
        }
        if(!$hasId) throw new InvalidArgumentException('У offer нужен включённый атрибут id.');
    }

    /** Attributes always precede children, independently of the displayed row order. */
    public static function write(XMLWriter $xml, array $nodes, callable $resolve, array $warehouses, string $parent='offer', ?array $warehouse=null, bool $filterOldPrice=false): void
    {
        $children=array_values(array_filter($nodes,static fn($n)=>!empty($n['enabled'])&&($n['parent']??'offer')===$parent));
        $values=[];
        foreach($children as $node) if($node['kind']!=='block') $values[$node['key']]=$resolve($node['source']??[],$warehouse);
        $price=null;
        foreach($children as $node) if($node['kind']==='tag'&&$node['name']==='price'&&is_numeric($values[$node['key']])) $price=(float)$values[$node['key']];
        foreach($children as $node) if($node['kind']==='attribute'&&$values[$node['key']]!=='') $xml->writeAttribute($node['name'],$values[$node['key']]);
        foreach($children as $node) {
            if($node['kind']==='attribute') continue;
            if($node['kind']==='block') {
                $contexts=($node['repeat']??'once')==='warehouse'?$warehouses:[$warehouse];
                foreach($contexts as $context) {
                    $xml->startElement($node['name']);
                    self::write($xml,$nodes,$resolve,$warehouses,$node['key'],$context,$filterOldPrice);
                    $xml->endElement();
                }
            } else {
                $value=$values[$node['key']];
                if($value==='') continue;
                if($filterOldPrice&&$node['name']==='oldprice'&&$price!==null&&is_numeric($value)&&(float)$value<=$price) continue;
                $xml->writeElement($node['name'],$value);
            }
        }
    }
}
