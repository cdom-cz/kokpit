<?php

declare(strict_types=1);

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * The list of every transaction, with "Nová transakce" as the one accent action.
 */
final class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    public function getTitle(): string
    {
        return __('finance.transactions.plural_label');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('finance.transactions.actions.create'))
                ->icon(Heroicon::OutlinedPlus),
        ];
    }
}
