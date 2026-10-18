<?php

declare(strict_types=1);

namespace App\Domain\Payments\Queries;

use App\Domain\Payments\Enums\CardPaymentTab;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * What waits for the Admin among the card payment records (PA-08, ruling R11-11-3): the unresolved duplicates, unmatched
 * payments and refund events, and the counts of the tabs of the overview. Each figure set is one aggregate query, so
 * the navigation badge, the tab counts and the dashboard card cost the same however many records there are.
 *
 * The records are read through the model, so the Partner scope applies; they hold Stripe references and notes, which
 * a Partner never sees, so the reader also refuses everyone but the Admin and a system run.
 */
final class UnresolvedPayments
{
    /**
     * The unresolved records, in total and by type.
     *
     * @return array{total: int, duplicate: int, unmatched: int, refund: int}
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function counts(): array
    {
        $tabs = $this->tabCounts();

        return [
            'total' => $tabs[CardPaymentTab::Unresolved->value],
            'duplicate' => $tabs[CardPaymentTab::Duplicate->value],
            'unmatched' => $tabs[CardPaymentTab::Unmatched->value],
            'refund' => $tabs[CardPaymentTab::Refund->value],
        ];
    }

    /**
     * The number of records of every tab, keyed by the tab value.
     *
     * @return array<string, int>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function tabCounts(): array
    {
        $this->authorise();

        /** @var list<literal-string> $selects */
        $selects = [];

        foreach (CardPaymentTab::cases() as $tab) {
            $selects[] = 'COUNT(*) FILTER (WHERE '.$tab->condition().') AS '.$tab->value;
        }

        $row = CardPayment::query()
            ->selectRaw(implode(', ', $selects))
            ->toBase()
            ->first();

        $counts = [];

        foreach (CardPaymentTab::cases() as $tab) {
            $counts[$tab->value] = (int) ($row->{$tab->value} ?? 0);
        }

        return $counts;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The card payment counts are available to the Admin only.');
        }
    }
}
