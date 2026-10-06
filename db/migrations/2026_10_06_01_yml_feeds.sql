-- Несколько ссылок на YML-фид: у каждой свой токен и свой набор складов.

CREATE TABLE IF NOT EXISTS yx_feeds (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(190) NOT NULL,
  token        CHAR(32)     NOT NULL,
  built_at     DATETIME     NULL,
  built_offers INT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_yx_feeds_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS yx_feed_warehouses (
  feed_id      INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (feed_id, warehouse_id),
  KEY idx_yx_fw_wh (warehouse_id),
  CONSTRAINT fk_yx_fw_feed FOREIGN KEY (feed_id) REFERENCES yx_feeds (id) ON DELETE CASCADE,
  CONSTRAINT fk_yx_fw_wh FOREIGN KEY (warehouse_id) REFERENCES yx_warehouses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Существующая ссылка переезжает в таблицу фидов как «Основной фид»
-- вместе с текущим выбором складов (in_feed).
INSERT INTO yx_feeds (name, token, built_at, built_offers)
SELECT 'Основной фид', token, built_at, built_offers FROM yx_feed_settings WHERE id = 1
ON DUPLICATE KEY UPDATE name = name;

INSERT IGNORE INTO yx_feed_warehouses (feed_id, warehouse_id)
SELECT f.id, w.id
FROM yx_feeds f
JOIN yx_feed_settings s ON s.id = 1 AND s.token = f.token
JOIN yx_warehouses w ON w.in_feed = 1;
