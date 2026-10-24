<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Models\Contact;
use App\Domain\Clients\Notifications\PartnerInvitation;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Documents\Notifications\DocumentArchiveFailedNotification;
use App\Domain\Documents\Notifications\DocumentArchiveReadyNotification;
use App\Domain\Documents\Notifications\PartnerUploadedFilesNotification;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Notifications\ResetPasswordNotification;
use App\Domain\Invoicing\Mail\InvoiceMail;
use App\Domain\Invoicing\Mail\PaymentConfirmationMail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Models\PaymentConfirmation;
use App\Domain\Invoicing\Notifications\InvoiceEmailFailedNotification;
use App\Domain\Operations\Alerts\OperationalAlert;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Notifications\CardPaymentMatchedNotification;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Notifications\ReportExportFailedNotification;
use App\Domain\Reports\Notifications\ReportExportReadyNotification;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
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
use App\Domain\Tasks\Notifications\TaskChangedNotification;
use App\Domain\Tasks\Notifications\TaskCommentedNotification;
use App\Domain\Tasks\Notifications\TaskCreatedNotification;
use App\Domain\Tasks\Notifications\TaskEscalatedNotification;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Notifications\LongRunningTimerNotification;

/**
 * The entity x channel canary matrix (AUD-01; D-07, D-08, D-12; R-12-05-1).
 *
 * One declaration over CanaryRegistry (D-07): for every entity of the registry, one cell for each of the eight
 * channels through which data can leave the application toward a Partner. It is an audit on top of the existing
 * tests/Isolation files (D-08), which stay as they are. It is edited in the pull request that adds an entity or a
 * channel, so a new isolated model, or a new channel, shows up as a diff here; tests/Audit/ChannelMatrixTest.php
 * fails on an entity without a row, a row without an entity, a missing channel, an unknown cell value and a
 * not-applicable cell without a written reason.
 *
 * A cell is one of
 *  - 'foreign': Partner A may see the rows of the own client in this channel, never the rows of client B;
 *  - 'none': Partner A sees the rows of neither client in this channel (the entity is closed to a Partner);
 *  - ['na' => reason]: the channel cannot carry this entity at all; the reason is written, because a wrong
 *    not-applicable cell would hide a leak. When unsure, a cell is declared applicable: the check is cheap.
 *
 * The channels are ui (pages and tables), search (global search), selects (options of selects and select filters),
 * exports (generated report files), notifications (the database notification bell), activity (the activity log and
 * its history relation managers), downloads (files served by signed routes) and api (the Sanctum REST API).
 * Drivers for the channels live in ChannelProbe; a channel without a driver yet is declared here all the same and
 * is walked as soon as ChannelProbe::DRIVEN lists it.
 *
 * The same file declares the audience of every Notification and Mailable class under app/ (notificationClasses()),
 * because a notification class is the other way data reaches a Partner without a page being opened: a new class
 * fails ChannelMatrixTest until it is declared here with the audience it is written for and a reason (D-07).
 *
 * Pure data: nothing here calls application code, apart from the `::class` constants, which run nothing.
 */
final class ChannelMatrix
{
    /**
     * The eight channels, in the order a row lists them.
     *
     * @var list<string>
     */
    public const array CHANNELS = ['ui', 'search', 'selects', 'exports', 'notifications', 'activity', 'downloads', 'api'];

    /**
     * The audiences a notification or mail class can be written for: `partner` (sent to a Partner of the task's
     * client, with the my-tasks link), `admin` (an operational or finance message for the Admin only, never a
     * Partner), `client-mail` (a mail to a client address that is not a user account) and `any-user` (a message
     * about the account itself, sent to whoever owns it, a Partner included).
     *
     * @var list<string>
     */
    public const array NOTIFICATION_AUDIENCES = ['partner', 'admin', 'client-mail', 'any-user'];

    private const string FOREIGN = 'foreign';

    private const string NONE = 'none';

    /**
     * The entities that write activity rows are exactly the models in AuditDeclaration::loggingModels() plus
     * Activity itself; ChannelMatrixTest checks that the activity cell is applicable for those and for no other.
     */
    private const string NA_ACTIVITY = 'writes no activity rows: not in AuditDeclaration::loggingModels()';

    private const string NA_DOWNLOADS = 'no file is stored for or generated from this model; files leave only through the document, archive, invoice PDF and report export routes';

    private const string NA_EXPORTS = 'no export reads this model';

    /**
     * Every CanaryRegistry entity with its eight cells.
     *
     * @return array<class-string, array<string, string|array{na: string}>>
     */
    public static function entities(): array
    {
        $na = static fn (string $reason): array => ['na' => $reason];
        $activityNa = $na(self::NA_ACTIVITY);
        $downloadsNa = $na(self::NA_DOWNLOADS);
        $exportsNa = $na(self::NA_EXPORTS);

        return [
            CanaryRecord::class => ['ui' => self::FOREIGN, 'search' => self::FOREIGN, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            Client::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            Contact::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            ClientInvitation::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            Project::class => ['ui' => self::FOREIGN, 'search' => self::NONE, 'selects' => self::FOREIGN, 'exports' => self::NONE, 'notifications' => self::FOREIGN, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            ProjectBilling::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            Task::class => ['ui' => self::FOREIGN, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::FOREIGN, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            TaskChecklistItem::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            TaskBilling::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            TaskComment::class => ['ui' => self::FOREIGN, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::FOREIGN, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            Document::class => ['ui' => self::FOREIGN, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => self::FOREIGN, 'api' => self::NONE],
            TimeEntry::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => self::NONE, 'api' => self::NONE],
            Invoice::class => ['ui' => self::FOREIGN, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => self::FOREIGN, 'api' => self::NONE],
            InvoiceItem::class => ['ui' => self::FOREIGN, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => self::FOREIGN, 'api' => self::NONE],
            InvoiceAdmin::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            InvoiceItemSource::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            InvoicePayment::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            PaymentLink::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            CardPayment::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            InvoiceEmail::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            PaymentConfirmation::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            ExchangeRate::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            TransactionCategory::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            Transaction::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            DocumentArchive::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => self::NONE, 'api' => self::NONE],
            ReportExport::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => self::NONE, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => self::NONE, 'api' => self::NONE],
            SignalTask::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            SignalRecurringTask::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            SignalSetting::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            SignalDeepWorkDay::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            SignalDayOverride::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            SignalWeeklyGoal::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            SignalWeeklyRecap::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            Media::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => self::NONE, 'api' => self::NONE],
            Tag::class => ['ui' => self::FOREIGN, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            Activity::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => self::NONE, 'downloads' => $downloadsNa, 'api' => self::NONE],
            WebhookCall::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
            SettingsProperty::class => ['ui' => self::NONE, 'search' => self::NONE, 'selects' => self::NONE, 'exports' => $exportsNa, 'notifications' => self::NONE, 'activity' => $activityNa, 'downloads' => $downloadsNa, 'api' => self::NONE],
        ];
    }

    /**
     * Every concrete Notification and Mailable class under app/ with the audience it is written for and the reason
     * for it. ChannelMatrixTest discovers the classes by scanning app/ and requires this list to match both ways.
     * A class declared `admin` must never reach a Partner: the notifications driver fails when Partner A holds a
     * notification of one.
     *
     * @return array<class-string, array{audience: string, reason: string}>
     */
    public static function notificationClasses(): array
    {
        $partner = static fn (string $reason): array => ['audience' => 'partner', 'reason' => $reason];
        $admin = static fn (string $reason): array => ['audience' => 'admin', 'reason' => $reason];
        $clientMail = static fn (string $reason): array => ['audience' => 'client-mail', 'reason' => $reason];
        $anyUser = static fn (string $reason): array => ['audience' => 'any-user', 'reason' => $reason];

        $task = 'sent through TaskNotifier to the Admin and to the Partner requester or assignee, with the my-tasks link for a Partner';

        return [
            TaskCreatedNotification::class => $partner($task),
            TaskCommentedNotification::class => $partner($task),
            TaskChangedNotification::class => $partner($task),
            TaskEscalatedNotification::class => $partner($task),
            ResetPasswordNotification::class => $anyUser('password reset of any user, a Partner included; carries only the reset link'),
            PartnerInvitation::class => $clientMail('sent to the invited address before an account exists; carries only the signed invitation link'),
            InvoiceMail::class => $clientMail("sent to the client's invoice e-mail address; carries the client's own issued document"),
            PaymentConfirmationMail::class => $clientMail("sent to the client's invoice e-mail address; carries the client's own payment confirmation"),
            PartnerUploadedFilesNotification::class => $admin('tells the Admin that a Partner uploaded files, with a count and no file name'),
            DocumentArchiveReadyNotification::class => $admin('tells the Admin that a document archive is ready'),
            DocumentArchiveFailedNotification::class => $admin('tells the Admin that building a document archive failed'),
            ReportExportReadyNotification::class => $admin('tells the Admin that a report export is ready; reports are measured time and finance'),
            ReportExportFailedNotification::class => $admin('tells the Admin that a report export failed'),
            InvoiceEmailFailedNotification::class => $admin('tells the Admin that sending an invoice e-mail failed; invoices are finance'),
            CardPaymentMatchedNotification::class => $admin('tells the Admin that a card payment was matched; payments are finance'),
            LongRunningTimerNotification::class => $admin('tells the Admin that a timer runs long; measured time is closed to a Partner'),
            OperationalAlert::class => $admin('operational alert for the Admin'),
        ];
    }

    /**
     * What is wrong with the given notification declarations: an unknown audience and an empty reason. Empty when
     * they are fine. Pure over its argument, so the self-tests can feed it planted declarations.
     *
     * @param  array<string, array<string, mixed>>  $classes
     * @return list<string>
     */
    public static function notificationProblemsIn(array $classes): array
    {
        $problems = [];

        foreach ($classes as $class => $declaration) {
            $audience = $declaration['audience'] ?? null;
            $reason = $declaration['reason'] ?? null;

            if (! is_string($audience) || ! in_array($audience, self::NOTIFICATION_AUDIENCES, true)) {
                $problems[] = "{$class} declares an unknown audience";
            }

            if (! is_string($reason) || trim($reason) === '') {
                $problems[] = "{$class} declares no reason for its audience";
            }
        }

        sort($problems);

        return $problems;
    }

    /**
     * The applicable cells of the given channels, keyed "{ShortModel} x {channel}", each as [model, channel]
     * (plain arrays, so a Pest dataset can spread them into two arguments).
     *
     * @param  list<string>  $channels
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function cells(array $channels): array
    {
        $cells = [];

        foreach (self::entities() as $model => $row) {
            foreach ($channels as $channel) {
                if (isset($row[$channel]) && ! is_array($row[$channel])) {
                    $cells[class_basename($model).' x '.$channel] = [$model, $channel];
                }
            }
        }

        return $cells;
    }

    /**
     * What is wrong with the given rows: a missing or unknown channel, an unknown cell value and a
     * not-applicable cell without a written reason. Empty when the rows are fine. Pure over its argument, so the
     * self-tests can feed it planted rows.
     *
     * @param  array<string, array<string, mixed>>  $entities
     * @return list<string>
     */
    public static function problemsIn(array $entities): array
    {
        $problems = [];

        foreach ($entities as $model => $row) {
            foreach (self::CHANNELS as $channel) {
                if (! array_key_exists($channel, $row)) {
                    $problems[] = "{$model} has no cell for the channel {$channel}";

                    continue;
                }

                $problem = self::cellProblem($row[$channel]);

                if ($problem !== null) {
                    $problems[] = "{$model} x {$channel}: {$problem}";
                }
            }

            foreach (array_diff(array_keys($row), self::CHANNELS) as $channel) {
                $problems[] = "{$model} declares the unknown channel {$channel}";
            }
        }

        sort($problems);

        return $problems;
    }

    /**
     * What is wrong with the declared rows and the declared notification classes; empty when they are fine.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        return [...self::problemsIn(self::entities()), ...self::notificationProblemsIn(self::notificationClasses())];
    }

    private static function cellProblem(mixed $cell): ?string
    {
        if ($cell === self::FOREIGN || $cell === self::NONE) {
            return null;
        }

        if (is_array($cell) && array_keys($cell) === ['na']) {
            return is_string($cell['na']) && trim($cell['na']) !== '' ? null : 'a not-applicable cell needs a written reason';
        }

        return 'the cell is none of foreign, none or a not-applicable cell with a reason';
    }
}
