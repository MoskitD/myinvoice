-- MyInvoice.cz — Default kategorie tržby na zakázce (projektu)
--
-- Zakázka (project) může mít přednastavenou výchozí kategorii tržby. Použití:
--   * Při zakládání nové vydané faktury se předvyplní. PŘEDNOST má default zakázky
--     před defaultem klienta (clients.default_revenue_category_id) — zakázka je
--     konkrétnější. Pořadí: explicitní volba na faktuře > zakázka > klient.
--   * Při uložení zakázky s nastavenou kategorií se default jednorázově doplní do
--     VŠECH jejích vydaných faktur, které kategorii nemají vyplněnou
--     (revenue_category_id IS NULL). Faktury s vybranou kategorií zůstanou beze změny.
--
-- FK záměrně nepřidáváme — konzistentní s clients.default_revenue_category_id (0073)
-- a invoices.revenue_category_id. Mazání kategorie ošetřuje aplikace.
--
-- Idempotence: MariaDB-native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE projects
  ADD COLUMN IF NOT EXISTS default_revenue_category_id INT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Výchozí kategorie tržby pro vydané faktury této zakázky. Přednost před klientem. NULL = bez defaultu.'
    AFTER note;
