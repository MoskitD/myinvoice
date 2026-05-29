-- MyInvoice.cz — Kategorie tržeb (revenue categories)
--
-- Plná symetrie ke kategoriím nákladů (expense_categories, migrace 0035):
--   * číselník revenue_categories per tenant (supplier_id), UNIQUE (supplier_id, code)
--   * invoices.revenue_category_id  — kategorie tržby na hlavičce vydané faktury
--   * clients.default_revenue_category_id — výchozí kategorie tržby pro zákazníka
--     (předvyplní se při zakládání faktury + jednorázový backfill při uložení klienta)
--
-- Rozdíl proti expense_categories: BEZ sloupce fixed_or_var (fixní/variabilní je
-- nákladový koncept, u tržeb nedává smysl). Per-řádková kategorie se nezavádí —
-- purchase_invoice_items.expense_category_id se v UI ani agregacích nepoužívá.
--
-- FK na *_category_id záměrně nepřidáváme — konzistentní s purchase_invoices/clients
-- (mazání kategorie ošetřuje aplikace: hard delete jen pokud nepoužitá, jinak archived).
--
-- Starý free-form sloupec invoices.revenue_category (VARCHAR 40, migrace 0035)
-- PONECHÁVÁME (nedropujeme) a jeho stávající hodnoty best-effort převedeme do
-- nového číselníku (viz data migrace níže).
--
-- Idempotence: MariaDB-native IF NOT EXISTS + INSERT IGNORE + UPDATE jen NULL hodnot.
-- Re-run safe (druhé spuštění je no-op).

SET NAMES utf8mb4;

-- ═══ revenue_categories ════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS revenue_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id     TINYINT UNSIGNED NOT NULL,
    code            VARCHAR(20) NOT NULL COMMENT '"konzultace", "produkt", "hosting"…',
    label           VARCHAR(100) NOT NULL,
    display_order   INT NOT NULL DEFAULT 0,
    archived        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_revenue_categories (supplier_id, code),
    KEY idx_revenue_supplier (supplier_id, archived, display_order),
    CONSTRAINT fk_rc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kategorie tržby na hlavičce vydané faktury (pro rozpad tržeb v CRM/Stats)
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS revenue_category_id INT UNSIGNED NULL
        COMMENT 'Kategorie tržby — pro report agregace. Nahrazuje free-form revenue_category.';

-- Výchozí kategorie tržby pro zákazníka (symetrie k default_expense_category_id)
ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS default_revenue_category_id INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Výchozí kategorie tržby pro vydané faktury tohoto zákazníka. NULL = bez defaultu.'
        AFTER default_expense_category_id;

-- ═══ Data migrace: free-form revenue_category → číselník ═════════════════
-- Pro každý distinct neprázdný tag založíme kategorii (code = slug, max 20 znaků).
-- Guard `slug <> ''` přeskočí hodnoty, které by se slugifikovaly na prázdno
-- (zůstanou s revenue_category_id IS NULL, starý text se zachová).
INSERT IGNORE INTO revenue_categories (supplier_id, code, label, display_order)
SELECT supplier_id, slug, label, 0
  FROM (
    SELECT DISTINCT
           i.supplier_id,
           LEFT(TRIM(i.revenue_category), 100) AS label,
           LEFT(TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(TRIM(i.revenue_category)), '[^a-z0-9]+', '-')), 20) AS slug
      FROM invoices i
     WHERE TRIM(COALESCE(i.revenue_category, '')) <> ''
  ) src
 WHERE src.slug <> '';

-- Propojení faktur s nově vzniklými kategoriemi (jen tam, kde ještě není nastaveno).
UPDATE invoices i
  JOIN revenue_categories rc
    ON rc.supplier_id = i.supplier_id
   AND rc.code = LEFT(TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(TRIM(i.revenue_category)), '[^a-z0-9]+', '-')), 20)
   SET i.revenue_category_id = rc.id
 WHERE i.revenue_category_id IS NULL
   AND TRIM(COALESCE(i.revenue_category, '')) <> '';
