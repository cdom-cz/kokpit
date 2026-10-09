<?php

declare(strict_types=1);

namespace App\Domain\Settings\Banking;

use InvalidArgumentException;

/**
 * One bank account of the supplier. The stored shape is the same for every
 * account (every key present); fields the format does not show are null.
 */
final readonly class BankAccount
{
    public function __construct(
        public BankAccountFormat $format,
        public string $label,
        public string $currency,
        public ?string $bic = null,
        public ?string $accountNumber = null,
        public ?string $bankCode = null,
        public ?string $bankName = null,
        public ?string $iban = null,
        public ?string $recipientName = null,
        public ?string $bankAddress = null,
    ) {}

    /**
     * Reads an account from a stored or submitted array. Missing keys read as
     * null (a payload written before a field existed still loads).
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException when the format is missing or unknown
     */
    public static function fromArray(array $data): self
    {
        $format = $data['format'] ?? null;
        $format = $format instanceof BankAccountFormat ? $format : (is_string($format) ? BankAccountFormat::tryFrom($format) : null);

        if ($format === null) {
            throw new InvalidArgumentException('A bank account needs a known format.');
        }

        $shown = static fn (string $field): ?string => in_array($field, $format->visibleFields(), true)
            ? self::text($data[$field] ?? null)
            : null;

        return new self(
            format: $format,
            label: self::text($data['label'] ?? null) ?? '',
            currency: self::text($data['currency'] ?? null) ?? '',
            bic: self::text($data['bic'] ?? null),
            accountNumber: $shown('account_number'),
            bankCode: $shown('bank_code'),
            bankName: $shown('bank_name'),
            iban: $shown('iban'),
            recipientName: $shown('recipient_name'),
            bankAddress: $shown('bank_address'),
        );
    }

    /**
     * The same account in its stored form: text trimmed, empty text as null,
     * the currency and BIC/SWIFT upper case, the IBAN without spaces.
     */
    public function normalised(): self
    {
        $clean = static fn (?string $value): ?string => ($value === null || trim($value) === '') ? null : trim($value);
        $upper = static fn (?string $value): ?string => ($value = $clean($value)) === null ? null : mb_strtoupper($value);

        return new self(
            format: $this->format,
            label: trim($this->label),
            currency: mb_strtoupper(trim($this->currency)),
            bic: $upper($this->bic),
            accountNumber: $clean($this->accountNumber),
            bankCode: $clean($this->bankCode),
            bankName: $clean($this->bankName),
            iban: ($iban = $clean($this->iban)) === null ? null : Iban::normalise($iban),
            recipientName: $clean($this->recipientName),
            bankAddress: $clean($this->bankAddress),
        );
    }

    /**
     * @return array{format: string, label: string, currency: string, bic: ?string, account_number: ?string, bank_code: ?string, bank_name: ?string, iban: ?string, recipient_name: ?string, bank_address: ?string}
     */
    public function toArray(): array
    {
        $shown = fn (string $field, ?string $value): ?string => in_array($field, $this->format->visibleFields(), true) ? $value : null;

        return [
            'format' => $this->format->value,
            'label' => $this->label,
            'currency' => $this->currency,
            'bic' => $this->bic,
            'account_number' => $shown('account_number', $this->accountNumber),
            'bank_code' => $shown('bank_code', $this->bankCode),
            'bank_name' => $shown('bank_name', $this->bankName),
            'iban' => $shown('iban', $this->iban),
            'recipient_name' => $shown('recipient_name', $this->recipientName),
            'bank_address' => $shown('bank_address', $this->bankAddress),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
