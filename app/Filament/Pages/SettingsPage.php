<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Settings\Settings\ValidatedSettings;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Money\Money;
use App\Filament\Concerns\EnforcesPageAccessRule;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\CanUseDatabaseTransactions;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;
use Throwable;
use UnitEnum;

/**
 * The one settings page of the panel (D-02): a tab per settings group, one Save.
 *
 * Tab order, final after plan 03-09: supplier, bank accounts, invoicing,
 * defaults, online payments. Each later plan adds its tab method and appends
 * its class to SETTINGS in that order.
 *
 * @property-read Schema $form
 */
#[AccessRule(Audience::AdminOnly, reason: 'Operator settings: supplier data, bank accounts, numbering and payment configuration are never shown to a Partner.')]
class SettingsPage extends Page
{
    use CanUseDatabaseTransactions;
    use EnforcesPageAccessRule;
    use HasUnsavedDataChangesAlert;

    /**
     * The settings classes on this page, in tab order. The form state holds one
     * entry per class under its group name.
     *
     * @var list<class-string<ValidatedSettings>>
     */
    private const SETTINGS = [
        SupplierSettings::class,
        DefaultsSettings::class,
    ];

    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 90;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * The save must be atomic whatever the panel-wide switch says.
     */
    public function hasDatabaseTransactions(): bool
    {
        return true;
    }

    public static function getNavigationLabel(): string
    {
        return __('kokpit.settings.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('kokpit.settings.navigation_group');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedCog6Tooth;
    }

    public function getTitle(): string
    {
        return __('kokpit.settings.title');
    }

    public function mount(): void
    {
        $state = [];

        foreach (self::SETTINGS as $class) {
            $state[$class::group()] = app($class)->toFormState();
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make()->tabs([
                    $this->supplierTab(),
                    $this->defaultsTab(),
                ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('kokpit.settings.save'))
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    /**
     * Saves every settings group in one transaction. A failure in any group
     * rolls all of them back; a data-layer ValidationException is shown on the
     * field data.{group}.{key}.
     */
    public function save(): void
    {
        $state = $this->form->getState();

        $this->beginDatabaseTransaction();

        try {
            foreach (self::SETTINGS as $class) {
                $group = $class::group();

                try {
                    app($class)->fillFromFormState($state[$group] ?? [])->save();
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages($this->prefixErrors($group, $exception->errors()));
                }
            }
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        $this->commitDatabaseTransaction();

        $this->rememberData();

        Notification::make()
            ->success()
            ->title(__('kokpit.settings.saved'))
            ->send();
    }

    private function supplierTab(): Tab
    {
        return Tab::make(__('kokpit.settings.tabs.supplier'))->schema([
            Group::make([
                $this->supplierInput('company_name')->autofocus(),
                $this->supplierInput('street'),
                $this->supplierInput('city'),
                $this->supplierInput('postal_code'),
                $this->supplierInput('country')
                    ->helperText(__('kokpit.settings.supplier.country_hint'))
                    ->dehydrateStateUsing(fn (mixed $state): mixed => is_string($state) ? mb_strtoupper(trim($state)) : $state),
                $this->supplierInput('company_id'),
                $this->supplierInput('vat_id')
                    ->dehydrateStateUsing(fn (mixed $state): mixed => is_string($state) ? mb_strtoupper(preg_replace('/\s+/', '', $state) ?? $state) : $state),
                $this->supplierInput('email')->email(),
                $this->supplierInput('phone')->tel(),
                $this->supplierInput('website')->url(),
                $this->supplierInput('registration_note'),
            ])->statePath(SupplierSettings::group()),
        ]);
    }

    private function defaultsTab(): Tab
    {
        $rules = DefaultsSettings::rules();

        return Tab::make(__('kokpit.settings.tabs.defaults'))->schema([
            Group::make([
                Select::make('default_currency')
                    ->label(__('kokpit.settings.defaults.default_currency'))
                    ->options(array_combine(Money::isoCurrencyCodes(), Money::isoCurrencyCodes()))
                    ->searchable()
                    ->live()
                    ->required()
                    ->rules($rules['default_currency']),
                TextInput::make('default_hourly_rate')
                    ->label(__('kokpit.settings.defaults.default_hourly_rate'))
                    ->helperText(__('kokpit.settings.defaults.default_hourly_rate_hint'))
                    ->inputMode('decimal')
                    ->suffix(fn (Get $get): string => (string) $get('default_currency').' / '.__('kokpit.settings.defaults.per_hour'))
                    ->required()
                    ->rules(fn (Get $get): array => [
                        ...$rules['default_hourly_rate'],
                        // Money::fromMajor is the one parser: it refuses excess decimals instead of rounding them.
                        static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            try {
                                Money::fromMajor(is_string($value) ? $value : '', (string) $get('default_currency'));
                            } catch (InvalidArgumentException|OverflowException) {
                                $fail(__('kokpit.settings.defaults.rate_invalid'));
                            }
                        },
                    ]),
            ])->statePath(DefaultsSettings::group()),
        ]);
    }

    /**
     * A text input for one supplier field, carrying the rules of the data layer
     * so the form and SupplierSettings::save() cannot drift apart.
     */
    private function supplierInput(string $key): TextInput
    {
        $rules = SupplierSettings::rules()[$key];

        return TextInput::make($key)
            ->label(__("kokpit.settings.supplier.{$key}"))
            ->required(in_array('required', $rules, true))
            ->rules($rules);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function prefixErrors(string $group, array $errors): array
    {
        $prefixed = [];

        foreach ($errors as $key => $messages) {
            $prefixed["data.{$group}.{$key}"] = $messages;
        }

        return $prefixed;
    }
}
