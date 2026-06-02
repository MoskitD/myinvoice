-- MyInvoice.cz - FR-58 bankovni emailova aviza
--
-- Automaticke parovani prichozich plateb z bankovnich emailovych aviz.
-- V1 je postavena nad IMAP pollingem, read-only mailbox kompatibilitou
-- a parser provider registry konfigurovanou v DB/UI.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS bank_email_imap_settings (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 TINYINT UNSIGNED NOT NULL,
  name                        VARCHAR(190) NOT NULL DEFAULT 'IMAP účet',
  enabled                     TINYINT(1) NOT NULL DEFAULT 0,
  host                        VARCHAR(190) NOT NULL DEFAULT '',
  port                        INT UNSIGNED NOT NULL DEFAULT 993,
  encryption                  ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
  validate_cert               TINYINT(1) NOT NULL DEFAULT 1,
  username                    VARCHAR(190) NOT NULL DEFAULT '',
  password_enc                TEXT NULL,
  folder                      VARCHAR(190) NOT NULL DEFAULT 'INBOX',
  max_messages_per_run        INT UNSIGNED NOT NULL DEFAULT 50,
  process_from_date           DATE NULL,
  success_action              ENUM('none','add_flag','move','mark_seen') NOT NULL DEFAULT 'none',
  success_flag                VARCHAR(80) NULL DEFAULT 'MyInvoiceProcessed',
  success_move_folder         VARCHAR(190) NULL,
  failure_action              ENUM('none','add_flag','move') NOT NULL DEFAULT 'none',
  failure_flag                VARCHAR(80) NULL DEFAULT 'MyInvoiceFailed',
  failure_move_folder         VARCHAR(190) NULL,
  retry_failed                TINYINT(1) NOT NULL DEFAULT 0,
  max_attempts                INT UNSIGNED NOT NULL DEFAULT 3,
  last_scan_at                TIMESTAMP NULL,
  last_scan_status            ENUM('ok','error') NULL,
  last_scan_message           VARCHAR(500) NULL,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_bei_supplier (supplier_id, enabled),
  CONSTRAINT fk_bei_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_email_notice_providers (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         TINYINT UNSIGNED NULL,
  code                VARCHAR(80) NOT NULL,
  name                VARCHAR(190) NOT NULL,
  parser_type         ENUM('regex','raiffeisenbank') NOT NULL DEFAULT 'regex',
  enabled             TINYINT(1) NOT NULL DEFAULT 1,
  sender_whitelist    TEXT NULL,
  subject_pattern     VARCHAR(500) NULL,
  body_pattern        VARCHAR(500) NULL,
  field_patterns      JSON NOT NULL,
  normalizer_config   JSON NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_benp_supplier_code (supplier_id, code),
  KEY idx_benp_enabled (enabled, code),
  CONSTRAINT fk_benp_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_email_account_mappings (
  id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                 TINYINT UNSIGNED NOT NULL,
  currency_id                 INT UNSIGNED NOT NULL,
  imap_account_id             BIGINT UNSIGNED NULL,
  provider_id                 BIGINT UNSIGNED NULL,
  enabled                     TINYINT(1) NOT NULL DEFAULT 0,
  amount_tolerance            DECIMAL(14,2) NOT NULL DEFAULT 0.05,
  created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_beam_currency (currency_id),
  KEY idx_beam_supplier (supplier_id),
  KEY idx_beam_imap_account (imap_account_id),
  KEY idx_beam_provider (provider_id),
  CONSTRAINT fk_beam_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_beam_currency FOREIGN KEY (currency_id) REFERENCES currencies(id) ON DELETE CASCADE,
  CONSTRAINT fk_beam_imap_account FOREIGN KEY (imap_account_id) REFERENCES bank_email_imap_settings(id) ON DELETE SET NULL,
  CONSTRAINT fk_beam_provider FOREIGN KEY (provider_id) REFERENCES bank_email_notice_providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_email_processed_messages (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id             TINYINT UNSIGNED NOT NULL,
  imap_account_id         BIGINT UNSIGNED NULL,
  imap_uid                BIGINT UNSIGNED NULL,
  message_id              VARCHAR(255) NULL,
  fallback_hash           CHAR(64) NOT NULL,
  message_date            DATETIME NULL,
  sender                  VARCHAR(255) NULL,
  subject                 VARCHAR(500) NULL,
  provider_id             BIGINT UNSIGNED NULL,
  provider_code           VARCHAR(80) NULL,
  status                  ENUM('processed_success','duplicate','parse_failed','security_rejected','match_failed','postprocess_failed','skipped_old','skipped_known') NOT NULL,
  attempts                INT UNSIGNED NOT NULL DEFAULT 1,
  parsed_payload          JSON NULL,
  bank_statement_id       BIGINT UNSIGNED NULL,
  bank_transaction_id     BIGINT UNSIGNED NULL,
  matched_invoice_id      BIGINT UNSIGNED NULL,
  error_message           VARCHAR(1000) NULL,
  processed_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bepm_account_message_id (imap_account_id, message_id),
  UNIQUE KEY uq_bepm_account_hash (imap_account_id, fallback_hash),
  KEY idx_bepm_supplier_status (supplier_id, status, processed_at),
  KEY idx_bepm_imap_account (imap_account_id),
  KEY idx_bepm_provider (provider_id),
  KEY idx_bepm_transaction (bank_transaction_id),
  CONSTRAINT fk_bepm_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_bepm_imap_account FOREIGN KEY (imap_account_id) REFERENCES bank_email_imap_settings(id) ON DELETE SET NULL,
  CONSTRAINT fk_bepm_provider FOREIGN KEY (provider_id) REFERENCES bank_email_notice_providers(id) ON DELETE SET NULL,
  CONSTRAINT fk_bepm_statement FOREIGN KEY (bank_statement_id) REFERENCES bank_statements(id) ON DELETE SET NULL,
  CONSTRAINT fk_bepm_transaction FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_bepm_invoice FOREIGN KEY (matched_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bank_statements
  ADD COLUMN IF NOT EXISTS source ENUM('gpc','email_notice') NOT NULL DEFAULT 'gpc' AFTER id;

ALTER TABLE bank_statements
  ADD COLUMN IF NOT EXISTS source_ref VARCHAR(190) NULL AFTER source;

ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS source ENUM('statement','email_notice') NOT NULL DEFAULT 'statement' AFTER id;

ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS source_ref VARCHAR(190) NULL AFTER source;

ALTER TABLE bank_transactions
  ADD COLUMN IF NOT EXISTS match_tolerance DECIMAL(14,2) NULL AFTER match_status;

UPDATE bank_email_notice_providers
   SET name = 'Raiffeisenbank - Pohyb na ucte',
       parser_type = 'raiffeisenbank',
       sender_whitelist = 'info@rb.cz',
       subject_pattern = 'Pohyb\\s+na\\s+účtě|Pohyb\\s+na\\s+ucte',
       body_pattern = 'Variabilní\\s+symbol',
       field_patterns = JSON_OBJECT(),
       normalizer_config = JSON_OBJECT()
 WHERE supplier_id IS NULL AND code = 'raiffeisenbank';

INSERT INTO bank_email_notice_providers
  (supplier_id, code, name, parser_type, enabled, sender_whitelist, subject_pattern, body_pattern, field_patterns, normalizer_config)
SELECT
  NULL,
  'raiffeisenbank',
  'Raiffeisenbank - Pohyb na ucte',
  'raiffeisenbank',
  1,
  'info@rb.cz',
  'Pohyb\\s+na\\s+účtě|Pohyb\\s+na\\s+ucte',
  'Variabilní\\s+symbol',
  JSON_OBJECT(),
  JSON_OBJECT()
WHERE NOT EXISTS (
  SELECT 1 FROM bank_email_notice_providers WHERE supplier_id IS NULL AND code = 'raiffeisenbank'
);
