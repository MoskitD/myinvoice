<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

final class ParsedBankEmailNotice
{
    public function __construct(
        public readonly string $variableSymbol,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $postedAt,
        public readonly string $recipientAccount,
        public readonly ?string $counterpartyAccount = null,
        public readonly ?string $counterpartyBank = null,
        public readonly ?string $counterpartyName = null,
        public readonly ?string $constantSymbol = null,
        public readonly ?string $message = null,
        public readonly ?string $bankRef = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'variable_symbol' => $this->variableSymbol,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'posted_at' => $this->postedAt,
            'recipient_account' => $this->recipientAccount,
            'counterparty_account' => $this->counterpartyAccount,
            'counterparty_bank' => $this->counterpartyBank,
            'counterparty_name' => $this->counterpartyName,
            'constant_symbol' => $this->constantSymbol,
            'message' => $this->message,
            'bank_ref' => $this->bankRef,
        ];
    }
}
