<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
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
     * table, a fake media disk and the probe host for media, tags and activity.
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

            Tag::class => static function (string $clientId, string $canary): void {
                app(PartnerContext::class)->runAsSystem(static function () use ($canary): void {
                    self::host()->attachTag($canary);
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
