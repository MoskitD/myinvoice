<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Parser;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ParsedBankEmailNotice;

interface BankEmailNoticeParserInterface
{
    /**
     * @param array<string,mixed> $provider
     */
    public function supports(BankEmailNoticeMessage $message, array $provider): bool;

    /**
     * @param array<string,mixed> $provider
     */
    public function parse(BankEmailNoticeMessage $message, array $provider): ParsedBankEmailNotice;
}
