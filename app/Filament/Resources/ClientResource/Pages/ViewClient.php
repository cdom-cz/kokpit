<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The client detail page: billing data, terms and tags read-only, with the
 * archive and restore actions. Contacts, invitations and Partner accounts attach
 * here as relation managers in later plans.
 */
final class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    /**
     * A Partner is refused with 403 before the record is looked up, like on the
     * edit page: the deny-all scope of the client would answer 404 first.
     */
    public function mount(int|string $record): void
    {
        abort_unless(ClientResource::canAccess(), 403);

        parent::mount($record);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            ClientResource::archiveAction(DeleteAction::make()),
            ClientResource::restoreAction(RestoreAction::make()),
        ];
    }
}
