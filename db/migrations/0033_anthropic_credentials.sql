-- MyInvoice.cz — Fáze 2c: Anthropic Claude API credentials (BYOK)
--
-- Pro AI extrakci dat z PDF faktur bez ISDOC. Per-tenant API klíč
-- (BYOK = Bring Your Own Key — uživatel platí sám Anthropicovi).
--
-- Default model: claude-haiku-4-5 (~$0.001/faktura).
-- Override per request — uživatel může chtít Sonnet 4.6 pro lepší kvalitu.

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS anthropic_api_key_enc VARBINARY(512) NULL
        COMMENT 'Anthropic API key (sk-ant-...) šifrovaný AES-256-GCM',
    ADD COLUMN IF NOT EXISTS anthropic_default_model VARCHAR(64) NULL
        DEFAULT 'claude-haiku-4-5'
        COMMENT 'Default Claude model pro AI extrakci',
    ADD COLUMN IF NOT EXISTS anthropic_extractions_count INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Počítadlo successful AI extrakcí (pro telemetry/billing transparency)';
