-- Phase 11 — "Digital Codes" removed from the storefront and the admin panel.
--
-- WHY THIS FILE EXISTS
-- The Digital Codes feature (digital.php, admin/digital-codes.php, code
-- delivery after payment approval) has been deleted from the application. The
-- pages, routes, permissions, notification event and order-timeline step are
-- gone in code, so nothing in the shop reads them any more. Three pieces of
-- leftover DATA still advertise the feature on a live store, and this file
-- removes exactly those:
--
--   1. the "Digital codes delivered the moment payment clears" homepage slider
--      slide (it would otherwise link to a product search nobody can buy from);
--   2. the `code_delivered` rows in the notification event matrix (the event can
--      never fire again, so the switch would sit there doing nothing);
--   3. the seeded site tagline / description, but ONLY when they still hold the
--      original installer text word for word — copy the owner wrote is never
--      overwritten.
--
-- WHAT THIS FILE DELIBERATELY DOES NOT DO
--   * no DROP TABLE `product_codes`
--   * no DROP of `orders.has_digital` / `orders.codes_delivered`
--   * no UPDATE and no DELETE on `products` — the existing `product_type =
--     'digital'` rows are left exactly as they are for the owner to decide
--   * no DELETE on `notification_logs` — past delivery records stay as history
--   * no change to `orders`, `order_items` or `payments`
-- The application now refuses to sell a non-physical product (cart, checkout and
-- the product page all treat it as not orderable online), so leaving those rows
-- in place cannot create an order the store is unable to fulfil.
--
-- HOW TO RUN IT (safe, reversible)
--   BACK UP FIRST. At minimum the four tables below plus the product rows:
--
--     mysqldump -u USER -p DBNAME \
--       promo_slides notifications settings seo_pages products product_codes \
--       orders order_items notification_logs \
--       > backup-before-digital-codes-removal-$(date +%F).sql
--
--   Then either paste the statements below into phpMyAdmin, or run:
--
--     mysql -u USER -p DBNAME < 2026-10-remove-digital-codes.sql
--
--   ROLLBACK: restore from the dump above. Every statement here only removes or
--   rewrites rows that the deleted feature owned; nothing else is touched.
--   The file is idempotent — running it twice changes nothing the second time.
--
-- STEP 0 (read-only) — see what is about to be affected:
--
--   SELECT id, placement, slot, tag, title, button_url, image
--     FROM promo_slides WHERE placement = 'slider' ORDER BY sort_order, id;
--
--   SELECT channel, event, enabled FROM notifications WHERE event = 'code_delivered';
--
--   SELECT id, name, sku, status, stock, category_id
--     FROM products WHERE product_type = 'digital' ORDER BY id;


-- ---------------------------------------------------------------------------
-- 1. Homepage slider: drop the Digital Codes slide.
--    Matched on the three identifying marks of that slide, so a slide the owner
--    has already rewritten into something else but that still uses the digital
--    artwork is included, and a legitimately repurposed slide is not.
-- ---------------------------------------------------------------------------
DELETE FROM `promo_slides`
 WHERE `placement` = 'slider'
   AND (
        `image`      LIKE '%slide-digital%'
     OR `button_url` LIKE '%gift+card%'
     OR `button_url` LIKE '%q=code%'
     OR `title`      = 'Digital codes delivered the moment payment clears'
   );


-- ---------------------------------------------------------------------------
-- 2. Notification event matrix: the `code_delivered` switch can never fire.
--    (Historical rows in `notification_logs` are kept on purpose.)
-- ---------------------------------------------------------------------------
DELETE FROM `notifications` WHERE `event` = 'code_delivered';


-- ---------------------------------------------------------------------------
-- 3. Seeded site copy — only the untouched installer defaults are rewritten.
--    If the owner edited `site_tagline` / `site_description`, the LIKE guards
--    do not match and the row is left alone.
-- ---------------------------------------------------------------------------
UPDATE `settings`
   SET `setting_value` = 'Everyday essentials, electronics and home products'
 WHERE `setting_key` = 'site_tagline'
   AND `setting_value` = 'Everyday essentials, electronics and digital products';

UPDATE `settings`
   SET `setting_value` = CONCAT(
         SUBSTRING_INDEX(`setting_value`, ' is an online marketplace', 1),
         ' is an online marketplace for electronics, home essentials, fashion, groceries and beauty products.'
       )
 WHERE `setting_key` = 'site_description'
   AND `setting_value` LIKE '% is an online marketplace for electronics, home essentials, fashion and instantly delivered digital products.';

UPDATE `settings`
   SET `setting_value` = ''
 WHERE `setting_key` = 'seo_default_description'
   AND `setting_value` LIKE '% is an online marketplace for electronics, home essentials, fashion and instantly delivered digital products.';


-- ---------------------------------------------------------------------------
-- 4. seo_pages: the removed page never had an entry in the SEO editor
--    (the catalogue never contained a `digital` key), so this is normally a
--    no-op. It only clears a row if one was created by hand, which would
--    otherwise keep a dead page key in the admin SEO list.
-- ---------------------------------------------------------------------------
DELETE FROM `seo_pages` WHERE `page_key` IN ('digital', 'digital-codes');


-- ---------------------------------------------------------------------------
-- STEP 5 (read-only) — verification and the owner's decision list.
--
--   a) The removed slide must be gone:
--      SELECT COUNT(*) FROM promo_slides
--       WHERE placement = 'slider'
--         AND (image LIKE '%slide-digital%' OR button_url LIKE '%gift+card%');
--
--   b) The event switch must be gone:
--      SELECT COUNT(*) FROM notifications WHERE event = 'code_delivered';
--
--   c) No copy left advertising instant digital delivery:
--      SELECT setting_key, setting_value FROM settings
--       WHERE setting_value LIKE '%digital%';
--
--   d) THE OWNER'S DECISION LIST. These products still exist, are still
--      visible in the catalogue, and can no longer be ordered online (the
--      store blocks them rather than taking an order it cannot fulfil).
--      Choose per product: set `product_type` to 'physical' with a real stock
--      count and a shipping weight/description, or set `status = 0` to
--      withdraw it, or delete it. Nothing here is applied automatically:
--
--      SELECT id, name, sku, status, stock, price FROM products
--       WHERE product_type = 'digital' ORDER BY id;
--
--   e) Left untouched on purpose, for reference only:
--      SHOW TABLES LIKE 'product_codes';
--      SHOW COLUMNS FROM orders LIKE '%has_digital%';
--      SHOW COLUMNS FROM orders LIKE '%codes_delivered%';
