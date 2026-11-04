<?php
declare(strict_types=1);
namespace App\Models;
use App\Support\{Db,PurchaseAccess};

final class OrderPurchasePrices
{
    /** Costs never come from the browser's order snapshot. */
    public static function forIds(array $ids): array
    {
        if(!PurchaseAccess::allowed())throw new \DomainException('Введите код для просмотра закупочных цен.');
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id):bool=>$id>0)));
        if(count($ids)>10000)throw new \InvalidArgumentException('Слишком много товаров в заказе.');
        if($ids===[])return [];
        $rows=Db::all('SELECT p.id,p.purchase_price,p.purchase_price_text,p.currency FROM products p JOIN sources s ON s.id=p.source_id WHERE s.is_active=1 AND p.id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
        $prices=[];
        foreach($rows as $row)$prices[(string)$row['id']]=$row['purchase_price']===null?'—':price_label((string)($row['purchase_price_text']??''),(float)$row['purchase_price'],(string)$row['currency']);
        return $prices;
    }
}
