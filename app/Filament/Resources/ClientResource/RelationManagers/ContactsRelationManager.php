<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Domain\Clients\Actions\CreateContact;
use App\Domain\Clients\Actions\DeleteContact;
use App\Domain\Clients\Actions\SetPrimaryContact;
use App\Domain\Clients\Actions\UpdateContact;
use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The "Kontakty" tab of the client detail (CL-02, D-12): the people of a client
 * with their billing flag and the one primary contact. Admin only (D-06).
 *
 * A contact is written only through the domain Actions. The primary flag is not a
 * form field: the Action decides which contact is primary, so a crafted Livewire
 * payload cannot set it. The partial unique index behind it refuses a second
 * primary in any case.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Contacts are personal data of the people of a client; a Partner never sees them.')]
final class ContactsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'contacts';

    /**
     * The tab is on the read-only detail page, but the Admin maintains contacts
     * there, so Filament's read-only default for view pages does not apply.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kokpit.contacts.relation_title');
    }

    /**
     * The form of the edit modal: the data fields only. The primary flag is not a
     * field; it moves through the "Nastavit jako primární" row action.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components($this->fields());
    }

    /**
     * @return list<Component>
     */
    private function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('kokpit.contacts.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label(__('kokpit.contacts.fields.email'))
                ->email()
                ->maxLength(255),
            TextInput::make('phone')
                ->label(__('kokpit.contacts.fields.phone'))
                ->tel()
                ->maxLength(50),
            TextInput::make('position')
                ->label(__('kokpit.contacts.fields.position'))
                ->maxLength(255),
            Toggle::make('is_billing')
                ->label(__('kokpit.contacts.fields.is_billing'))
                ->helperText(__('kokpit.contacts.hints.is_billing')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('kokpit.contacts.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('kokpit.contacts.fields.email'))
                    ->placeholder(__('kokpit.contacts.empty_value')),
                TextColumn::make('phone')
                    ->label(__('kokpit.contacts.fields.phone'))
                    ->placeholder(__('kokpit.contacts.empty_value')),
                TextColumn::make('position')
                    ->label(__('kokpit.contacts.fields.position'))
                    ->placeholder(__('kokpit.contacts.empty_value')),
                IconColumn::make('is_primary')
                    ->label(__('kokpit.contacts.fields.is_primary'))
                    ->boolean(),
                IconColumn::make('is_billing')
                    ->label(__('kokpit.contacts.fields.is_billing'))
                    ->boolean(),
            ])
            ->defaultSort(static fn ($query) => $query->orderByDesc('is_primary')->orderBy('name')->orderBy('id'))
            ->headerActions([
                CreateAction::make()
                    ->label(__('kokpit.contacts.actions.create'))
                    ->modalHeading(__('kokpit.contacts.actions.create_heading'))
                    ->successNotificationTitle(__('kokpit.contacts.notifications.created'))
                    // Not a column: the Action decides what the flag means, `is_primary` is never bound.
                    ->schema([
                        ...$this->fields(),
                        Checkbox::make('make_primary')
                            ->label(__('kokpit.contacts.fields.make_primary'))
                            ->helperText(__('kokpit.contacts.hints.make_primary'))
                            ->default(false),
                    ])
                    ->using(function (array $data): Model {
                        $client = $this->getOwnerRecord();
                        assert($client instanceof Client);

                        try {
                            return app(CreateContact::class)->handle($client, $data, makePrimary: ($data['make_primary'] ?? false) === true);
                        } catch (ValidationException $e) {
                            throw $this->underModal($e);
                        }
                    }),
            ])
            ->recordActions([
                Action::make('setPrimary')
                    ->label(__('kokpit.contacts.actions.set_primary'))
                    ->icon(Heroicon::OutlinedStar)
                    ->requiresConfirmation()
                    ->modalHeading(__('kokpit.contacts.actions.set_primary_heading'))
                    ->modalDescription(__('kokpit.contacts.actions.set_primary_description'))
                    ->successNotificationTitle(__('kokpit.contacts.notifications.primary_set'))
                    ->hidden(static fn (Model $record): bool => $record instanceof Contact && $record->is_primary)
                    ->action(function (Model $record, Action $action): void {
                        assert($record instanceof Contact);

                        app(SetPrimaryContact::class)->handle($record);

                        $action->success();
                    }),
                EditAction::make()
                    ->modalHeading(__('kokpit.contacts.actions.edit_heading'))
                    ->successNotificationTitle(__('kokpit.contacts.notifications.updated'))
                    ->using(function (Model $record, array $data): Model {
                        assert($record instanceof Contact);

                        try {
                            return app(UpdateContact::class)->handle($record, $data);
                        } catch (ValidationException $e) {
                            throw $this->underModal($e);
                        }
                    }),
                DeleteAction::make()
                    ->modalHeading(__('kokpit.contacts.actions.delete_heading'))
                    ->modalDescription(__('kokpit.contacts.actions.delete_description'))
                    ->successNotificationTitle(__('kokpit.contacts.notifications.deleted'))
                    ->using(static function (Model $record, DeleteAction $action): bool {
                        assert($record instanceof Contact);

                        try {
                            app(DeleteContact::class)->handle($record);
                        } catch (ValidationException) {
                            // The primary of a multi-contact client stays: show why instead of a field error.
                            $action->failureNotificationTitle(__('kokpit.contacts.errors.primary_delete'));

                            return false;
                        }

                        return true;
                    }),
            ])
            ->emptyStateHeading(__('kokpit.contacts.empty_heading'))
            ->emptyStateDescription(__('kokpit.contacts.empty_description'));
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path.
     */
    private function underModal(ValidationException $e): ValidationException
    {
        $mapped = [];

        foreach ($e->errors() as $key => $messages) {
            $mapped["mountedActions.0.data.{$key}"] = $messages;
        }

        return ValidationException::withMessages($mapped);
    }
}
