<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Models\Contact;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Signal\Models\SignalDayOverride;
use App\Domain\Signal\Models\SignalDeepWorkDay;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Models\SignalSetting;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use App\Domain\Tasks\Actions\AddTaskComment;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Enums\TaskBillingType;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use App\Domain\Tasks\Models\TaskChecklistItem;
use App\Domain\Tasks\Models\TaskComment;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Probes\PackageProbe;

/**
 * One fixture line per PartnerIsolated model (D-04).
 *
 * Each fixture creates one row that carries the given canary string, for the
 * given client where the model has a notion of one. The registry test fails
 * when a PartnerIsolated model has no line, and the route walk and the registry
 * test search every Partner-visible surface for the canary of the other client.
 *
 * Later phases add one line per new PartnerIsolated model (D-04). A model that
 * is closed to Partners (DeniesPartners) carries the canary in a name, payload
 * or description field, which must stay invisible to every Partner anyway.
 */
final class CanaryRegistry
{
    private static ?PackageProbe $host = null;

    private static ?User $plannerOwner = null;

    /** @var array<string, string> canary => its own Monday */
    private static array $signalMondays = [];

    /**
     * The one Admin that owns every Signal fixture row of the current test. The owner scope limits even
     * the Admin to the own rows, so all Signal rows of one test must belong to one Admin, and a test that
     * wants to see them must act as this Admin. Memoised, and reset by prepare() and cleanup() because the
     * test transaction rolls the user back after every test.
     */
    public static function plannerOwner(): User
    {
        return self::$plannerOwner ??= Canary::admin();
    }

    /**
     * A Monday of the canary: the next unused one, counted from a fixed base, so the same canary has the
     * same Monday in every Signal fixture and two canaries never share a day or a week (the unique keys
     * of a day or a week would collide otherwise).
     */
    private static function signalMonday(string $canary): string
    {
        return self::$signalMondays[$canary] ??= CarbonImmutable::parse('2020-01-06')->addWeeks(count(self::$signalMondays))->toDateString();
    }

    /**
     * The strings the Admin must find in the visible rows of a model for one canary: the canary itself
     * for every model that stores text; the Monday of the canary for the models that have a date but
     * no text column (the two Signal day models and the exchange rate); the id of the planner owner for the
     * settings (one row per user, no text column).
     *
     * @param  class-string  $model
     * @return list<string>
     */
    public static function needles(string $model, string $canary): array
    {
        return match ($model) {
            SignalDeepWorkDay::class, SignalDayOverride::class, ExchangeRate::class => [self::signalMonday($canary)],
            SignalSetting::class => [(string) self::plannerOwner()->getKey()],
            default => [$canary],
        };
    }

    /**
     * Creates what the fixtures need inside the test transaction: the canary
     * table, a fake media disk and the probe host for media and activity.
     * Pair every call with cleanup() in afterEach.
     */
    public static function prepare(): void
    {
        self::$plannerOwner = null;
        self::$signalMondays = [];

        Canary::createTable();
        config(['media-library.disk_name' => 'local']);
        Storage::fake('local');

        self::$host = app(PartnerContext::class)->runAsSystem(static fn (): PackageProbe => PackageProbe::provision());
    }

    public static function cleanup(): void
    {
        self::$host = null;
        self::$plannerOwner = null;
        self::$signalMondays = [];
        PackageProbe::restoreMorphMap();
    }

    /**
     * @return array<class-string, Closure(string $clientId, string $canary): void>
     */
    public static function fixtures(): array
    {
        return [
            CanaryRecord::class => static function (string $clientId, string $canary): void {
                Canary::record($clientId, $canary);
            },

            // The client id is the identity every other fixture hangs off, so the
            // fixture writes the canary into the name of the existing client row.
            Client::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    Client::query()->whereKey($clientId)->firstOrFail()->forceFill(['name' => $canary])->save();
                });
            },

            // The canary is the name of the contact, so a Partner reading any contact
            // field would be caught; the e-mail is a fictional example.com address.
            Contact::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $client = Client::query()->whereKey($clientId)->firstOrFail();

                    $client->contacts()->create(['name' => $canary, 'email' => exampleEmail(), 'is_billing' => true]);
                });
            },

            // The canary is the invitee's name, so a Partner reading any invitation
            // field would be caught. The token hash is that of a random token.
            ClientInvitation::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $invitation = new ClientInvitation(['name' => $canary, 'email' => exampleEmail()]);
                    $invitation->forceFill([
                        'client_id' => $clientId,
                        'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
                        'expires_at' => now()->addDays(7),
                        'last_sent_at' => now(),
                    ])->save();
                });
            },

            // One client-visible project per client, directly after the Client
            // fixture so later fixtures (tags, project billing) can find it.
            Project::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $project = new Project(['name' => $canary, 'key' => Canary::projectKey(), 'client_visible' => true]);
                    $project->forceFill(['client_id' => $clientId])->save();
                });
            },

            // The Admin-only billing row of the canary project of that client (found
            // by name; the Project fixture runs before this one). The canary sits in
            // the internal note, so a Partner reading it would be caught.
            ProjectBilling::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $client = Client::query()->whereKey($clientId)->firstOrFail();
                    $project = Project::query()->where('client_id', $clientId)->where('name', $canary)->firstOrFail();

                    $project->billing()->create([
                        'billing_type' => BillingType::Hourly,
                        'hourly_rate' => Money::ofMinor(85000, $client->currency),
                        'internal_note' => $canary,
                    ]);
                });
            },

            // One task in the canary project of that client (found by name; the Project
            // fixture runs before this one), created through the real Action by an
            // Admin. The canary is the title, so a Partner reading it would be caught. The task is in
            // status in_review (Phase 8, UI-SPEC Access contract), so every test that renders the Partner dashboard
            // exercises the "Ke kontrole" highlight path of the open tasks widget.
            Task::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $project = Project::query()->where('client_id', $clientId)->where('name', $canary)->firstOrFail();

                    app(CreateTask::class)->handle(Canary::admin(), $project, ['title' => $canary, 'status' => ProjectStatus::InReview->value]);
                });
            },

            // One checklist item on the canary task of that client (found by its project
            // name; the Task fixture runs before this one). The canary is the item text,
            // so a Partner reading it would be caught.
            TaskChecklistItem::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $project = Project::query()->where('client_id', $clientId)->where('name', $canary)->firstOrFail();
                    $task = Task::query()->where('project_id', $project->getKey())->firstOrFail();

                    $task->checklistItems()->create(['text' => $canary, 'position' => 1]);
                });
            },

            // The Admin-only billing row of the canary task of that client (found by its
            // project name; the Task fixture runs before this one). The canary sits in the
            // internal note, so a Partner reading any billing field would be caught.
            TaskBilling::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $client = Client::query()->whereKey($clientId)->firstOrFail();
                    $project = Project::query()->where('client_id', $clientId)->where('name', $canary)->firstOrFail();
                    $task = Task::query()->where('project_id', $project->getKey())->firstOrFail();

                    $task->billing()->create([
                        'billing_type' => TaskBillingType::Hourly,
                        'hourly_rate' => Money::ofMinor(95000, $client->currency),
                        'internal_note' => $canary,
                    ]);
                });
            },

            // Two comments on the canary task of that client (found by its project name; the
            // Task fixture runs before this one): a visible one and an internal twin, both
            // carrying the canary. A Partner must read exactly the visible one, so the
            // exact-one-row check fails if the internal comment is ever returned.
            TaskComment::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $project = Project::query()->where('client_id', $clientId)->where('name', $canary)->firstOrFail();
                    $task = Task::query()->where('project_id', $project->getKey())->firstOrFail();
                    $admin = Canary::admin();

                    app(AddTaskComment::class)->handle($admin, $task, '<p>'.$canary.'</p>');
                    app(AddTaskComment::class)->handle($admin, $task, '<p>'.$canary.'</p>', internal: true);
                });
            },

            // Five files that all carry the canary in their name. The visible file of the canary project of
            // that client (found by name; the Project and TaskComment fixtures run before this one) is attached
            // through the real Action by an Admin. It has four hidden twins that a Partner must never read: a
            // hidden file of the same project, a file of the internal canary comment flagged visible by a system
            // forceFill (the CHECK allows it, only the scope drops it), a client-owned file and an unattached
            // file. The exact-one-row check fails if any twin is ever returned.
            Document::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $project = Project::query()->where('client_id', $clientId)->where('name', $canary)->firstOrFail();
                    $task = Task::query()->where('project_id', $project->getKey())->firstOrFail();
                    $internalComment = TaskComment::query()->where('task_id', $task->getKey())->where('is_internal', true)->firstOrFail();
                    $admin = Canary::admin();
                    $attach = app(AttachDocuments::class);
                    $file = static fn (string $suffix) => DocumentFixtures::upload($canary.$suffix.'.txt', 'Fictional file content '.$canary.$suffix);

                    $attach->handle($admin, $project, [$file('')]);
                    $attach->handle($admin, $project, [$file('-hidden')], visibleToClient: false);

                    $attach->handle($admin, $internalComment, [$file('-internal')])
                        ->each(static fn (Document $document) => $document->forceFill(['visible_to_client' => true])->save());

                    $attach->handle($admin, Client::query()->whereKey($clientId)->firstOrFail(), [$file('-client')]);
                    $attach->handle($admin, null, [$file('-unattached')]);
                });
            },

            // One finished time entry of the Admin on the canary project and canary task of
            // that client (found by the project name; the Task fixture runs before this
            // one). The canary is the description, so a Partner reading any entry would be
            // caught: measured time is closed to Partners.
            TimeEntry::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $project = Project::query()->where('client_id', $clientId)->where('name', $canary)->firstOrFail();
                    $task = Task::query()->where('project_id', $project->getKey())->firstOrFail();

                    (new TimeEntry(['description' => $canary]))->forceFill([
                        'user_id' => Canary::admin()->getKey(),
                        'client_id' => $clientId,
                        'project_id' => $project->getKey(),
                        'task_id' => $task->getKey(),
                        'started_at' => TimerClock::now()->subHours(2),
                        'ended_at' => TimerClock::now()->subHour(),
                    ])->save();
                });
            },

            // A CNB rate row has no text and no client: the canary is the published day (the Monday of the
            // canary), under a fictional currency code, so two canaries never collide on the unique key of a
            // currency and day. A Partner reading any rate would be caught by that day.
            ExchangeRate::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    $now = TimerClock::now();

                    ExchangeRate::query()->insert([
                        'rate_date' => self::signalMonday($canary),
                        'currency' => 'XTS',
                        'unit_amount' => 1,
                        'rate' => '1.5',
                        'fetched_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                });
            },

            // A finance category is Admin-only. The canary is the name of a root expense category (unique per
            // type, so two canaries never collide); a Partner reading any category would be caught by it.
            TransactionCategory::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new TransactionCategory(['name' => $canary]))->forceFill([
                        'type' => TransactionType::Expense,
                        'depth' => 0,
                    ])->save();
                });
            },

            // A finance transaction is Admin-only. The canary is the counterparty and the description of an
            // expense in the seeded "Software" category, tied to the client of the canary and booked in CZK
            // (identity), written in the system context; a Partner reading any transaction would be caught.
            Transaction::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $category = TransactionCategory::query()
                        ->where('type', TransactionType::Expense->value)
                        ->whereNull('parent_id')
                        ->where('name', 'Software')
                        ->sole();

                    (new Transaction(['counterparty' => $canary, 'description' => $canary]))->forceFill([
                        'type' => TransactionType::Expense,
                        'transaction_date' => '2026-10-14',
                        'category_id' => $category->getKey(),
                        'amount' => Money::ofMinor(123450, 'CZK'),
                        'czk_minor' => 123450,
                        'czk_source' => 'identity',
                        'client_id' => $clientId,
                    ])->save();
                });
            },

            // An export request belongs to one Admin and is closed to Partners (OwnedByUser + DeniesPartners).
            // The canary is the summary ("Zadání"); the row is a finished one with a fictional path, written in
            // the system context for the one planner owner, because the owner scope limits even an Admin.
            ReportExport::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    $now = TimerClock::now();

                    (new ReportExport(['format' => ReportExportFormat::Csv, 'summary' => $canary, 'filters' => ['from' => '2020-01-06', 'to' => '2020-01-12']]))
                        ->forceFill([
                            'user_id' => self::plannerOwner()->getKey(),
                            'status' => ReportExportStatus::Ready,
                            'path' => 'report-exports/'.Str::uuid().'.csv',
                            'row_count' => 3,
                            'completed_at' => $now,
                            'expires_at' => $now->addDays(7),
                        ])->save();
                });
            },

            // The planner "Signal" belongs to one Admin and is closed to Partners (OwnedByUser +
            // DeniesPartners). Each fixture writes one row of the Admin that carries the canary in a
            // title or a text field, so a Partner reading any of them would be caught. All rows belong to
            // plannerOwner(), because the owner scope limits even an Admin to the own rows. The day or
            // week is the Monday of the canary, so the two clients of one test never collide on the
            // unique keys of a day or a week.
            SignalTask::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalTask(['title' => $canary, 'for_date' => self::signalMonday($canary), 'category' => 'main']))
                        ->forceFill(['user_id' => self::plannerOwner()->getKey()])->save();
                });
            },

            SignalRecurringTask::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalRecurringTask(['title' => $canary, 'category' => 'main', 'weekdays' => [0]]))
                        ->forceFill(['user_id' => self::plannerOwner()->getKey()])->save();
                });
            },

            SignalSetting::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function (): void {
                    if (! SignalSetting::query()->where('user_id', self::plannerOwner()->getKey())->exists()) {
                        (new SignalSetting(['deep_work_weekday_blocks' => 4, 'deep_work_weekend_blocks' => 1]))
                            ->forceFill(['user_id' => self::plannerOwner()->getKey()])->save();
                    }
                });
            },

            SignalDeepWorkDay::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalDeepWorkDay(['for_date' => self::signalMonday($canary), 'planned' => 3, 'completed' => 1]))
                        ->forceFill(['user_id' => self::plannerOwner()->getKey()])->save();
                });
            },

            SignalDayOverride::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalDayOverride(['for_date' => self::signalMonday($canary)]))
                        ->forceFill(['user_id' => self::plannerOwner()->getKey()])->save();
                });
            },

            SignalWeeklyGoal::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalWeeklyGoal(['week_start' => self::signalMonday($canary), 'title' => $canary, 'position' => 1]))
                        ->forceFill(['user_id' => self::plannerOwner()->getKey()])->save();
                });
            },

            SignalWeeklyRecap::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalWeeklyRecap(['week_start' => self::signalMonday($canary), 'what_went_well' => $canary, 'what_to_change' => $canary]))
                        ->forceFill(['user_id' => self::plannerOwner()->getKey()])->save();
                });
            },

            Media::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    self::host()
                        ->addMediaFromString('Fictional file content '.$canary)
                        ->usingName($canary)
                        ->usingFileName(strtolower($canary).'.txt')
                        ->withCustomProperties(['client_id' => $clientId])
                        ->toMediaCollection('canary');
                });
            },

            // A project-type tag carrying the canary, attached to the canary
            // Project of that client (found by name; the Project fixture runs
            // before this one). A Partner sees exactly this tag of the own client
            // (D-07) and none of the other client's.
            Tag::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $project = Project::query()->where('client_id', $clientId)->where('name', $canary)->firstOrFail();
                    $project->attachTag($canary, TagType::Project->value);
                });
            },

            Activity::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    activity()->performedOn(self::host())->withProperties(['client_id' => $clientId])->log($canary);
                });
            },

            WebhookCall::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    /** @var class-string<WebhookCall> $model */
                    $model = config('webhook-client.configs.0.webhook_model');

                    $model::create([
                        'name' => 'default',
                        'url' => 'https://'.implode('.', ['example', 'com']).'/hook',
                        'payload' => ['client_id' => $clientId, 'note' => $canary],
                    ]);
                });
            },

            SettingsProperty::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    SettingsProperty::query()->create([
                        'group' => 'canary',
                        'name' => $canary,
                        'payload' => json_encode(['client_id' => $clientId, 'note' => $canary], JSON_THROW_ON_ERROR),
                    ]);
                });
            },
        ];
    }

    /**
     * Writes the fixtures of every registered model for one client.
     */
    public static function seedAll(string $clientId, string $canary): void
    {
        foreach (self::fixtures() as $fixture) {
            $fixture($clientId, $canary);
        }
    }

    private static function host(): PackageProbe
    {
        return self::$host ?? throw new \LogicException('CanaryRegistry::prepare() was not called.');
    }
}
