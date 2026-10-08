-- Theme Customization + admin restructure — reference migration.
-- Theme colours live in the existing `settings` table as theme_<key> rows and
-- are created on first save (missing rows fall back to the CSS defaults), so
-- NO schema change is required. Built-in courier rows are inserted automatically
-- (INSERT IGNORE) when Admin → Courier / Shipping is opened. Everything below is
-- optional and safe to re-run.

INSERT IGNORE INTO `couriers` (`name`, `code`, `driver`, `status`, `sort_order`) VALUES
  ('Steadfast',         'steadfast',   'steadfast', 0, 1),
  ('Pathao Courier',    'pathao',      'pathao',    0, 2),
  ('RedX',              'redx',        'redx',      0, 3),
  ('eCourier',          'ecourier',    'ecourier',  0, 4),
  ('Paperfly',          'paperfly',    'paperfly',  0, 5),
  ('Sundarban Courier', 'sundarban',   'sundarban', 0, 6),
  ('SA Paribahan',      'saparibahan', 'custom',    0, 7);
