<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Settings\Casts\MoneySettingsCast;
use App\Domain\Settings\Rules\KnownCurrency;
use App\Domain\Shared\Money\Money;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Defaults for new work (group `defaults`): the default currency, the default
 * hourly rate and the default invoice language. The rate is stored as integer
 * minor units plus its currency (Money); the form shows it as text with a
 * decimal comma. A new client copies these values once (D-13).
 */
class DefaultsSettings extends ValidatedSettings
{
    /** ISO 4217 code. */
    public string $default_currency;

    public Money $default_hourly_rate;

    public InvoiceLanguage $default_invoice_language;

    public static function group(): string
    {
        return 'defaults';
    }

    /**
     * @return array<string, MoneySettingsCast>
     */
    public static function casts(): array
    {
        return [
            'default_hourly_rate' => new MoneySettingsCast,
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'default_currency' => ['required', new KnownCurrency],
            'default_hourly_rate' => ['required', 'regex:/^\d+([.,]\d+)?$/'],
            'default_invoice_language' => ['required', new Enum(InvoiceLanguage::class)],
        ];
    }

    /**
     * @return array{default_currency: string, default_hourly_rate: string, default_invoice_language: string}
     */
    public function toFormState(): array
    {
        return [
            'default_currency' => $this->default_currency,
            'default_hourly_rate' => str_replace('.', ',', $this->default_hourly_rate->toMajor()),
            'default_invoice_language' => $this->default_invoice_language->value,
        ];
    }

    /**
     * The rate must be in the default currency: a rate in another currency would
     * silently price every new piece of work in the wrong money.
     *
     * @throws ValidationException
     */
    public function save(): static
    {
        if ($this->default_hourly_rate->currency !== $this->default_currency) {
            throw ValidationException::withMessages([
                'default_hourly_rate' => [__('kokpit.settings.defaults.rate_currency_mismatch')],
            ]);
        }

        return parent::save();
    }

    /**
     * Converts the typed rate with Money::fromMajor in the typed currency; a text
     * that Money refuses (excess decimals, grouping, empty) becomes a field error.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws ValidationException
     */
    public function fillFromFormState(array $state): static
    {
        if (is_string($state['default_currency'] ?? null)) {
            $this->default_currency = $state['default_currency'];
        }

        if (array_key_exists('default_hourly_rate', $state)) {
            try {
                $this->default_hourly_rate = Money::fromMajor(
                    is_string($state['default_hourly_rate']) ? $state['default_hourly_rate'] : '',
                    $this->default_currency,
                );
            } catch (InvalidArgumentException|OverflowException) {
                throw ValidationException::withMessages([
                    'default_hourly_rate' => [__('kokpit.settings.defaults.rate_invalid')],
                ]);
            }
        }

        if (array_key_exists('default_invoice_language', $state)) {
            // A Filament select over an enum hands the case itself back; a crafted payload hands a string.
            $language = match (true) {
                $state['default_invoice_language'] instanceof InvoiceLanguage => $state['default_invoice_language'],
                is_string($state['default_invoice_language']) => InvoiceLanguage::tryFrom($state['default_invoice_language']),
                default => null,
            };

            if ($language === null) {
                throw ValidationException::withMessages([
                    'default_invoice_language' => [__('kokpit.settings.defaults.invoice_language_invalid')],
                ]);
            }

            $this->default_invoice_language = $language;
        }

        return $this;
    }
}
