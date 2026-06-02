<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class BankEmailNoticeParserRepository
{
    /**
     * @var array<string,BankEmailNoticeParserInterface>
     */
    private array $parsers;

    public function __construct(
        private readonly Connection $db,
        ?RegexBankEmailNoticeParser $regexParser = null,
        ?RaiffeisenbankEmailNoticeParser $raiffeisenbankParser = null,
    ) {
        $regexParser ??= new RegexBankEmailNoticeParser();
        $raiffeisenbankParser ??= new RaiffeisenbankEmailNoticeParser();
        $this->parsers = [
            'regex' => $regexParser,
            'raiffeisenbank' => $raiffeisenbankParser,
        ];
    }

    /**
     * @return array{provider:array<string,mixed>, parsed:ParsedBankEmailNotice}
     */
    public function parse(BankEmailNoticeMessage $message, ?int $preferredProviderId = null, ?int $supplierId = null): array
    {
        foreach ($this->providers($preferredProviderId, $supplierId) as $provider) {
            $parser = $this->parsers[(string) $provider['parser_type']] ?? null;
            if ($parser === null) {
                continue;
            }
            if (!$parser->supports($message, $provider)) {
                continue;
            }
            return ['provider' => $provider, 'parsed' => $parser->parse($message, $provider)];
        }

        throw new \RuntimeException('Pro e-mail nebyl nalezen žádný aktivní parser provider.');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function providers(?int $preferredProviderId = null, ?int $supplierId = null): array
    {
        $sql = 'SELECT * FROM bank_email_notice_providers WHERE enabled = 1';
        $params = [];
        if ($supplierId !== null && $supplierId > 0) {
            $sql .= ' AND (supplier_id IS NULL OR supplier_id = ?)';
            $params[] = $supplierId;
        }
        if ($preferredProviderId !== null && $preferredProviderId > 0) {
            $sql .= ' AND id = ?';
            $params[] = $preferredProviderId;
        }
        $sql .= ' ORDER BY supplier_id IS NOT NULL DESC, id ASC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['supplier_id'] = $row['supplier_id'] !== null ? (int) $row['supplier_id'] : null;
            $row['enabled'] = (bool) $row['enabled'];
            $row['field_patterns'] = $this->json((string) $row['field_patterns']);
            $row['normalizer_config'] = $this->json((string) ($row['normalizer_config'] ?? '{}'));
        }
        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function json(string $json): array
    {
        $decoded = json_decode($json !== '' ? $json : '{}', true);
        return is_array($decoded) ? $decoded : [];
    }
}
