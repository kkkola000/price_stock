<?php
use App\Support\Csrf;
use App\Import\ColumnRef;
use App\YmlExport\{UploadRepository,WarehouseRepository,FeedRepository,Matcher,FeedBuilder};
?>
<?php $itemsPage=App\YmlExport\WarehouseItems::search($warehouseId,$listQuery,$listField,(int)($_GET['page']??1)); ?>
<div class="toolbar">
  <form method="get" class="form__actions match-search">
    <input type="hidden" name="tab" value="items"><input type="hidden" name="warehouse_id" value="<?= $warehouseId ?>">
    <input class="input" name="list_q" value="<?= e($listQuery) ?>" placeholder="Артикул или название" aria-label="Поиск товаров склада">
    <select class="input" name="list_field" aria-label="Поле поиска"><?php foreach(['all'=>'По всем полям','sku'=>'По артикулу','name'=>'По названию'] as $key=>$label): ?><option value="<?= $key ?>"<?= sel($key,$listField) ?>><?= $label ?></option><?php endforeach; ?></select>
    <button class="btn btn--primary">Найти</button>
  </form><span class="stat"><b><?= $itemsPage['total'] ?></b> позиций</span>
</div>
<div class="card table-wrap"><table class="table"><thead><tr><th>Наименование / артикул</th><th>Остаток</th><th>Цена</th><th>Старая цена</th><th>Мин. цена</th><th>Сопоставление</th><th></th></tr></thead><tbody>
<?php foreach($itemsPage['items'] as $item): ?>
<tr><td><b><?= e($item['name']) ?></b><br><span class="mono small muted"><?= e($item['sku']) ?></span></td><td><?= e($item['stock_text']??'—') ?></td><td><?= $item['price']!==null?e((string)$item['price']):'—' ?></td><td><?= $item['oldprice']!==null?e((string)$item['oldprice']):'—' ?></td><td><?= $item['min_price']!==null?e((string)$item['min_price']):'—' ?></td>
<td><span class="pill <?= $item['match_status']==='confirmed'?'pill--ok':'pill--running' ?>"><?= e(['confirmed'=>'Подтверждено','suggested'=>'Проверить','unmatched'=>'Без пары','rejected'=>'Отклонено'][$item['match_status']??'']??'Без пары') ?></span><?php if($item['product_name']!==null): ?><div class="small muted"><?= e($item['product_name']) ?></div><?php endif; ?></td>
<td><?php if($item['match_id']!==null): ?><a class="btn btn--small btn--ghost" href="yml.php?tab=matches&warehouse_id=<?= $warehouseId ?>&match_id=<?= (int)$item['match_id'] ?>"><?= $item['match_status']==='confirmed'?'Другой':'Сопоставить' ?></a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if($itemsPage['items']===[]): ?><tr><td colspan="7" class="muted">Товары не найдены.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="form__actions" aria-label="Страницы товаров">
<?php foreach(['Назад'=>$itemsPage['page']-1,'Далее'=>$itemsPage['page']+1] as $label=>$pageNumber): if($pageNumber<1||$pageNumber>$itemsPage['pages'])continue; ?><a class="btn btn--ghost" href="yml.php?<?= e(http_build_query(['tab'=>'items','warehouse_id'=>$warehouseId,'page'=>$pageNumber,'list_q'=>$listQuery,'list_field'=>$listField])) ?>"><?= $label ?></a><?php endforeach; ?>
<span class="muted"><?= $itemsPage['page'] ?> / <?= $itemsPage['pages'] ?></span></nav>
