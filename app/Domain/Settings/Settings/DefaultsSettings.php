<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use App\Domain\Settings\Casts\MoneySettingsCast;
use App\Domain\Shared\Money\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Defaults for new work (group `defaults`): the default currency and the default
 * hourly rate. The rate is stored as integer minor units plus its currency
 * (Money); the form shows it as text with a decimal comma.
 */
class DefaultsSettings extends ValidatedSettings
{
    /** ISO 4217 code. */
    public string $default_currency;

    public Money $default_hourly_rate;

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
            'default_currency' => ['required', Rule::in(Money::isoCurrencyCodes())],
            'default_hourly_rate' => ['required', 'regex:/^\d+([.,]\d+)?$/'],
        ];
    }

    /**
     * @return array{default_currency: string, default_hourly_rate: string}
     */
    public function toFormState(): array
    {
        return [
            'default_currency' => $this->default_currency,
            'default_hourly_rate' => str_replace('.', ',', $this->default_hourly_rate->toMajor()),
        ];
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

        return $this;
    }
}
