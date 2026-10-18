<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Enums;

/**
 * The dashboard cards whose collapsed state a user can store. The values are what the database CHECK
 * `dashboard_widget_preferences_widget_key_check` allows; a new dashboard widget needs a new case and a migration
 * that widens it.
 */
enum DashboardWidget: string
{
    case HoursStats = 'hours_stats';
    case TodayPlan = 'today_plan';
    case UnpaidInvoices = 'unpaid_invoices';
    case UnresolvedPayments = 'unresolved_payments';
    case UnbilledPerClient = 'unbilled_per_client';
    case UpcomingDeadlines = 'upcoming_deadlines';
    case PartnerTasks = 'partner_tasks';
    case PartnerProjects = 'partner_projects';

    /**
     * Whether a Partner can see the card, and so may store its state.
     */
    public function isPartnerWidget(): bool
    {
        return match ($this) {
            self::PartnerTasks, self::PartnerProjects => true,
            self::HoursStats, self::TodayPlan, self::UnpaidInvoices, self::UnresolvedPayments, self::UnbilledPerClient, self::UpcomingDeadlines => false,
        };
    }
}
