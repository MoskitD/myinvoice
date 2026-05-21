<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Anthropic Claude API client pro AI extraction z PDF faktur.
 *
 * BYOK — per-tenant API klíč (uživatel platí sám). Default model:
 * claude-haiku-4-5 (~$0.001/faktura), pro lepší kvalitu lze přepnout
 * na Sonnet 4.6 (~$0.005/faktura).
 *
 * Cena za extrakci PDF s ~5 řádkami:
 *   Haiku 4.5:  ~3000 input tokens (PDF base64) + ~500 output tokens
 *               = $0.0006 input + $0.0025 output = ~$0.003
 *   Sonnet 4.6: ~$0.012 (4× dráž)
 *
 * Strict JSON output přes structured response — anti-hallucination.
 */
final class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const TIMEOUT = 120; // PDF extraction trvá 10-30s typicky
    private const MAX_PDF_BYTES = 32 * 1024 * 1024; // 32 MiB hard limit (Anthropic limit)

    private Client $http;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
    ) {
        $this->http = new Client([
            'timeout' => self::TIMEOUT,
            'http_errors' => false,
        ]);
    }

    /**
     * @return array{api_key:string, default_model:string}|null
     */
    public function getCredentials(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT anthropic_api_key_enc, anthropic_default_model FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || empty($row['anthropic_api_key_enc'])) return null;
        try {
            $key = $this->crypto->decrypt((string) $row['anthropic_api_key_enc']);
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic API key decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
        return [
            'api_key'       => $key,
            'default_model' => (string) ($row['anthropic_default_model'] ?? 'claude-haiku-4-5'),
        ];
    }

    public function setCredentials(int $supplierId, string $apiKey, ?string $defaultModel = null): void
    {
        $enc = $apiKey === '' ? null : $this->crypto->encrypt($apiKey);
        $model = $defaultModel ?: 'claude-haiku-4-5';
        $this->db->pdo()->prepare(
            'UPDATE supplier SET anthropic_api_key_enc = ?, anthropic_default_model = ?
              WHERE id = ?'
        )->execute([$enc, $model, $supplierId]);
    }

    /**
     * Test connectivity — pošle minimalistický prompt, ověří 200 OK.
     */
    public function testConnection(int $supplierId): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'API key nenastaven'];
        }
        try {
            $resp = $this->http->post(self::API_URL, [
                'headers' => $this->authHeaders($creds['api_key']),
                'json' => [
                    'model' => $creds['default_model'],
                    'max_tokens' => 10,
                    'messages' => [['role' => 'user', 'content' => 'Reply OK']],
                ],
            ]);
            $code = $resp->getStatusCode();
            $body = json_decode((string) $resp->getBody(), true);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }
            return ['ok' => true, 'model' => $body['model'] ?? null, 'usage' => $body['usage'] ?? null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extrahuje strukturovaná data z PDF faktury pomocí Claude vision.
     *
     * Workflow:
     *   1. PDF → base64
     *   2. Strict system prompt s JSON schema definicí
     *   3. POST /messages s document content block (type=document, source.type=base64, source.data=...)
     *   4. Parse response.content[0].text jako JSON
     *   5. Validate proti hallucinations (caller zodpovědný)
     *
     * @return array{ok:bool, data?:array<string,mixed>, error?:string, model?:string, usage?:array<string,int>}
     */
    public function extractInvoice(int $supplierId, string $pdfBytes, ?string $modelOverride = null): array
    {
        $creds = $this->getCredentials($supplierId);
        if ($creds === null) {
            return ['ok' => false, 'error' => 'Anthropic API key nenastaven pro tohoto suppliera.'];
        }
        if (strlen($pdfBytes) > self::MAX_PDF_BYTES) {
            return ['ok' => false, 'error' => 'PDF přesahuje limit ' . self::MAX_PDF_BYTES . ' B.'];
        }
        if (!str_starts_with($pdfBytes, '%PDF')) {
            return ['ok' => false, 'error' => 'Soubor není validní PDF (chybí %PDF header).'];
        }

        $model = $modelOverride ?: $creds['default_model'];
        $base64Pdf = base64_encode($pdfBytes);

        $systemPrompt = <<<'EOT'
Jsi expert na extrakci dat z českých a slovenských faktur. Z PDF přílohy vytáhneš strukturovaná data ve striktním JSON formátu.

PRAVIDLA:
- Vrátíš JEN platný JSON (žádný markdown, žádný komentář před/po).
- Pokud pole neexistuje v PDF, použij null. NEVYMÝŠLEJ data.
- Datumy ve formátu ISO YYYY-MM-DD.
- Částky čísla bez měny (přidej zvlášť do `currency`).
- IČ/DIČ ořež na čísla (CZ12345678 → "12345678"), pokud má prefix země ponech v `dic` jak je.
- VAT rate jako desetinné číslo (21.0, 15.0, 12.0, 10.0, 0.0).

JSON schema:
{
  "vendor": {
    "company_name": string,
    "ic": string|null,
    "dic": string|null,
    "street": string|null,
    "city": string|null,
    "zip": string|null,
    "country_iso2": "CZ"|"SK"|...
  },
  "customer": {
    "company_name": string|null,
    "ic": string|null,
    "dic": string|null
  },
  "vendor_invoice_number": string,
  "varsymbol": string|null,
  "issue_date": "YYYY-MM-DD",
  "tax_date": "YYYY-MM-DD"|null,
  "due_date": "YYYY-MM-DD"|null,
  "currency": "CZK"|"EUR"|"USD"|...,
  "items": [
    {
      "description": string,
      "quantity": number,
      "unit": string,
      "unit_price_without_vat": number,
      "vat_rate": number
    }
  ],
  "total_without_vat": number|null,
  "total_with_vat": number|null
}
EOT;

        try {
            $resp = $this->http->post(self::API_URL, [
                'headers' => $this->authHeaders($creds['api_key']),
                'json' => [
                    'model' => $model,
                    'max_tokens' => 4096,
                    'system' => $systemPrompt,
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'document',
                                'source' => [
                                    'type' => 'base64',
                                    'media_type' => 'application/pdf',
                                    'data' => $base64Pdf,
                                ],
                            ],
                            [
                                'type' => 'text',
                                'text' => 'Vytáhni strukturovaná data z této faktury podle JSON schema. Odpověz JEN samotným JSON, bez markdown.',
                            ],
                        ],
                    ]],
                ],
            ]);
            $code = $resp->getStatusCode();
            $body = json_decode((string) $resp->getBody(), true);
            if ($code !== 200) {
                $msg = is_array($body) ? ($body['error']['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
                return ['ok' => false, 'error' => $msg];
            }

            // Parse Claude's text response
            $text = (string) ($body['content'][0]['text'] ?? '');
            if ($text === '') {
                return ['ok' => false, 'error' => 'Prázdná odpověď od Claude'];
            }
            // Strip případné markdown code fences
            $text = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', $text);
            $data = json_decode((string) $text, true);
            if (!is_array($data)) {
                return ['ok' => false, 'error' => 'Claude vrátil invalid JSON: ' . substr($text, 0, 200)];
            }

            // Increment usage counter
            $this->db->pdo()->prepare(
                'UPDATE supplier SET anthropic_extractions_count = anthropic_extractions_count + 1 WHERE id = ?'
            )->execute([$supplierId]);

            return [
                'ok'    => true,
                'data'  => $data,
                'model' => $body['model'] ?? $model,
                'usage' => $body['usage'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic extractInvoice failed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function authHeaders(string $apiKey): array
    {
        return [
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ];
    }
}
