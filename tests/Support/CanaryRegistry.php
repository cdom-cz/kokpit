<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
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
