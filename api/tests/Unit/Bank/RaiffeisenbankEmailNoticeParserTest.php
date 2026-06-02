<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\Parser\RaiffeisenbankEmailNoticeParser;
use PHPUnit\Framework\TestCase;

final class RaiffeisenbankEmailNoticeParserTest extends TestCase
{
    public function testParsesSanitizedNoticeSample(): void
    {
        $text = <<<TEXT
Datum a čas
01. 06. 2026 10:15
Na účet
123456789/5500Firma Test s.r.o.
Částka v měně účtu
+1.234,56 CZK
Z účtu
987654321/5500Plátce Demo s.r.o.
Variabilní symbol
2606001
Konstantní symbol
308
Zpráva pro příjemce
Faktura 2606001
Disponibilní zůstatek po pohybu
+99.999,99 CZK
TEXT;

        $message = new BankEmailNoticeMessage(
            uid: 1,
            messageId: '<sanitized-sample@rb.cz>',
            date: new \DateTimeImmutable('2026-06-01 10:15:00'),
            sender: 'Raiffeisenbank <info@rb.cz>',
            subject: 'Pohyb na účtě',
            text: $text,
            raw: $text,
        );

        $parser = new RaiffeisenbankEmailNoticeParser();
        self::assertTrue($parser->supports($message, ['parser_type' => 'raiffeisenbank']));

        $parsed = $parser->parse($message, ['parser_type' => 'raiffeisenbank']);

        self::assertSame('2606001', $parsed->variableSymbol);
        self::assertSame(1234.56, $parsed->amount);
        self::assertSame('CZK', $parsed->currency);
        self::assertSame('2026-06-01', $parsed->postedAt);
        self::assertSame('123456789/5500', $parsed->recipientAccount);
        self::assertSame('987654321', $parsed->counterpartyAccount);
        self::assertSame('5500', $parsed->counterpartyBank);
        self::assertSame('Plátce Demo s.r.o.', $parsed->counterpartyName);
        self::assertSame('308', $parsed->constantSymbol);
        self::assertSame('Faktura 2606001', $parsed->message);
    }
}
