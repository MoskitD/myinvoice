-- MyInvoice.cz — CRM summary: CZK-přepočtené sloupce pro volbu „Vše"
--
-- crm_monthly_summary drží částky v NATIVNÍ měně per (period, currency). Pro
-- agregaci „Vše" (součet všech měn) potřebujeme společnou jednotku → přidáme
-- *_czk sloupce naplněné v sp_recompute_crm_monthly_summary přes exchange_rate
-- doklady (stejný vzorec jako topClients/expenseBreakdown:
--   total * COALESCE(IF(code='CZK', 1, exchange_rate), 1)).
--
-- Frontend pak při volbě „Vše" sečte *_czk napříč měnami a zobrazí v CZK.
-- Per-currency pohled dál používá nativní revenue/costs (beze změny).
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS + DROP/CREATE PROCEDURE.
-- Po migraci se hodnoty doplní při nejbližším lazy recompute (≤5 min) nebo
-- ručním „Přepočítat".

SET NAMES utf8mb4;

ALTER TABLE crm_monthly_summary
    ADD COLUMN IF NOT EXISTS revenue_czk     DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'Revenue (with VAT) přepočtené na CZK',
    ADD COLUMN IF NOT EXISTS revenue_net_czk DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'Revenue (without VAT) přepočtené na CZK',
    ADD COLUMN IF NOT EXISTS costs_czk       DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'Costs (with VAT) přepočtené na CZK',
    ADD COLUMN IF NOT EXISTS costs_net_czk   DECIMAL(18, 4) NOT NULL DEFAULT 0 COMMENT 'Costs (without VAT) přepočtené na CZK';

DELIMITER //

DROP PROCEDURE IF EXISTS sp_recompute_crm_monthly_summary //

CREATE PROCEDURE sp_recompute_crm_monthly_summary(IN p_supplier_id TINYINT UNSIGNED)
BEGIN
    DECLARE v_cutoff DATE;
    SET v_cutoff = DATE_SUB(CURDATE(), INTERVAL 13 MONTH);

    DELETE FROM crm_monthly_summary
     WHERE supplier_id = p_supplier_id
       AND period_ym >= DATE_FORMAT(v_cutoff, '%Y-%m');

    -- Revenue + invoice count z vydaných (status NOT IN draft, cancelled)
    INSERT INTO crm_monthly_summary
        (supplier_id, period_ym, currency, revenue, revenue_net, revenue_czk, revenue_net_czk, invoice_count,
         costs, costs_net, costs_czk, costs_net_czk, purchase_count, vat_output, vat_input)
    SELECT
        i.supplier_id,
        DATE_FORMAT(i.issue_date, '%Y-%m') AS ym,
        COALESCE(c.code, 'CZK') AS currency,
        SUM(COALESCE(i.total_with_vat, 0))    AS revenue,
        SUM(COALESCE(i.total_without_vat, 0)) AS revenue_net,
        SUM(COALESCE(i.total_with_vat, 0)    * COALESCE(IF(c.code = 'CZK', 1, i.exchange_rate), 1)) AS revenue_czk,
        SUM(COALESCE(i.total_without_vat, 0) * COALESCE(IF(c.code = 'CZK', 1, i.exchange_rate), 1)) AS revenue_net_czk,
        COUNT(*) AS invoice_count,
        0, 0, 0, 0, 0,
        SUM(COALESCE(i.total_with_vat, 0) - COALESCE(i.total_without_vat, 0)) AS vat_output,
        0
      FROM invoices i
 LEFT JOIN currencies c ON c.id = i.currency_id
     WHERE i.supplier_id = p_supplier_id
       AND i.status NOT IN ('draft', 'cancelled')
       AND i.issue_date >= v_cutoff
       AND i.invoice_type != 'proforma'  -- proformy vynechat (nejsou daňový doklad)
  GROUP BY i.supplier_id, ym, currency
       ON DUPLICATE KEY UPDATE
           revenue         = VALUES(revenue),
           revenue_net     = VALUES(revenue_net),
           revenue_czk     = VALUES(revenue_czk),
           revenue_net_czk = VALUES(revenue_net_czk),
           invoice_count   = VALUES(invoice_count),
           vat_output      = VALUES(vat_output);

    -- Costs + purchase count z přijatých (status NOT IN draft, cancelled).
    -- Zálohu (advance) vyřaď, pokud je zaplacená NEBO spárovaná s finální fakturou
    -- (proti dvojímu započtení nákladu). Nezaplacená nespárovaná záloha se počítá.
    INSERT INTO crm_monthly_summary
        (supplier_id, period_ym, currency, revenue, revenue_net, revenue_czk, revenue_net_czk, invoice_count,
         costs, costs_net, costs_czk, costs_net_czk, purchase_count, vat_output, vat_input)
    SELECT
        pi.supplier_id,
        DATE_FORMAT(pi.issue_date, '%Y-%m') AS ym,
        COALESCE(c.code, 'CZK') AS currency,
        0, 0, 0, 0, 0,
        SUM(COALESCE(pi.total_with_vat, 0))    AS costs,
        SUM(COALESCE(pi.total_without_vat, 0)) AS costs_net,
        SUM(COALESCE(pi.total_with_vat, 0)    * COALESCE(IF(c.code = 'CZK', 1, pi.exchange_rate), 1)) AS costs_czk,
        SUM(COALESCE(pi.total_without_vat, 0) * COALESCE(IF(c.code = 'CZK', 1, pi.exchange_rate), 1)) AS costs_net_czk,
        COUNT(*) AS purchase_count,
        0,
        SUM(COALESCE(pi.total_with_vat, 0) - COALESCE(pi.total_without_vat, 0)) AS vat_input
      FROM purchase_invoices pi
 LEFT JOIN currencies c ON c.id = pi.currency_id
     WHERE pi.supplier_id = p_supplier_id
       AND pi.status NOT IN ('draft', 'cancelled')
       AND pi.issue_date >= v_cutoff
       AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                AND (pi.status = 'paid'
                     OR EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                 WHERE adv_s.advance_purchase_invoice_id = pi.id)))
  GROUP BY pi.supplier_id, ym, currency
       ON DUPLICATE KEY UPDATE
           costs          = VALUES(costs),
           costs_net      = VALUES(costs_net),
           costs_czk      = VALUES(costs_czk),
           costs_net_czk  = VALUES(costs_net_czk),
           purchase_count = VALUES(purchase_count),
           vat_input      = VALUES(vat_input);
END //

DELIMITER ;
