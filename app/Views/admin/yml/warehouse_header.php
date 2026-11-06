<?php
use App\Support\Csrf;
use App\Import\ColumnRef;
use App\YmlExport\{UploadRepository,WarehouseRepository,FeedRepository,Matcher,FeedBuilder};
?>
<div class="yml-warehouse-head">
  <a class="breadcrumbs" href="yml.php?tab=warehouses">← Источники</a>
  <div class="toolbar"><h2 class="a-subtitle"><?= e($warehouseContext['name']) ?></h2><span class="pill <?= $warehouseContext['is_active']?'pill--ok':'pill--disabled' ?>"><?= $warehouseContext['is_active']?'Активен':'Отключён' ?></span></div>
  <nav class="tabs yml-warehouse-tabs" aria-label="Страницы склада">
    <a href="yml.php?tab=warehouses&wh_form=<?= $warehouseId ?>" class="<?= $tab==='warehouses'?'is-active':'' ?>">Настройки</a>
    <a href="yml.php?tab=items&warehouse_id=<?= $warehouseId ?>" class="<?= $tab==='items'?'is-active':'' ?>">Товары <span class="pill pill--never"><?= (int)$warehouseContext['items_count'] ?></span></a>
    <a href="yml.php?tab=matches&warehouse_id=<?= $warehouseId ?>" class="<?= $tab==='matches'?'is-active':'' ?>">Сопоставление <span class="pill <?= $pendingCount?'pill--running':'pill--ok' ?>"><?= $pendingCount ?></span></a>
  </nav>
</div>
