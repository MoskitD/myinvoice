<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

final class RaiffeisenbankEmailNoticeParser implements BankEmailNoticeParserInterface
{
    private EmailNoticeTextNormalizer $normalizer;

    public function __construct(?EmailNoticeTextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new EmailNoticeTextNormalizer();
    }

    public function supports(BankEmailNoticeMessage $message, array $provider): bool
    {
        $sender = strtolower($message->sender);
        if (!str_contains($sender, 'info@rb.cz') && !str_contains($sender, '@rb.cz')) {
            return false;
        }
        $subject = mb_strtolower($message->subject);
        if (!str_contains($subject, 'pohyb na účtě') && !str_contains($subject, 'pohyb na ucte')) {
            return false;
        }
        $text = mb_strtolower($this->normalizer->normalize($message->text));
        return str_contains($text, 'variabilní symbol')
            && str_contains($text, 'částka v měně účtu')
            && str_contains($text, 'na účet');
    }

    public function parse(BankEmailNoticeMessage $message, array $provider): ParsedBankEmailNotice
    {
        $text = $this->normalizer->normalize($message->text);

        $postedAt = $this->required($text, '/Datum\s+a\s+čas\s*(?<value>\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s+\d{1,2}:\d{2})/iu', 'datum');
        $recipientAccount = $this->required($text, '/Na\s+účet\s*(?<value>[0-9\-]+\/[0-9]{4})/iu', 'cílový účet');
        $amountCurrency = $this->match($text, '/Částka\s+v\s+měně\s+účtu\s*(?<amount>[+\-]?[0-9 .]+,[0-9]{2})\s*(?<currency>[A-Z]{3})/iu');
        if ($amountCurrency === null) {
            throw new \RuntimeException('Raiffeisenbank parser nenašel částku a měnu.');
        }
        $counterparty = $this->match($text, '/Z\s+účtu\s*(?<account>[0-9\-]+\/[0-9]{4})(?<name>.*?)Variabilní\s+symbol/isu');
        $variableSymbol = $this->required($text, '/Variabilní\s+symbol\s*(?<value>[0-9]+)/iu', 'variabilní symbol');
        $constantSymbol = $this->optional($text, '/Konstantní\s+symbol\s*(?<value>[0-9]+)/iu');
        $note = $this->optional($text, '/Zpráva\s+pro\s+příjemce\s*(?<value>.*?)Disponibilní\s+zůstatek/isu');

        [$counterpartyAccount, $counterpartyBank] = $this->splitAccount((string) ($counterparty['account'] ?? ''));

        return new ParsedBankEmailNotice(
            variableSymbol: $variableSymbol,
            amount: $this->parseAmount((string) $amountCurrency['amount']),
            currency: strtoupper((string) $amountCurrency['currency']),
            postedAt: $this->parseDate($postedAt),
            recipientAccount: $recipientAccount,
            counterpartyAccount: $counterpartyAccount,
            counterpartyBank: $counterpartyBank,
            counterpartyName: $this->cleanNullable((string) ($counterparty['name'] ?? '')),
            constantSymbol: $constantSymbol,
            message: $note,
        );
    }

    /**
     * @return array<string,string>|null
     */
    private function match(string $text, string $pattern): ?array
    {
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }
        $out = [];
        foreach ($m as $key => $value) {
            if (is_string($key)) {
                $out[$key] = trim((string) $value);
            }
        }
        return $out;
    }

    private function required(string $text, string $pattern, string $label): string
    {
        $value = $this->optional($text, $pattern);
        if ($value === null) {
            throw new \RuntimeException("Raiffeisenbank parser nenašel {$label}.");
        }
        return $value;
    }

    private function optional(string $text, string $pattern): ?string
    {
        $m = $this->match($text, $pattern);
        if ($m === null || !isset($m['value'])) {
            return null;
        }
        return $this->cleanNullable($m['value']);
    }

    private function parseAmount(string $value): float
    {
        $value = str_replace(["\xc2\xa0", ' ', '+', '.'], '', trim($value));
        $value = str_replace(',', '.', $value);
        return (float) $value;
    }

    private function parseDate(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        foreach (['d. m. Y H:i', 'd.m.Y H:i'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }
        throw new \RuntimeException('Raiffeisenbank parser nenašel validní datum platby.');
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function splitAccount(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/^(?<account>[0-9\-]+)\/(?<bank>[0-9]{4})$/', $value, $m) === 1) {
            return [$m['account'], $m['bank']];
        }
        return [$value, null];
    }

    private function cleanNullable(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
