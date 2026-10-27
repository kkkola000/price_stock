-- Primary link may have been created before any warehouse existed.
-- Explicit selections and custom links keep their existing membership.
ALTER TABLE yx_feeds
    ADD COLUMN warehouse_mode ENUM('selected','all') NOT NULL DEFAULT 'selected';

UPDATE yx_feeds f
JOIN yx_feed_settings s ON s.id = 1 AND s.token = f.token
LEFT JOIN yx_feed_warehouses fw ON fw.feed_id = f.id
SET f.warehouse_mode = 'all', f.built_at = NULL, f.built_offers = 0
WHERE fw.feed_id IS NULL;
