<?php

declare(strict_types=1);

namespace App\Filament\Resources\CardPaymentResource\Pages;

use App\Domain\Payments\Models\CardPayment;
use App\Filament\Resources\CardPaymentResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * The record of one Stripe payment or refund event (UI-SPEC Surface H): the amount as the heading with the type and the
 * state, what happened, and the Stripe facts. Nothing is editable here.
 *
 * The page is Admin only and the check runs on every Livewire request: a Partner would otherwise be served a record
 * of an own document through the Partner scope of the model.
 */
final class ViewCardPayment extends ViewRecord
{
    protected static string $resource = CardPaymentResource::class;

    /**
     * The Admin check of every request, before any other code of the page runs. A Partner is refused with 403 before
     * the record is looked up.
     */
    public function boot(): void
    {
        abort_unless(CardPaymentResource::canAccess(), 403);
    }

    public function getTitle(): string
    {
        return $this->payment()->amount()->format('cs');
    }

    /**
     * The amount with the type and state badges.
     */
    public function getHeading(): Htmlable
    {
        $payment = $this->payment();
        $status = $payment->status();

        $badges = Blade::render(
            '<x-filament::badge :color="$typeColor" :icon="$typeIcon" style="vertical-align: middle;">{{ $type }}</x-filament::badge> <x-filament::badge :color="$statusColor" :icon="$statusIcon" style="vertical-align: middle;">{{ $state }}</x-filament::badge>',
            [
                'typeColor' => CardPaymentResource::typeColor($payment->type),
                'typeIcon' => CardPaymentResource::typeIcon($payment->type),
                'type' => $payment->type->getLabel(),
                'statusColor' => CardPaymentResource::statusColor($status),
                'statusIcon' => CardPaymentResource::statusIcon($status),
                'state' => $status->getLabel(),
            ],
        );

        return new HtmlString(e($this->getTitle()).' '.$badges);
    }

    /**
     * The received instant, under the heading.
     */
    public function getSubheading(): string
    {
        return $this->payment()->received_at->setTimezone(FilamentTimezone::get())->format('j. n. Y H:i');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private function payment(): CardPayment
    {
        $record = $this->getRecord();
        assert($record instanceof CardPayment);

        return $record;
    }
}
