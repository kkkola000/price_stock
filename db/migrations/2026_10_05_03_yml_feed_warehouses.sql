-- Галочка «склад в фиде»: выбор складов, попадающих в YML, на вкладке «Готовый фид».

ALTER TABLE yx_warehouses
  ADD COLUMN in_feed TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Включать склад в YML-фид' AFTER default_stock;
