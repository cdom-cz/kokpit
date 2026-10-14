<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiTokenResource\Pages;

use App\Domain\Identity\Actions\IssueApiToken;
use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\ApiTokenExpiry;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\ApiTokenResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The token list with the two actions of the screen (AP-01, AP-05; D-04).
 *
 * Creating a token is a header action with a form; its handler confirms the identity
 * (password, then a fresh TOTP code), hands the choices to IssueApiToken and opens the
 * one-time modal. The plain text of the new token lives only in the locked property
 * below, from the moment it is issued until the modal's only button clears it.
 */
final class ListApiTokens extends ListRecords
{
    protected static string $resource = ApiTokenResource::class;

    /** The rate limiter key prefix of the identity confirmation, one bucket per user (D-04). */
    private const string LIMITER_PREFIX = 'api-token-confirm:';

    /** Confirmation attempts a minute and a user the create modal accepts (UI-SPEC U-5). */
    private const int ATTEMPTS_PER_MINUTE = 5;

    /** The plain-text token, held only while the one-time modal is open. */
    #[Locked]
    public ?string $plainToken = null;

    /**
     * What the one-time modal summarises: name, ability values and the validity text.
     *
     * @var array{name?: string, abilities?: list<string>, validity?: string}
     */
    #[Locked]
    public array $createdSummary = [];

    public function getTitle(): string
    {
        return __('kokpit.api_tokens.title');
    }

    public function getSubheading(): string
    {
        return __('kokpit.api_tokens.subheading');
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->docsAction(),
            $this->createTokenAction(),
        ];
    }

    /**
     * Where the generated documentation lives; it opens in a new tab.
     */
    public static function docsUrl(): string
    {
        return url((string) config('scribe.laravel.docs_url', '/docs'));
    }

    public function docsAction(): Action
    {
        return Action::make('docs')
            ->label(__('kokpit.api_tokens.actions.docs'))
            ->icon(Heroicon::OutlinedBookOpen)
            ->color('gray')
            ->url(self::docsUrl(), shouldOpenInNewTab: true)
            ->extraAttributes(['rel' => 'noopener']);
    }

    public function createTokenAction(): Action
    {
        return Action::make('createToken')
            ->label(__('kokpit.api_tokens.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('kokpit.api_tokens.create.heading'))
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('kokpit.api_tokens.create.submit'))
            ->modalCancelActionLabel(__('kokpit.api_tokens.create.cancel'))
            ->schema([
                TextInput::make('name')
                    ->label(__('kokpit.api_tokens.create.fields.name'))
                    ->placeholder(__('kokpit.api_tokens.create.name_placeholder'))
                    ->helperText(__('kokpit.api_tokens.create.name_helper'))
                    ->required()
                    ->maxLength(80)
                    ->autofocus()
                    ->validationMessages([
                        'required' => __('kokpit.api_tokens.errors.name_required'),
                        'max' => __('kokpit.api_tokens.errors.name_too_long'),
                    ]),
                CheckboxList::make('abilities')
                    ->label(__('kokpit.api_tokens.create.fields.abilities'))
                    ->options(ApiAbility::class)
                    ->descriptions(self::abilityDescriptions())
                    ->required()
                    ->minItems(1)
                    ->validationMessages([
                        'required' => __('kokpit.api_tokens.errors.abilities_required'),
                        'min' => __('kokpit.api_tokens.errors.abilities_required'),
                    ]),
                Select::make('expiry')
                    ->label(__('kokpit.api_tokens.create.fields.expiry'))
                    ->options(ApiTokenExpiry::class)
                    ->default(ApiTokenExpiry::None->value)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->required()
                    ->helperText(static fn (Get $get): string => self::expiryHelper($get('expiry'))),
                Section::make(__('kokpit.api_tokens.create.identity_heading'))
                    ->description(__('kokpit.api_tokens.create.identity_description'))
                    ->schema([
                        TextInput::make('password')
                            ->label(__('kokpit.api_tokens.create.fields.password'))
                            ->password()
                            ->revealable()
                            ->autocomplete('current-password')
                            ->required()
                            ->validationMessages(['required' => __('kokpit.api_tokens.errors.password_required')]),
                        OneTimeCodeInput::make('code')
                            ->label(__('kokpit.api_tokens.create.fields.code'))
                            ->required()
                            ->validationMessages([
                                'required' => __('kokpit.api_tokens.errors.code_required'),
                                'numeric' => __('kokpit.api_tokens.errors.code_wrong'),
                                'digits' => __('kokpit.api_tokens.errors.code_wrong'),
                            ]),
                    ]),
            ])
            ->action(function (array $data): void {
                $this->issueToken($data);
            });
    }

    /**
     * The one-time modal: the only place the plain-text token is ever shown.
     */
    public function showTokenAction(): Action
    {
        return Action::make('showToken')
            ->modalHeading(__('kokpit.api_tokens.one_time.heading'))
            ->modalWidth(Width::Large)
            ->modalCloseButton(false)
            ->closeModalByEscaping(false)
            ->closeModalByClickingAway(false)
            ->modalCancelAction(false)
            ->modalSubmitActionLabel(__('kokpit.api_tokens.one_time.submit'))
            ->fillForm(fn (): array => ['token' => $this->plainToken])
            ->schema([
                Callout::make(__('kokpit.api_tokens.one_time.callout_heading'))
                    ->description(__('kokpit.api_tokens.one_time.callout_body'))
                    ->warning()
                    ->icon(Heroicon::OutlinedExclamationTriangle),
                TextEntry::make('summary_name')
                    ->label(__('kokpit.api_tokens.one_time.name'))
                    ->state(fn (): string => $this->createdSummary['name'] ?? '')
                    ->extraAttributes(['style' => 'overflow-wrap: anywhere;']),
                TextEntry::make('summary_abilities')
                    ->label(__('kokpit.api_tokens.one_time.abilities'))
                    ->badge()
                    ->color('gray')
                    ->state(fn (): array => $this->createdSummary['abilities'] ?? [])
                    ->formatStateUsing(static fn (string $state): string => ApiAbility::tryFrom($state)?->getLabel() ?? $state),
                TextEntry::make('summary_validity')
                    ->label(__('kokpit.api_tokens.one_time.validity'))
                    ->state(fn (): string => $this->createdSummary['validity'] ?? ''),
                Textarea::make('token')
                    ->label(__('kokpit.api_tokens.one_time.token_label'))
                    ->readOnly()
                    ->dehydrated(false)
                    ->rows(3)
                    ->autofocus()
                    ->extraInputAttributes([
                        'class' => 'font-mono',
                        'style' => 'word-break: break-all; user-select: all;',
                        'x-on:focus' => '$el.select()',
                        'x-on:click' => '$el.select()',
                    ]),
                Actions::make([
                    Action::make('copyToken')
                        ->label(__('kokpit.api_tokens.one_time.copy'))
                        ->icon(Heroicon::OutlinedClipboard)
                        ->color('gray')
                        ->alpineClickHandler(
                            'navigator.clipboard?.writeText($wire.mountedActions[0].data.token).then(() => new FilamentNotification().title('
                            .json_encode(__('kokpit.api_tokens.one_time.copied'), JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_QUOT)
                            .').success().send())',
                        ),
                ]),
                Actions::make([
                    Action::make('usageDocs')
                        ->label(__('kokpit.api_tokens.actions.docs'))
                        ->link()
                        ->url(self::docsUrl(), shouldOpenInNewTab: true)
                        ->extraAttributes(['rel' => 'noopener']),
                ])->label(__('kokpit.api_tokens.one_time.usage')),
            ])
            ->action(function (): void {
                $this->plainToken = null;
                $this->createdSummary = [];
            });
    }

    /**
     * Filament cannot resolve the row of a revoke confirmation that was deleted in the meantime (in
     * another tab) and would drop the submit without a word. The component hook runs before Filament
     * resolves the mounted actions, so it can say so instead (UI-SPEC Surface D): the confirmation
     * closes, the info toast shows, and the list refreshes with the response.
     */
    public function hydrate(): void
    {
        $mounted = $this->mountedActions[0] ?? [];
        $recordKey = $mounted['context']['recordKey'] ?? null;

        if (
            ($mounted['name'] ?? null) === 'revoke'
            && is_string($recordKey)
            && Str::isUuid($recordKey)
            && ! ApiTokenResource::getEloquentQuery()->whereKey($recordKey)->exists()
        ) {
            $this->mountedActions = [];

            Notification::make()->info()->title(__('kokpit.api_tokens.toasts.already_gone'))->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function issueToken(array $data): void
    {
        // Order of checks: authorization, throttle, password, code, then the rest of the form.
        abort_unless(ApiTokenResource::canAccess(), 403);

        $user = auth()->user();
        assert($user instanceof User);

        $limiterKey = self::LIMITER_PREFIX.$user->getKey();

        if (RateLimiter::tooManyAttempts($limiterKey, self::ATTEMPTS_PER_MINUTE)) {
            $this->failUnderModal(['password' => __('kokpit.api_tokens.errors.throttled', ['seconds' => RateLimiter::availableIn($limiterKey)])]);
        }

        RateLimiter::hit($limiterKey, 60);

        // Fails closed: without a stored authenticator secret there is nothing to confirm against.
        if (blank($user->getAppAuthenticationSecret())) {
            Notification::make()->danger()->title(__('kokpit.api_tokens.errors.failed'))->send();

            return;
        }

        if (! Hash::check((string) ($data['password'] ?? ''), $user->password)) {
            $this->failUnderModal(['password' => __('kokpit.api_tokens.errors.password_wrong')]);
        }

        $code = (string) ($data['code'] ?? '');

        if (preg_match('/\A\d{6}\z/', $code) !== 1 || ! AppAuthentication::make()->verifyCode($code, null, shouldPreventCodeReuse: true)) {
            $this->failUnderModal(['code' => __('kokpit.api_tokens.errors.code_wrong')]);
        }

        $abilities = [];

        foreach ((array) ($data['abilities'] ?? []) as $value) {
            // The checkbox list hands back enum cases for an enum options list; a plain string is
            // accepted too, and anything else is refused.
            $ability = $value instanceof ApiAbility ? $value : (is_string($value) ? ApiAbility::tryFrom($value) : null);

            if ($ability === null) {
                $this->failUnderModal(['abilities' => __('kokpit.api_tokens.errors.abilities_required')]);
            }

            $abilities[] = $ability;
        }

        $expiry = self::asExpiry($data['expiry'] ?? null) ?? ApiTokenExpiry::None;

        try {
            $issued = app(IssueApiToken::class)->handle($user, (string) ($data['name'] ?? ''), $abilities, $expiry);
        } catch (ValidationException $e) {
            $this->failUnderModal(array_map(static fn (array $messages): string => (string) ($messages[0] ?? ''), $e->errors()));
        }

        $this->plainToken = $issued->plainTextToken;
        $this->createdSummary = [
            'name' => $issued->accessToken->name,
            'abilities' => array_map(static fn (ApiAbility $ability): string => $ability->value, $abilities),
            'validity' => self::validityText($expiry),
        ];

        $this->replaceMountedAction('showToken');
    }

    /**
     * Stops the create action with field errors under its modal form. The modal stays open with the
     * name, abilities and validity kept, and the password and the code cleared: a secret that failed
     * is never left in the form.
     *
     * @param  array<string, string>  $errors  message by field of the form
     */
    private function failUnderModal(array $errors): never
    {
        foreach (['password', 'code'] as $secret) {
            data_set($this->mountedActions, "0.data.{$secret}", null);
        }

        $mapped = [];

        foreach ($errors as $field => $message) {
            $mapped["mountedActions.0.data.{$field}"] = $message;
        }

        throw ValidationException::withMessages($mapped);
    }

    /**
     * @return array<string, string>
     */
    private static function abilityDescriptions(): array
    {
        $descriptions = [];

        foreach (ApiAbility::cases() as $ability) {
            $descriptions[$ability->value] = __('kokpit.api_tokens.ability_descriptions.'.str_replace(':', '_', $ability->value));
        }

        return $descriptions;
    }

    /**
     * The validity choice from form state, which holds the enum case or its value.
     */
    private static function asExpiry(mixed $choice): ?ApiTokenExpiry
    {
        return $choice instanceof ApiTokenExpiry ? $choice : (is_string($choice) ? ApiTokenExpiry::tryFrom($choice) : null);
    }

    /**
     * The live helper line under Platnost.
     */
    private static function expiryHelper(mixed $choice): string
    {
        $date = self::expiryDate(self::asExpiry($choice));

        return $date === null
            ? __('kokpit.api_tokens.expiry_helper.none')
            : __('kokpit.api_tokens.expiry_helper.limited', ['date' => $date]);
    }

    /**
     * "do :date" or "Bez omezení", the validity line of the one-time modal.
     */
    private static function validityText(ApiTokenExpiry $expiry): string
    {
        $date = self::expiryDate($expiry);

        return $date === null
            ? __('kokpit.api_tokens.no_limit')
            : __('kokpit.api_tokens.one_time.validity_until', ['date' => $date]);
    }

    /**
     * The last day of validity as j. n. Y in the display time zone, or null for no limit.
     */
    private static function expiryDate(?ApiTokenExpiry $expiry): ?string
    {
        $expiresAt = $expiry?->expiresAt(CarbonImmutable::now());

        return $expiresAt?->setTimezone(FilamentTimezone::get())->format('j. n. Y');
    }
}
