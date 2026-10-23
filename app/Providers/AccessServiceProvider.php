<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Models\Contact;
use App\Domain\Dashboard\Models\DashboardWidgetPreference;
use App\Domain\Dashboard\Policies\DashboardWidgetPreferencePolicy;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Documents\Policies\DocumentPolicy;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\UserPolicy;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Invoicing\Policies\InvoiceItemPolicy;
use App\Domain\Invoicing\Policies\InvoicePolicy;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Projects\Policies\ProjectPolicy;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
use App\Domain\Shared\Policies\AdminOnlyPolicy;
use App\Domain\Signal\Models\SignalDayOverride;
use App\Domain\Signal\Models\SignalDeepWorkDay;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Models\SignalSetting;
use App\Domain\Signal\Models\SignalTask;
use App\Domain\Signal\Models\SignalWeeklyGoal;
use App\Domain\Signal\Models\SignalWeeklyRecap;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use App\Domain\Tasks\Models\TaskChecklistItem;
use App\Domain\Tasks\Models\TaskComment;
use App\Domain\Tasks\Policies\TaskCommentPolicy;
use App\Domain\Tasks\Policies\TaskPolicy;
use App\Domain\TimeTracking\Models\TimeEntry;
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

        // Accounts are managed by the Admin only, from the client detail (US-02, D-04).
        Gate::policy(User::class, UserPolicy::class);

        // Projects are the only Partner-readable model of Phase 4: explicit grants
        // for the own client-visible projects, everything else stays denied.
        Gate::policy(Project::class, ProjectPolicy::class);

        // Billing terms (rates, prices, estimate, internal note) are Admin-only (D-05).
        Gate::policy(ProjectBilling::class, AdminOnlyPolicy::class);

        // Tasks are Partner-readable through their project; explicit grants only.
        Gate::policy(Task::class, TaskPolicy::class);

        // The private todo checklist of a task is the Admin's working note (TA-03, A1).
        Gate::policy(TaskChecklistItem::class, AdminOnlyPolicy::class);
        Gate::policy(TaskBilling::class, AdminOnlyPolicy::class);

        // Comments: a Partner reads the non-internal comments of the visible tasks and
        // writes new ones; nobody edits or deletes a comment (TA-04, D-08, A6).
        Gate::policy(TaskComment::class, TaskCommentPolicy::class);

        // Files: the Admin manages them; a Partner is granted nothing yet. The Partner grants are explicit and
        // added in plans 09-08 (read a shared file) and 09-10 (the own upload and delete).
        Gate::policy(Document::class, DocumentPolicy::class);

        // API tokens are minted and revoked by the Admin only (AP-01); strict authorization needs the
        // registration, because the token screen is a Resource on this model.
        Gate::policy(PersonalAccessToken::class, AdminOnlyPolicy::class);

        // Tracked time, its rates and its billing are Admin-only (TI-07): a Partner is granted nothing.
        Gate::policy(TimeEntry::class, AdminOnlyPolicy::class);

        // Public CNB data, but finance-like and closed to Partners like every rate.
        Gate::policy(ExchangeRate::class, AdminOnlyPolicy::class);

        // Finance is closed to Partners: the categories of income and expenses are the Admin's alone.
        Gate::policy(TransactionCategory::class, AdminOnlyPolicy::class);

        // Transactions are finance data, the Admin's alone (D-12).
        Gate::policy(Transaction::class, AdminOnlyPolicy::class);

        // A ZIP archive bundles files of every client: Admin-only, and the owner scope of the model keeps even a
        // second Admin away from it.
        Gate::policy(DocumentArchive::class, AdminOnlyPolicy::class);

        // An export request names the clients and periods of one Admin: Admin-only, and the owner scope of the
        // model keeps even a second Admin away from it.
        Gate::policy(ReportExport::class, AdminOnlyPolicy::class);

        // The collapsed state of a dashboard card: the Admin holds any card, a Partner only the cards a Partner can see
        // (explicit grant in the policy, everything else denied); the owner scope keeps everybody to the own rows.
        Gate::policy(DashboardWidgetPreference::class, DashboardWidgetPreferencePolicy::class);

        // Billing documents: a Partner views an issued document of the own client, nothing else (IN-09).
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(InvoiceItem::class, InvoiceItemPolicy::class);

        // The Admin-only half of a billing document (internal note, CNB snapshot, PDF link) and its payments are
        // closed to Partners, even for an issued invoice of the own client (IN-09, R-08-1).
        Gate::policy(InvoiceAdmin::class, AdminOnlyPolicy::class);
        Gate::policy(InvoicePayment::class, AdminOnlyPolicy::class);

        // The generation source of a line (rates, seconds) and the e-mail log are Admin-only too (IN-09, R-08-1).
        Gate::policy(InvoiceItemSource::class, AdminOnlyPolicy::class);
        Gate::policy(InvoiceEmail::class, AdminOnlyPolicy::class);

        // The confirmation e-mail of a payment (recipient, remaining amount, state) is Admin-only (Phase 13).
        Gate::policy(PaymentConfirmation::class, AdminOnlyPolicy::class);

        // The Stripe payment link of a document (state, amount, Stripe references) is Admin-only (Phase 11).
        Gate::policy(PaymentLink::class, AdminOnlyPolicy::class);

        // The record of a Stripe payment or refund event (Stripe references, resolution note) is Admin-only (Phase 11).
        Gate::policy(CardPayment::class, AdminOnlyPolicy::class);

        // The personal planner "Signal" belongs to one Admin and is closed to Partners; the owner scope
        // of the models keeps every Admin to the own rows.
        foreach ([SignalDayOverride::class, SignalDeepWorkDay::class, SignalRecurringTask::class, SignalSetting::class, SignalTask::class, SignalWeeklyGoal::class, SignalWeeklyRecap::class] as $model) {
            Gate::policy($model, AdminOnlyPolicy::class);
        }
    }
}
