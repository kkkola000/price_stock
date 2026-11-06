<?php
declare(strict_types=1);
namespace App\YmlExport;
use App\Support\Db;

final class WarehouseItems
{
    /** All file positions, including unmatched/rejected ones. Search and pagination stay warehouse-scoped. */
    public static function search(int $warehouseId,string $query='',string $field='all',int $page=1): array
    {
        $where='i.warehouse_id=?';$params=[$warehouseId];
        if(trim($query)!==''){
            $columns=match($field){'sku'=>['i.sku'],'name'=>['i.name'],default=>['i.sku','i.name','i.stock_text','i.price','i.oldprice','i.min_price']};
            $where.=' AND ('.implode(' OR ',array_map(static fn($c)=>$c." LIKE ? ESCAPE '!'",$columns)).')';
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],trim($query)).'%';foreach($columns as $_)$params[]=$pattern;
        }
        $total=(int)Db::scalar('SELECT COUNT(*) FROM yx_items i WHERE '.$where,$params);
        $pages=max(1,(int)ceil($total/50));$page=max(1,min($pages,$page));
        $items=Db::all("SELECT i.*,m.id AS match_id,m.status AS match_status,p.name AS product_name,p.sku AS product_sku_live
            FROM yx_items i LEFT JOIN yx_matches m ON m.warehouse_id=i.warehouse_id AND m.item_sku=i.sku
            LEFT JOIN products p ON p.id=m.product_id WHERE ".$where.' ORDER BY i.id LIMIT 50 OFFSET '.(($page-1)*50),$params);
        return compact('items','total','page','pages');
    }
}
