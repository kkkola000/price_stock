<?php
$source=(array)($variable['source']??[]);
$input=($source['type']??'')==='warehouse'?['type'=>'warehouse','ref'=>'name','extra'=>'']: \App\YmlExport\XmlMapping::formSource($source);
?>
<tr data-template-variable>
  <td><input class="input" name="var_name[<?= e((string)$i) ?>]" value="<?= e($variable['name']) ?>" aria-label="Имя переменной" placeholder="например, артикул"></td>
  <td><select class="input" name="var_type[<?= e((string)$i) ?>]" aria-label="Источник переменной"><?php foreach(['file'=>'Файл','catalog'=>'Витрина','constant'=>'Константа','warehouse'=>'Текущий склад'] as $key=>$label): ?><option value="<?= e($key) ?>"<?= sel($key,$input['type']) ?>><?= e($label) ?></option><?php endforeach; ?></select></td>
  <td><input class="input" name="var_ref[<?= e((string)$i) ?>]" value="<?= e($input['ref']) ?>" aria-label="Поле или константа"><input class="input template-extra" name="var_extra[<?= e((string)$i) ?>]" value="<?= e($input['extra']) ?>" aria-label="Буква столбца" hidden></td>
  <td><div class="form__actions"><button type="button" class="btn btn--small btn--ghost" data-template-insert>Вставить</button><button type="button" class="btn btn--small btn--danger" data-template-remove aria-label="Удалить переменную">×</button></div></td>
</tr>
