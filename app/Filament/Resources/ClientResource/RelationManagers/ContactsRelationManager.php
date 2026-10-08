<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Domain\Clients\Actions\CreateContact;
use App\Domain\Clients\Models\Client;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
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

    public function form(Schema $schema): Schema
    {
        return $schema->components([
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
            // Not a column: the Action decides what the flag means, `is_primary` is never bound.
            Checkbox::make('make_primary')
                ->label(__('kokpit.contacts.fields.make_primary'))
                ->helperText(__('kokpit.contacts.hints.make_primary'))
                ->default(false),
        ]);
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
                    ->using(function (array $data): Model {
                        $client = $this->getOwnerRecord();
                        assert($client instanceof Client);

                        try {
                            return app(CreateContact::class)->handle($client, $data, makePrimary: ($data['make_primary'] ?? false) === true);
                        } catch (ValidationException $e) {
                            // The Action reports plain data keys; the modal form lives under the action state path.
                            $mapped = [];

                            foreach ($e->errors() as $key => $messages) {
                                $mapped["mountedActions.0.data.{$key}"] = $messages;
                            }

                            throw ValidationException::withMessages($mapped);
                        }
                    }),
            ])
            ->emptyStateHeading(__('kokpit.contacts.empty_heading'))
            ->emptyStateDescription(__('kokpit.contacts.empty_description'));
    }
}
