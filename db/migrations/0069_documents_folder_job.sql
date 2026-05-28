-- Sekce Dokumenty — background job pro upload celé složky (mnoho souborů).
-- Chunked upload do staging + asynchronní zpracování (obchází max_file_uploads
-- i request timeout). MODIFY je idempotentní.

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import'
    ) NOT NULL;
