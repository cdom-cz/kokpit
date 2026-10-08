<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Models\Contact;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Projects\Policies\ProjectPolicy;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
use App\Domain\Shared\Policies\AdminOnlyPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the data-layer access rules: the request-scoped Partner context and the
 * policy registrations of the models that carry no policy attribute (D-02).
 */
final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(), not singleton() or a plain binding: the system flag must
        // survive between resolutions of one request and reset for the next
        // request and for every queue job.
        $this->app->scoped(PartnerContext::class);
    }

    public function boot(): void
    {
        // The package models have no policy attribute of their own: Phase 2 closes
        // them to Partners. Later phases replace the registration deliberately.
        foreach ([Media::class, Tag::class, Activity::class, WebhookCall::class, SettingsProperty::class] as $model) {
            Gate::policy($model, AdminOnlyPolicy::class);
        }

        // Clients are Admin-only (Phase 4 D-06): a Partner is granted nothing.
        Gate::policy(Client::class, AdminOnlyPolicy::class);

        // Contacts are personal data of the client's people, Admin-only (D-06).
        Gate::policy(Contact::class, AdminOnlyPolicy::class);

        // Partner invitations carry the invitee's e-mail and a token hash, Admin-only (US-02).
        Gate::policy(ClientInvitation::class, AdminOnlyPolicy::class);

        // Projects are the only Partner-readable model of Phase 4: explicit grants
        // for the own client-visible projects, everything else stays denied.
        Gate::policy(Project::class, ProjectPolicy::class);

        // Billing terms (rates, prices, estimate, internal note) are Admin-only (D-05).
        Gate::policy(ProjectBilling::class, AdminOnlyPolicy::class);
    }
}
