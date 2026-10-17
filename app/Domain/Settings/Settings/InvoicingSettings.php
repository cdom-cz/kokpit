<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use App\Domain\Settings\Rules\HexColorRule;
use App\Domain\Settings\VatMode;
use Illuminate\Validation\ValidationException;

/**
 * How invoices are issued (group `invoicing`): the VAT mode, the default
 * number of days until an invoice is due and the appearance of the documents
 * (accent colour and footer text, D-24).
 *
 * The values of the appearance are frozen into the issue snapshot, so a later
 * change here never alters an issued document.
 */
class InvoicingSettings extends ValidatedSettings
{
    // Only `non_payer` is supported in this milestone.
    public VatMode $vat_mode;

    public int $payment_due_days;

    /** Lower-case #rrggbb; every other colour of the PDF is derived from it. */
    public string $document_accent_color;

    public ?string $document_footer;

    public static function group(): string
    {
        return 'invoicing';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'vat_mode' => ['required', 'in:non_payer'],
            'payment_due_days' => ['required', 'integer', 'between:0,365'],
            'document_accent_color' => [new HexColorRule],
            'document_footer' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array{vat_mode: string, payment_due_days: int, document_accent_color: string, document_footer: string|null}
     */
    public function toFormState(): array
    {
        return [
            'vat_mode' => $this->vat_mode->value,
            'payment_due_days' => $this->payment_due_days,
            'document_accent_color' => $this->document_accent_color,
            'document_footer' => $this->document_footer,
        ];
    }

    /**
     * Converts the form values into the typed properties; a value that cannot
     * be converted (unknown mode, non-integer days) becomes a field error.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws ValidationException
     */
    public function fillFromFormState(array $state): static
    {
        if (array_key_exists('vat_mode', $state)) {
            // A Filament select over an enum hands the case itself back; a crafted payload hands a string.
            $mode = match (true) {
                $state['vat_mode'] instanceof VatMode => $state['vat_mode'],
                is_string($state['vat_mode']) => VatMode::tryFrom($state['vat_mode']),
                default => null,
            };

            if ($mode === null) {
                throw ValidationException::withMessages([
                    'vat_mode' => [__('kokpit.settings.invoicing.vat_mode_invalid')],
                ]);
            }

            $this->vat_mode = $mode;
        }

        if (array_key_exists('payment_due_days', $state)) {
            $days = filter_var($state['payment_due_days'], FILTER_VALIDATE_INT);

            if ($days === false) {
                throw ValidationException::withMessages([
                    'payment_due_days' => [__('kokpit.settings.invoicing.payment_due_days_invalid')],
                ]);
            }

            $this->payment_due_days = $days;
        }

        if (array_key_exists('document_accent_color', $state)) {
            // A colour picker may hand back upper case; the stored form is lower case. Anything else stays
            // as typed and is refused by the rule.
            $color = $state['document_accent_color'];
            $this->document_accent_color = is_string($color) ? strtolower(trim($color)) : '';
        }

        if (array_key_exists('document_footer', $state)) {
            $footer = $state['document_footer'];
            $this->document_footer = is_string($footer) && trim($footer) !== '' ? $footer : null;
        }

        return $this;
    }
}
