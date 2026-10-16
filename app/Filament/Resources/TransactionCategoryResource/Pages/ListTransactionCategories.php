<?php

declare(strict_types=1);

namespace App\Filament\Resources\TransactionCategoryResource\Pages;

use App\Domain\Finance\Actions\SaveTransactionCategory;
use App\Filament\Resources\TransactionCategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The "Kategorie" screen of the Admin: the tree list with the create modal (FI-01, D-15).
 */
final class ListTransactionCategories extends ListRecords
{
    protected static string $resource = TransactionCategoryResource::class;

    public function getTitle(): string
    {
        return __('finance.categories.navigation');
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('finance.categories.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading(__('finance.categories.actions.create_heading'))
                ->modalWidth(Width::Large)
                ->successNotificationTitle(__('finance.categories.notifications.created'))
                ->schema(TransactionCategoryResource::fields())
                ->using(static function (array $data): Model {
                    try {
                        return app(SaveTransactionCategory::class)->handle(TransactionCategoryResource::actor(), null, $data);
                    } catch (ValidationException $e) {
                        throw TransactionCategoryResource::underModal($e);
                    }
                }),
        ];
    }
}
