<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerInvoiceResource\Pages;

use App\Filament\Partner\Resources\PartnerInvoiceResource;
use Filament\Resources\Pages\ListRecords;

final class ListPartnerInvoices extends ListRecords
{
    protected static string $resource = PartnerInvoiceResource::class;

    public function getTitle(): string
    {
        return __('invoices.partner.navigation');
    }

    /**
     * The list is read-only: no create action.
     *
     * @return array<never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
