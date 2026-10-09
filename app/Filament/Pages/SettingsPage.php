<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Settings\Numbering\InvalidNumberPattern;
use App\Domain\Settings\Numbering\NumberPattern;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Settings\Settings\ValidatedSettings;
use App\Domain\Settings\VatMode;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Money\Money;
use App\Filament\Concerns\EnforcesPageAccessRule;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\CanUseDatabaseTransactions;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
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
        BankAccountSettings::class,
        InvoicingSettings::class,
        NumberingSettings::class,
        DefaultsSettings::class,
        PaymentSettings::class,
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
                    $this->bankTab(),
                    $this->invoicingTab(),
                    $this->defaultsTab(),
                    $this->paymentsTab(),
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

    private function bankTab(): Tab
    {
        $currencies = array_combine(Money::isoCurrencyCodes(), Money::isoCurrencyCodes());

        // Rules go in as closures returning the list: Filament evaluates a closure rule placed directly in
        // the list and cannot resolve its $attribute parameter.
        return Tab::make(__('kokpit.settings.tabs.bank'))->schema([
            Group::make([
                Repeater::make('accounts')
                    ->label(__('kokpit.settings.bank.accounts'))
                    ->addActionLabel(__('kokpit.settings.bank.add_account'))
                    ->itemLabel(static fn (array $state): ?string => filled($state['label'] ?? null)
                        ? $state['label'].' ('.($state['currency'] ?? '').')'
                        : null)
                    ->defaultItems(0)
                    ->columns(2)
                    ->schema([
                        TextInput::make('label')
                            ->label(__('kokpit.settings.bank.label'))
                            ->required()
                            ->rules(fn (): array => BankAccountSettings::commonRules('label')),
                        Select::make('format')
                            ->label(__('kokpit.settings.bank.format'))
                            ->options(BankAccountFormat::class)
                            ->native(false)
                            ->live()
                            ->required(),
                        // Canonical upper-case codes, so distinct() also catches duplicates (D-04).
                        Select::make('currency')
                            ->label(__('kokpit.settings.bank.currency'))
                            ->options($currencies)
                            ->searchable()
                            ->required()
                            ->distinct()
                            ->rules(fn (): array => BankAccountSettings::commonRules('currency')),
                        TextInput::make('bic')
                            ->label(__('kokpit.settings.bank.bic'))
                            ->helperText(__('kokpit.settings.bank.bic_hint'))
                            ->rules(fn (): array => BankAccountSettings::commonRules('bic')),
                        $this->accountInput('account_number'),
                        $this->accountInput('bank_code'),
                        $this->accountInput('recipient_name'),
                        $this->accountInput('bank_name'),
                        $this->accountInput('bank_address')->columnSpanFull(),
                        $this->accountInput('iban')
                            ->helperText(__('kokpit.settings.bank.iban_hint'))
                            ->columnSpanFull(),
                    ]),
            ])->statePath(BankAccountSettings::group()),
        ]);
    }

    /**
     * A bank account field that only the formats showing it display; its rules
     * are those of the data layer for the chosen format.
     */
    private function accountInput(string $field): TextInput
    {
        $rulesFor = static function (Get $get) use ($field): array {
            $format = self::accountFormat($get('format'));

            return $format === null ? [] : BankAccountSettings::fieldRules($field, $format);
        };

        return TextInput::make($field)
            ->label(__("kokpit.settings.bank.{$field}"))
            ->visible(static fn (Get $get): bool => in_array(
                $field,
                self::accountFormat($get('format'))?->visibleFields() ?? [],
                true,
            ))
            ->required(static fn (Get $get): bool => in_array('required', $rulesFor($get), true))
            ->rules($rulesFor);
    }

    private function invoicingTab(): Tab
    {
        $rules = InvoicingSettings::rules();

        return Tab::make(__('kokpit.settings.tabs.invoicing'))->schema([
            Group::make([
                Select::make('vat_mode')
                    ->label(__('kokpit.settings.invoicing.vat_mode'))
                    ->helperText(__('kokpit.settings.invoicing.vat_mode_hint'))
                    ->options(VatMode::class)
                    ->disableOptionWhen(fn (string $value): bool => $value !== VatMode::NonPayer->value)
                    ->native(false)
                    ->required()
                    ->rules($rules['vat_mode']),
                TextInput::make('payment_due_days')
                    ->label(__('kokpit.settings.invoicing.payment_due_days'))
                    ->helperText(__('kokpit.settings.invoicing.payment_due_days_hint'))
                    ->numeric()
                    ->inputMode('numeric')
                    ->suffix(__('kokpit.settings.invoicing.days'))
                    ->required()
                    ->rules($rules['payment_due_days']),
            ])->statePath(InvoicingSettings::group()),
            $this->numberingSection(),
        ]);
    }

    /**
     * The numbering patterns of the invoicing tab (D-05). Every pattern field
     * shows the number its own counter would give next; the preview only reads.
     */
    private function numberingSection(): Group
    {
        return Group::make([
            Section::make(__('kokpit.settings.numbering.title'))
                ->description(__('kokpit.settings.numbering.token_help'))
                ->schema([
                    ...$this->patternInputs(DocumentKind::Invoice, 'invoice_pattern'),
                    ...$this->patternInputs(DocumentKind::Proforma, 'proforma_pattern'),
                    ...$this->patternInputs(DocumentKind::CreditNote, 'credit_note_pattern'),
                    // Fixed: never taken from the request. The field is not dehydrated, and its rule still
                    // refuses a crafted payload with the Czech reason instead of dropping it silently.
                    TextInput::make('task_pattern')
                        ->label(__('kokpit.settings.numbering.task_pattern'))
                        ->helperText(__('kokpit.settings.numbering.task_explanation'))
                        ->disabled()
                        ->rules(NumberingSettings::rules()['task_pattern']),
                ]),
        ])->statePath(NumberingSettings::group());
    }

    /**
     * The pattern input of a kind and, under it, the warning shown when the edited
     * pattern restarts the counter.
     *
     * @return list<TextInput|Text>
     */
    private function patternInputs(DocumentKind $kind, string $field): array
    {
        return [
            $this->patternInput($kind, $field),
            Text::make(__('kokpit.settings.numbering.reset_warning'))
                ->color('warning')
                ->visible(static fn (Get $get): bool => self::resetPeriodChanges($kind, $field, $get($field))),
        ];
    }

    /**
     * A pattern input with the rules of the data layer (the NumberPatternRule of
     * its kind, taken from NumberingSettings::rules()) and a preview line under
     * it. It updates on blur, not on every keystroke, and the preview is one
     * indexed read of the counter.
     */
    private function patternInput(DocumentKind $kind, string $field): TextInput
    {
        return TextInput::make($field)
            ->label(__("kokpit.settings.numbering.{$field}"))
            ->live(onBlur: true)
            ->required()
            ->rules(NumberingSettings::rules()[$field])
            ->helperText(static function (Get $get) use ($kind, $field): ?string {
                $number = self::previewNumber($kind, $get($field));

                return $number === null ? null : (string) __('kokpit.settings.numbering.preview', ['number' => $number]);
            });
    }

    /**
     * Whether the edited pattern resets its counter in another period than the
     * stored one, which starts the numbering again at 1. Compared with the stored
     * row, not with an in-memory settings object, so a failed save cannot hide it.
     */
    private static function resetPeriodChanges(DocumentKind $kind, string $field, mixed $edited): bool
    {
        $payload = SettingsProperty::query()
            ->where('group', NumberingSettings::group())
            ->where('name', $field)
            ->value('payload');

        $stored = is_string($payload) ? json_decode($payload, true) : null;

        if (! is_string($edited) || ! is_string($stored)) {
            return false;
        }

        try {
            return NumberPattern::parse($edited, $kind)->resetPeriod() !== NumberPattern::parse($stored, $kind)->resetPeriod();
        } catch (InvalidNumberPattern) {
            return false;
        }
    }

    /**
     * The number the counter would give next for a pattern as typed, or null
     * when the pattern is not valid (the field then shows its own error).
     */
    private static function previewNumber(DocumentKind $kind, mixed $pattern): ?string
    {
        if (! is_string($pattern)) {
            return null;
        }

        try {
            return app(DocumentNumbering::class)->preview($kind, $pattern);
        } catch (InvalidNumberPattern|OverflowException) {
            return null;
        }
    }

    private function paymentsTab(): Tab
    {
        $rules = PaymentSettings::rules();

        return Tab::make(__('kokpit.settings.tabs.payments'))->schema([
            Group::make([
                Toggle::make('online_payments_enabled')
                    ->label(__('kokpit.settings.payments.online_payments_enabled'))
                    ->helperText(__('kokpit.settings.payments.online_payments_enabled_hint'))
                    ->rules($rules['online_payments_enabled']),
            ])->statePath(PaymentSettings::group()),
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
                Select::make('default_invoice_language')
                    ->label(__('kokpit.settings.defaults.default_invoice_language'))
                    ->helperText(__('kokpit.settings.defaults.default_invoice_language_hint'))
                    ->options(InvoiceLanguage::class)
                    ->native(false)
                    ->required()
                    ->rules($rules['default_invoice_language']),
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
     * The format of a bank account item. A Filament select over an enum hands
     * the case itself back, a crafted payload hands a string or nothing.
     */
    private static function accountFormat(mixed $state): ?BankAccountFormat
    {
        return match (true) {
            $state instanceof BankAccountFormat => $state,
            is_string($state) => BankAccountFormat::tryFrom($state),
            default => null,
        };
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
