<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Invoicing\Pdf\DocumentPalette;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\DocumentImage;
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
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
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
                    $groupState = $state[$group] ?? [];

                    if ($class === SupplierSettings::class) {
                        $groupState = $this->applyImageChanges($groupState);
                    }

                    app($class)->fillFromFormState($groupState)->save();
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages($this->prefixErrors($group, $exception->errors()));
                }
            }
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        $this->commitDatabaseTransaction();

        $this->resetImageFields();

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
                ...$this->imageInputs('logo'),
                ...$this->imageInputs('signature'),
            ])->statePath(SupplierSettings::group()),
        ]);
    }

    /**
     * The upload, the preview of the stored image and the remove switch of one
     * image kind. The upload field only carries the file: it is never stored by
     * Filament, SettingsPage::save() hands it to DocumentImage, and the stored path
     * is not a form field at all, so a crafted form state cannot set it.
     *
     * @return list<FileUpload|Text|Toggle>
     */
    private function imageInputs(string $kind): array
    {
        return [
            FileUpload::make("{$kind}_upload")
                ->label(__("kokpit.settings.supplier.{$kind}_upload"))
                ->helperText(__('kokpit.settings.supplier.image_hint'))
                ->acceptedFileTypes(['image/png', 'image/jpeg'])
                ->maxSize(DocumentImage::MAX_BYTES / 1024)
                ->storeFiles(false)
                ->multiple(false)
                ->openable(false)
                ->downloadable(false),
            Text::make(static fn (Get $get): HtmlString => self::imagePreview($get("{$kind}_path"))),
            Toggle::make("{$kind}_remove")
                ->label(__("kokpit.settings.supplier.{$kind}_remove"))
                ->helperText(__('kokpit.settings.supplier.image_kept_note'))
                ->visible(static fn (Get $get): bool => filled($get("{$kind}_path"))),
        ];
    }

    /**
     * The stored image at 160 x 40 pt as a data URI (no public URL exists). Empty
     * when nothing is stored; a stored object that can no longer be read shows the
     * warning instead of failing the page.
     */
    private static function imagePreview(mixed $path): HtmlString
    {
        if (! is_string($path) || $path === '') {
            return new HtmlString('');
        }

        try {
            $images = app(DocumentImage::class);
            $reference = $images->reference($path);
            $uri = $reference === null ? null : $images->dataUri($reference['path'], $reference['sha256']);
        } catch (Throwable) {
            return new HtmlString('<span>'.e((string) __('kokpit.settings.supplier.image_missing')).'</span>');
        }

        return new HtmlString('<img src="'.e((string) $uri).'" alt="" style="max-width:160pt;max-height:40pt">');
    }

    /**
     * After a successful save the upload and remove fields start empty again and the
     * form carries the stored paths, so the preview shows what was saved and a
     * second Save does not store the same file once more over a later removal.
     */
    private function resetImageFields(): void
    {
        $supplier = app(SupplierSettings::class);

        foreach (['logo', 'signature'] as $kind) {
            $this->data['supplier']["{$kind}_path"] = $supplier->{"{$kind}_path"};
            $this->data['supplier']["{$kind}_upload"] = [];
            $this->data['supplier']["{$kind}_remove"] = false;
        }
    }

    /**
     * Turns the upload and remove fields of the supplier tab into the stored paths:
     * a new file is checked and stored by DocumentImage, a remove switch clears the
     * path (the stored object stays, an issued document may reference it, D-23).
     * Only uploaded file objects count; a string in the field is client-controlled
     * and ignored.
     *
     * @param  array<string, mixed>  $supplier
     * @return array<string, mixed>
     */
    private function applyImageChanges(array $supplier): array
    {
        foreach (['logo', 'signature'] as $kind) {
            $upload = $supplier["{$kind}_upload"] ?? null;
            $upload = is_array($upload) ? (array_values($upload)[0] ?? null) : $upload;

            if ($upload instanceof UploadedFile) {
                $supplier["{$kind}_path"] = app(DocumentImage::class)->store($upload, $kind);
            } elseif (($supplier["{$kind}_remove"] ?? false) === true) {
                $supplier["{$kind}_path"] = null;
            }

            unset($supplier["{$kind}_upload"], $supplier["{$kind}_remove"]);
        }

        return $supplier;
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
            $this->appearanceSection(),
        ]);
    }

    /**
     * The appearance of the documents (D-24): the accent colour with a sample of
     * the colours derived from it, and the footer text. The sample is a static
     * strip in inline styles (no Tailwind class that the build could miss) and the
     * contrast line is the measured contrast of the text on the total bar.
     */
    private function appearanceSection(): Group
    {
        $rules = InvoicingSettings::rules();

        return Group::make([
            Section::make(__('kokpit.settings.invoicing.appearance.title'))
                ->schema([
                    ColorPicker::make('document_accent_color')
                        ->label(__('kokpit.settings.invoicing.appearance.accent'))
                        ->helperText(__('kokpit.settings.invoicing.appearance.accent_hint'))
                        ->live(debounce: 500)
                        ->afterStateUpdated(static function (mixed $state, Set $set): void {
                            // Typed upper case is stored lower case; the rule itself stays strict.
                            if (is_string($state) && $state !== strtolower(trim($state))) {
                                $set('document_accent_color', strtolower(trim($state)));
                            }
                        })
                        ->markAsRequired()
                        // Filament would evaluate a bare rule object list too; the closure keeps the
                        // data-layer list as the one source.
                        ->rules(fn (): array => $rules['document_accent_color']),
                    Text::make(static fn (Get $get): HtmlString => self::appearanceSample($get('document_accent_color'))),
                    Textarea::make('document_footer')
                        ->label(__('kokpit.settings.invoicing.appearance.footer'))
                        ->helperText(__('kokpit.settings.invoicing.appearance.footer_hint'))
                        ->rows(3)
                        ->maxLength(300)
                        ->rules($rules['document_footer']),
                ]),
        ])->statePath(InvoicingSettings::group());
    }

    /**
     * The sample strip: the accent, the darkened accent text, the text on the
     * total bar and the tint, each in its own colours, and the contrast line.
     * The colours come out of DocumentPalette, which falls back to the default
     * for a value that is not #rrggbb, so nothing typed reaches the style.
     */
    private static function appearanceSample(mixed $accent): HtmlString
    {
        $palette = DocumentPalette::fromAccent(is_string($accent) ? $accent : null);

        $swatch = static fn (string $label, string $style): string => '<span style="display:inline-block;padding:0.5rem 1rem;margin-right:0.5rem;border:1px solid '
            .e($palette->hairline).';'.$style.'">'.e($label).'</span>';

        $ratio = number_format($palette->onAccentContrast(), 1, ',', '');

        return new HtmlString(
            '<span style="display:block">'
            .$swatch(__('kokpit.settings.invoicing.appearance.sample_accent'), 'background:'.e($palette->accent).';color:'.e($palette->onAccent))
            .$swatch(__('kokpit.settings.invoicing.appearance.sample_accent_text'), 'background:#ffffff;color:'.e($palette->accentText))
            .$swatch(__('kokpit.settings.invoicing.appearance.sample_on_accent'), 'background:'.e($palette->accent).';color:'.e($palette->onAccent).';font-weight:600')
            .$swatch(__('kokpit.settings.invoicing.appearance.sample_tint'), 'background:'.e($palette->tint).';color:'.e($palette->ink))
            .'</span><span style="display:block;margin-top:0.5rem">'
            .e((string) __('kokpit.settings.invoicing.appearance.contrast', ['ratio' => $ratio]))
            .'</span>'
        );
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
