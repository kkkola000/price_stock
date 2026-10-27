<?php
/** @var array $node @var int|string $i */
$src=(array)($node['source']??[]);
?>
<tr data-xml-node>
  <td><input type="hidden" name="map_key[<?= e((string)$i) ?>]" value="<?= e($node['key']) ?>"><input type="checkbox" name="map_enabled[<?= e((string)$i) ?>]"<?= chk(!empty($node['enabled'])) ?> aria-label="Включить элемент"></td>
  <td>
    <select class="input" name="map_kind[<?= e((string)$i) ?>]" aria-label="Тип элемента">
      <?php foreach (['tag'=>'Тег','attribute'=>'Атрибут','block'=>'Вложенный блок'] as $key=>$label): ?><option value="<?= e($key) ?>"<?= sel($key,$node['kind']) ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <select class="input" name="map_repeat[<?= e((string)$i) ?>]" aria-label="Повторение">
      <option value="once"<?= sel('once',$node['repeat']??'once') ?>>Один раз</option><option value="warehouse"<?= sel('warehouse',$node['repeat']??'once') ?>>Для каждого склада</option>
    </select>
  </td>
  <td><input class="input mono" name="map_name[<?= e((string)$i) ?>]" value="<?= e($node['name']) ?>" aria-label="Имя XML"></td>
  <td><select class="input" name="map_parent[<?= e((string)$i) ?>]" aria-label="Родитель">
    <option value="offer"<?= sel('offer',$node['parent']??'offer') ?>>Элемент товара (offer)</option>
    <?php foreach (['_catalog'=>'Корневой элемент','_shop'=>'Магазин (shop)','_offers'=>'Список товаров (offers)'] as $key=>$label): ?><option value="<?= e($key) ?>"<?= sel($key,$node['parent']??'offer') ?>><?= e($label) ?></option><?php endforeach; ?>
    <?php foreach($xmlNodes as $parent): if($parent['kind']!=='block'||$parent['key']===$node['key']) continue; ?>
      <option value="<?= e($parent['key']) ?>"<?= sel($parent['key'],$node['parent']??'offer') ?>><?= e($parent['name']) ?></option>
    <?php endforeach; ?>
  </select></td>
  <td><select class="input" name="map_type[<?= e((string)$i) ?>]" aria-label="Источник">
    <?php foreach($sourceTypeLabels as $key=>$label): ?><option value="<?= e($key) ?>"<?= sel($key,$src['type']??'constant') ?>><?= e($label) ?></option><?php endforeach; ?>
  </select><input class="input" name="map_ref[<?= e((string)$i) ?>]" value="<?= e((string)($src['ref']??'')) ?>" aria-label="Поле или значение" placeholder="sku · price · A · текст"></td>
  <td><div class="xml-node-actions"><button class="btn btn--small btn--ghost" type="button" data-xml-move="up" aria-label="Выше">↑</button><button class="btn btn--small btn--ghost" type="button" data-xml-move="down" aria-label="Ниже">↓</button><button class="btn btn--small btn--danger" type="button" data-xml-remove aria-label="Удалить элемент">×</button></div></td>
</tr>
