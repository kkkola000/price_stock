<?php
declare(strict_types=1);
namespace App\Models;
use App\Support\{Db,PurchaseAccess};

final class OrderPurchasePrices
{
    /** Costs never come from the browser's order snapshot. Amounts use integer minor units. */
    public static function detailsForIds(array $ids): array
    {
        if(!PurchaseAccess::allowed())throw new \DomainException('Введите код для просмотра закупочных цен.');
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));
        if(count($ids)>10000)throw new \InvalidArgumentException('Слишком много товаров в заказе.');
        if($ids===[])return [];
        $rows=Db::all('SELECT p.id,p.purchase_price,p.currency FROM products p JOIN sources s ON s.id=p.source_id WHERE s.is_active=1 AND p.id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
        $details=[];
        foreach($rows as $row){
            $cents=$row['purchase_price']===null?null:(int)round((float)$row['purchase_price']*100);
            $details[(string)$row['id']]=['cents'=>$cents,'currency'=>(string)$row['currency'],'symbol'=>currency_symbol((string)$row['currency']),'label'=>$cents===null?'—':self::label($cents,(string)$row['currency'])];
        }
        return $details;
    }
    public static function forIds(array $ids): array
    {
        return array_map(static fn(array $detail):string=>$detail['label'],self::detailsForIds($ids));
    }
    public static function label(int $cents,string $currency): string
    {
        return number_format($cents/100,$cents%100===0?0:2,'.',' ').' '.currency_symbol($currency);
    }
    public static function csvNumber(int $cents): string
    {
        return number_format($cents/100,$cents%100===0?0:2,',','');
    }
    /** Separate totals for different currencies; missing prices are never counted as zero. */
    public static function totals(array $items,array $details): array
    {
        $totals=[];$missing=0;
        foreach($items as $item){
            $detail=$details[(string)(int)$item['id']]??null;
            if($detail===null||$detail['cents']===null){$missing++;continue;}
            $currency=$detail['currency'];
            $totals[$currency]=($totals[$currency]??0)+$detail['cents']*max(1,min(9999,(int)($item['qty']??1)));
        }
        return ['totals'=>$totals,'missing'=>$missing];
    }
}
