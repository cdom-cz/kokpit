<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Models\Contact;
use App\Domain\Projects\Enums\BillingType;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
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

    /**
     * A Monday derived from the canary string, so two canaries never share a day or a week.
     */
    private static function signalMonday(string $canary): string
    {
        return CarbonImmutable::parse('2020-01-06')->addWeeks(crc32($canary) % 400)->toDateString();
    }

    /**
     * Creates what the fixtures need inside the test transaction: the canary
     * table, a fake media disk and the probe host for media and activity.
     * Pair every call with cleanup() in afterEach.
     */
    public static function prepare(): void
    {
        Canary::createTable();
        config(['media-library.disk_name' => 'local']);
        Storage::fake('local');

        self::$host = app(PartnerContext::class)->runAsSystem(static fn (): PackageProbe => PackageProbe::provision());
    }

    public static function cleanup(): void
    {
        self::$host = null;
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
            // Admin. The canary is the title, so a Partner reading it would be caught.
            Task::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
                    $project = Project::query()->where('client_id', $clientId)->where('name', $canary)->firstOrFail();

                    app(CreateTask::class)->handle(Canary::admin(), $project, ['title' => $canary]);
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

            // The planner "Signal" belongs to one Admin and is closed to Partners (OwnedByUser +
            // DeniesPartners). Each fixture writes one row of the Admin that carries the canary in a
            // title or a text field, so a Partner reading any of them would be caught. The day or
            // week is derived from the canary, so the two clients of one test never collide on the
            // unique keys of a day or a week (the base is a Monday).
            SignalTask::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalTask(['title' => $canary, 'for_date' => self::signalMonday($canary), 'category' => 'main']))
                        ->forceFill(['user_id' => Canary::admin()->getKey()])->save();
                });
            },

            SignalRecurringTask::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalRecurringTask(['title' => $canary, 'category' => 'main', 'weekday_mask' => 1]))
                        ->forceFill(['user_id' => Canary::admin()->getKey()])->save();
                });
            },

            SignalSetting::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function (): void {
                    if (! SignalSetting::query()->where('user_id', Canary::admin()->getKey())->exists()) {
                        (new SignalSetting(['deep_work_weekday_blocks' => 4, 'deep_work_weekend_blocks' => 1]))
                            ->forceFill(['user_id' => Canary::admin()->getKey()])->save();
                    }
                });
            },

            SignalDeepWorkDay::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalDeepWorkDay(['for_date' => self::signalMonday($canary), 'planned' => 3, 'completed' => 1]))
                        ->forceFill(['user_id' => Canary::admin()->getKey()])->save();
                });
            },

            SignalDayOverride::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalDayOverride(['for_date' => self::signalMonday($canary)]))
                        ->forceFill(['user_id' => Canary::admin()->getKey()])->save();
                });
            },

            SignalWeeklyGoal::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalWeeklyGoal(['week_start' => self::signalMonday($canary), 'title' => $canary, 'position' => 1]))
                        ->forceFill(['user_id' => Canary::admin()->getKey()])->save();
                });
            },

            SignalWeeklyRecap::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    (new SignalWeeklyRecap(['week_start' => self::signalMonday($canary), 'what_went_well' => $canary, 'what_to_change' => $canary]))
                        ->forceFill(['user_id' => Canary::admin()->getKey()])->save();
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
