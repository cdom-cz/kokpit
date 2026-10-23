<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\ItemGrouping;
use App\Domain\Invoicing\Queries\InvoiceableEntries;
use App\Domain\Invoicing\Support\InvoiceCurrencies;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Resources\InvoiceResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * "Nová faktura" (UI-SPEC Surface B): the modal that creates a draft invoice or proforma for one client, from the
 * unbilled time of a period or empty (IN-02; D-01, D-02, D-04, D-07). It sits on the document list and, with the
 * client locked, on the client page.
 *
 * Nothing here writes a row: the submit hands the form data to {@see CreateDraft}, which re-validates the client,
 * the projects, the period, the grouping and the currency, so a forged id changes nothing. The live result line is the
 * very summary the picker query computes ({@see InvoiceableEntries::summary()}), so what the Admin reads is what the
 * draft will hold. The Livewire state holds ids and scalars only; every list shown is read again through the scoped
 * queries on each update.
 */
final class CreateDraftAction
{
    /**
     * @param  Client|null  $lockedClient  the client of the page the modal sits on; the field is then fixed to it
     */
    public static function make(?Client $lockedClient = null): Action
    {
        $lockedId = $lockedClient?->id;

        return Action::make('createDraft')
            ->label(__('invoices.list.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->visible(static fn (): bool => InvoiceResource::canAccess())
            ->modalHeading(__('invoices.create.heading'))
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('invoices.create.submit'))
            ->modalCancelActionLabel(__('invoices.create.cancel'))
            ->schema(self::schema($lockedId))
            ->action(static function (array $data, Action $action) use ($lockedId): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();

                abort_unless($actor instanceof User, 403);

                // A locked modal never takes the client from the form: the page decides.
                if ($lockedId !== null) {
                    $data['client_id'] = $lockedId;
                }

                try {
                    $draft = app(CreateDraft::class)->handle($actor, self::payload($data));
                } catch (ValidationException $exception) {
                    throw self::underModal($exception);
                }

                Notification::make()->success()->title(__('invoices.create.toast'))->send();

                $action->redirect(InvoiceResource::getUrl('edit', ['record' => $draft]));
            });
    }

    /**
     * @return list<Callout|Grid|Group|Select|Text|ToggleButtons>
     */
    private static function schema(?string $lockedId): array
    {
        return [
            ToggleButtons::make('kind')
                ->label(__('invoices.create.fields.kind'))
                ->options([
                    InvoiceKind::Invoice->value => InvoiceKind::Invoice->getLabel(),
                    InvoiceKind::Proforma->value => InvoiceKind::Proforma->getLabel(),
                ])
                ->default(InvoiceKind::Invoice->value)
                ->inline()
                ->required(),
            Select::make('client_id')
                ->label(__('invoices.create.fields.client'))
                ->options(static fn (): array => self::clientOptions())
                ->default($lockedId)
                ->disabled($lockedId !== null)
                ->dehydrated()
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(static function (Set $set, mixed $state): void {
                    $client = self::client($state);

                    // A new client brings its own currency and, by default, all of its projects.
                    $set('currency', $client?->currency);
                    $set('project_ids', array_keys(self::projectOptions($client?->id)));
                })
                ->helperText(static fn (Get $get): ?string => self::clientHelper($get('client_id'))),
            ToggleButtons::make('source')
                ->label(__('invoices.create.fields.source'))
                ->options([
                    'time' => __('invoices.create.fields.source_time'),
                    'empty' => __('invoices.create.fields.source_empty'),
                ])
                ->default('time')
                ->inline()
                ->live()
                ->required(),
            Group::make([
                Select::make('period')
                    ->label(__('invoices.create.fields.period'))
                    ->options([
                        'this_month' => __('invoices.create.fields.period_this_month'),
                        'last_month' => __('invoices.create.fields.period_last_month'),
                        'custom' => __('invoices.create.fields.period_custom'),
                    ])
                    ->default('this_month')
                    ->native(false)
                    ->live()
                    ->required(),
                Grid::make(2)
                    ->visible(static fn (Get $get): bool => $get('period') === 'custom')
                    ->schema([
                        DatePicker::make('period_from')
                            ->label(__('invoices.create.fields.period_from'))
                            ->live()
                            ->required(),
                        DatePicker::make('period_to')
                            ->label(__('invoices.create.fields.period_to'))
                            ->live()
                            ->required(),
                    ]),
                Select::make('project_ids')
                    ->label(__('invoices.create.fields.projects'))
                    ->multiple()
                    ->options(static fn (Get $get): array => self::projectOptions(self::clientId($get('client_id'))))
                    ->default(static fn (): array => array_keys(self::projectOptions($lockedId)))
                    ->live(),
                Checkbox::make('without_project')
                    ->label(__('invoices.create.fields.without_project'))
                    ->default(true)
                    ->live(),
                ToggleButtons::make('grouping')
                    ->label(__('invoices.create.fields.grouping'))
                    ->options(ItemGrouping::class)
                    ->default(ItemGrouping::PerProject->value)
                    ->inline()
                    ->live()
                    ->required(),
            ])->visible(static fn (Get $get): bool => $get('source') === 'time'),
            Select::make('currency')
                ->label(__('invoices.create.fields.currency'))
                ->options(static fn (Get $get): array => self::currencyOptions(self::clientId($get('client_id'))))
                ->native(false)
                ->live(),
            Text::make(static fn (Get $get): string => self::currencyNote($get))
                ->color('gray')
                ->visible(static fn (Get $get): bool => self::currencyNote($get) !== ''),
            Callout::make(static fn (Get $get): string => self::liveLine($get))
                ->status(static fn (Get $get): string => (self::summary($get)['count'] ?? 0) === 0 ? 'warning' : 'info')
                ->visible(static fn (Get $get): bool => self::summary($get) !== null),
            Callout::make(static fn (Get $get): string => implode(' ', array_map(
                static fn (string $name): string => __('invoices.create.fixed_price', ['name' => $name]),
                self::summary($get)['fixed_price_projects'] ?? [],
            )))
                ->info()
                ->visible(static fn (Get $get): bool => (self::summary($get)['fixed_price_projects'] ?? []) !== []),
            Text::make(static function (Get $get): string {
                $count = self::summary($get)['reserved_elsewhere'] ?? 0;

                return trans_choice('invoices.create.reserved_elsewhere', $count, ['count' => $count]);
            })
                ->color('gray')
                ->visible(static fn (Get $get): bool => (self::summary($get)['reserved_elsewhere'] ?? 0) > 0),
        ];
    }

    /**
     * The data the Action takes: the period of a preset is turned into Prague days, a time source passes its filters
     * and an empty one nothing else.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function payload(array $data): array
    {
        $payload = [
            'client_id' => $data['client_id'] ?? null,
            'kind' => $data['kind'] ?? InvoiceKind::Invoice->value,
            'currency' => $data['currency'] ?? null,
            'source' => ($data['source'] ?? 'time') === 'empty' ? 'empty' : 'time',
        ];

        if ($payload['source'] === 'empty') {
            return $payload;
        }

        $period = self::period($data['period'] ?? 'this_month', $data['period_from'] ?? null, $data['period_to'] ?? null);

        return [
            ...$payload,
            'period_from' => $period['from'] ?? ($data['period_from'] ?? null),
            'period_to' => $period['to'] ?? ($data['period_to'] ?? null),
            'project_ids' => is_array($data['project_ids'] ?? null) ? array_values($data['project_ids']) : [],
            'without_project' => (bool) ($data['without_project'] ?? false),
            'grouping' => $data['grouping'] ?? ItemGrouping::PerProject->value,
        ];
    }

    /**
     * The Prague days of the chosen period, or null for a custom period that is not a valid range yet.
     *
     * @return array{from: string, to: string}|null
     */
    public static function period(mixed $preset, mixed $from, mixed $to): ?array
    {
        if ($preset === 'custom') {
            $from = PragueDay::day($from);
            $to = PragueDay::day($to);

            return $from !== null && $to !== null && $from <= $to ? ['from' => $from, 'to' => $to] : null;
        }

        $today = CarbonImmutable::instance(TimerClock::now())->setTimezone(FilamentTimezone::get());
        $month = $preset === 'last_month' ? $today->subMonthNoOverflow() : $today;

        return ['from' => $month->startOfMonth()->format('Y-m-d'), 'to' => $month->endOfMonth()->format('Y-m-d')];
    }

    /**
     * The live result of the picker for the current form state, or null when there is nothing to compute (no client,
     * an empty source or a custom period that is not a range yet).
     *
     * @return array{count: int, seconds: int, estimate: Money|null, reserved_elsewhere: int, fixed_price_projects: list<string>, currency: string}|null
     */
    private static function summary(Get $get): ?array
    {
        if ($get('source') !== 'time') {
            return null;
        }

        $client = self::client($get('client_id'));
        $period = self::period($get('period') ?? 'this_month', $get('period_from'), $get('period_to'));

        if ($client === null || $period === null) {
            return null;
        }

        $currency = is_string($get('currency')) && $get('currency') !== '' ? strtoupper($get('currency')) : $client->currency;
        $projectIds = array_values(array_filter(
            is_array($get('project_ids')) ? $get('project_ids') : [],
            static fn (mixed $id): bool => is_string($id) && Str::isUuid($id),
        ));
        $grouping = self::grouping($get('grouping'));

        try {
            $summary = app(InvoiceableEntries::class)->summary(
                $client->id,
                $period['from'],
                $period['to'],
                $projectIds,
                (bool) $get('without_project'),
                $grouping,
                $currency,
                TimerClock::now(),
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        return [...$summary, 'currency' => $currency];
    }

    /**
     * The chosen grouping; the toggle holds the enum or its value, anything else is the default.
     */
    private static function grouping(mixed $state): ItemGrouping
    {
        if ($state instanceof ItemGrouping) {
            return $state;
        }

        return is_string($state) ? (ItemGrouping::tryFrom($state) ?? ItemGrouping::PerProject) : ItemGrouping::PerProject;
    }

    /**
     * "Nalezeno :count záznamů, :duration, odhad :amount", or the empty warning.
     */
    private static function liveLine(Get $get): string
    {
        $summary = self::summary($get);

        if ($summary === null || $summary['count'] === 0) {
            return __('invoices.create.live_none');
        }

        return trans_choice('invoices.create.live', $summary['count'], [
            'count' => $summary['count'],
            'duration' => DurationFormat::hoursMinutes($summary['seconds']),
            'amount' => $summary['estimate']?->format('cs') ?? __('invoices.navigation.empty_value'),
        ]);
    }

    /**
     * "Měna :currency, splatnost :days dní, jazyk dokladu :language." for the chosen client.
     */
    private static function clientHelper(mixed $clientId): ?string
    {
        $client = self::client($clientId);

        if ($client === null) {
            return null;
        }

        return __('invoices.create.client_helper', [
            'currency' => $client->currency,
            'days' => $client->payment_terms_days,
            'language' => mb_strtolower($client->invoice_language->getLabel()),
        ]);
    }

    /**
     * The note under the currency field when it differs from the currency of the client, otherwise an empty string.
     */
    private static function currencyNote(Get $get): string
    {
        $client = self::client($get('client_id'));
        $currency = $get('currency');

        if ($client === null || ! is_string($currency) || $currency === '' || strtoupper($currency) === $client->currency) {
            return '';
        }

        return __('invoices.create.currency_differs', ['client' => $client->currency]);
    }

    /**
     * An active client by id, or null: an archived client cannot be billed.
     */
    private static function client(mixed $id): ?Client
    {
        return is_string($id) && Str::isUuid($id) ? Client::query()->find($id) : null;
    }

    private static function clientId(mixed $id): ?string
    {
        return self::client($id)?->id;
    }

    /**
     * The active clients in Czech order.
     *
     * @return array<string, string>
     */
    private static function clientOptions(): array
    {
        $options = [];

        foreach (CzechCollation::orderBy(Client::query(), 'clients.name')->orderBy('clients.id')->get(['clients.id', 'clients.name']) as $client) {
            $options[$client->id] = $client->name;
        }

        return $options;
    }

    /**
     * The projects of the client the time can come from: the active ones, and the archived ones only while they hold
     * unbilled time that no draft holds (marked "(archivovaný)").
     *
     * @return array<string, string>
     */
    public static function projectOptions(?string $clientId): array
    {
        if ($clientId === null) {
            return [];
        }

        $options = [];

        $active = Project::query()->selectable()->where('projects.client_id', $clientId);

        foreach (CzechCollation::orderBy($active, 'projects.name')->orderBy('projects.id')->get(['projects.id', 'projects.name']) as $project) {
            $options[$project->id] = $project->name;
        }

        $archived = Project::query()->onlyTrashed()
            ->where('projects.client_id', $clientId)
            ->whereExists(static fn (QueryBuilder $entries): QueryBuilder => $entries->selectRaw('1')
                ->from('time_entries')
                ->whereColumn('time_entries.project_id', 'projects.id')
                ->whereNotNull('time_entries.ended_at')
                ->where('time_entries.billable', true)
                ->where('time_entries.billing_state', 'unbilled')
                ->whereNull('time_entries.invoice_id'));

        foreach (CzechCollation::orderBy($archived, 'projects.name')->orderBy('projects.id')->get(['projects.id', 'projects.name']) as $project) {
            $options[$project->id] = __('invoices.create.archived_project', ['name' => $project->name]);
        }

        return $options;
    }

    /**
     * The currencies the modal offers (D-07): those CNB publishes, those of the supplier's bank accounts, the currency
     * of the chosen client and CZK, from the one list the editors use. A currency without an account or a CNB list is a
     * blocker of issuing, not of the draft.
     *
     * @return array<string, string>
     */
    private static function currencyOptions(?string $clientId): array
    {
        $codes = InvoiceCurrencies::choices(self::client($clientId), null);

        return array_combine($codes, $codes);
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path. A key the form does not
     * show (the lines of a time draft) lands on the projects field, the closest one.
     */
    private static function underModal(ValidationException $exception): ValidationException
    {
        $fields = ['kind', 'client_id', 'currency', 'period_from', 'period_to', 'project_ids', 'grouping'];
        $mapped = [];

        foreach ($exception->errors() as $key => $messages) {
            $field = in_array($key, $fields, true) ? $key : 'project_ids';
            $path = 'mountedActions.0.data.'.$field;
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }
}
