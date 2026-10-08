<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use App\Domain\Settings\VatMode;
use Illuminate\Validation\ValidationException;

/**
 * How invoices are issued (group `invoicing`): the VAT mode and the default
 * number of days until an invoice is due.
 *
 * The numbering section of plan 03-09 joins this group later.
 */
class InvoicingSettings extends ValidatedSettings
{
    /** Only `non_payer` is supported in this milestone. */
    public VatMode $vat_mode;

    public int $payment_due_days;

    public static function group(): string
    {
        return 'invoicing';
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'vat_mode' => ['required'],
            'payment_due_days' => ['required', 'integer'],
        ];
    }

    /**
     * @return array{vat_mode: string, payment_due_days: int}
     */
    public function toFormState(): array
    {
        return [
            'vat_mode' => $this->vat_mode->value,
            'payment_due_days' => $this->payment_due_days,
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

        return $this;
    }
}
