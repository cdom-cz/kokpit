<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Clients\Models\Contact;
use App\Domain\Clients\Queries\ContactBirthdays;
use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\CollapsibleDashboardWidget;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Resources\ClientResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Narozeniny dnes" on the Admin dashboard: every contact whose birthday is today in Prague (CL-02, RE-04).
 *
 * The list is {@see ContactBirthdays}, the reader the API endpoint uses too, so the card and the API cannot disagree.
 * A contact with a known birth year shows the age it turns today ("41. narozeniny"); one without a year shows no age. A
 * 29 February birthday is listed on 28 February in a non-leap year. Contacts of an archived client are left out.
 *
 * A click on a row opens a modal with the whole contact and a link to the client detail. The card is always on the
 * Admin dashboard and collapsible; on a day without a birthday it shows a short empty state. Admin only: contacts are
 * personal data, so a Partner is refused at boot, before anything is read.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Birthdays are personal data of client contacts; a Partner is refused at boot and never sees the card.')]
final class BirthdaysToday extends TableWidget
{
    use CollapsibleDashboardWidget;
    use EnforcesWidgetAccessRule;

    protected static ?int $sort = 16;

    /** The page tests and the Admin's first look read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /** @var view-string */
    protected string $view = 'filament.widgets.dashboard-table-widget';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    public static function dashboardWidget(): DashboardWidget
    {
        return DashboardWidget::BirthdaysToday;
    }

    protected function cardHeading(): string
    {
        return __('kokpit.dashboard.birthdays.heading');
    }

    /**
     * How many contacts have a birthday today, or that nobody has.
     */
    protected function collapsedSummary(): string
    {
        $reader = app(ContactBirthdays::class);
        $count = $reader->query($reader->today())->reorder()->count();

        return trans_choice('kokpit.dashboard.birthdays.summary', $count, ['count' => $count]);
    }

    public function table(Table $table): Table
    {
        return $table
            // The card carries the title; without this the table makes a heading from the class name.
            ->heading(null)
            ->query(fn (): Builder => $this->birthdays())
            ->defaultKeySort(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('kokpit.dashboard.birthdays.columns.contact'))
                    ->description(static fn (Model $record): ?string => $record instanceof Contact ? $record->position : null),
                TextColumn::make('client.name')
                    ->label(__('kokpit.dashboard.birthdays.columns.client')),
                TextColumn::make('age')
                    ->label(__('kokpit.dashboard.birthdays.columns.age'))
                    ->state(fn (Model $record): ?string => $this->ageLabel($record))
                    ->placeholder(__('kokpit.contacts.empty_value')),
            ])
            ->paginated(false)
            ->recordAction('showContact')
            ->recordActions([$this->showContactAction()])
            ->emptyStateIcon(Heroicon::OutlinedCake)
            ->emptyStateHeading(__('kokpit.dashboard.birthdays.empty_heading'))
            ->emptyStateDescription(__('kokpit.dashboard.birthdays.empty_description'));
    }

    /**
     * The modal of a row: the whole contact, read only, with a link to the client. A ViewAction, so the Contact policy
     * (Admin only) decides who may open it.
     */
    private function showContactAction(): ViewAction
    {
        return ViewAction::make('showContact')
            ->label(__('kokpit.dashboard.birthdays.show'))
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading(static fn (Model $record): string => $record instanceof Contact ? $record->name : '')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('kokpit.dashboard.birthdays.close'))
            ->schema([
                TextEntry::make('client_name')
                    ->label(__('kokpit.dashboard.birthdays.columns.client'))
                    ->state(static fn (Model $record): ?string => $record instanceof Contact ? $record->client?->name : null)
                    ->placeholder(__('kokpit.contacts.empty_value')),
                TextEntry::make('email')
                    ->label(__('kokpit.contacts.fields.email'))
                    ->state(static fn (Model $record): ?string => $record instanceof Contact ? $record->email : null)
                    ->url(static fn (Model $record): ?string => $record instanceof Contact && $record->email !== null ? 'mailto:'.$record->email : null)
                    ->placeholder(__('kokpit.contacts.empty_value')),
                TextEntry::make('phone')
                    ->label(__('kokpit.contacts.fields.phone'))
                    ->state(static fn (Model $record): ?string => $record instanceof Contact ? $record->phone : null)
                    ->placeholder(__('kokpit.contacts.empty_value')),
                TextEntry::make('position')
                    ->label(__('kokpit.contacts.fields.position'))
                    ->state(static fn (Model $record): ?string => $record instanceof Contact ? $record->position : null)
                    ->placeholder(__('kokpit.contacts.empty_value')),
                TextEntry::make('birthday')
                    ->label(__('kokpit.contacts.fields.birthday'))
                    ->state(static fn (Model $record): ?string => $record instanceof Contact ? $record->birthdayLabel() : null)
                    ->placeholder(__('kokpit.contacts.empty_value')),
                TextEntry::make('age')
                    ->label(__('kokpit.dashboard.birthdays.columns.age'))
                    ->state(fn (Model $record): ?string => $this->ageLabel($record))
                    ->placeholder(__('kokpit.contacts.empty_value')),
                IconEntry::make('is_primary')
                    ->label(__('kokpit.contacts.fields.is_primary'))
                    ->state(static fn (Model $record): bool => $record instanceof Contact && $record->is_primary)
                    ->boolean(),
                IconEntry::make('is_billing')
                    ->label(__('kokpit.contacts.fields.is_billing'))
                    ->state(static fn (Model $record): bool => $record instanceof Contact && $record->is_billing)
                    ->boolean(),
            ])
            ->extraModalFooterActions(static fn (Model $record): array => [
                Action::make('openClient')
                    ->label(__('kokpit.dashboard.birthdays.open_client'))
                    ->url($record instanceof Contact ? ClientResource::getUrl('view', ['record' => $record->client_id]) : null),
            ]);
    }

    /**
     * "41. narozeniny" for a contact with a known birth year, null without one.
     */
    private function ageLabel(Model $record): ?string
    {
        if (! $record instanceof Contact) {
            return null;
        }

        $age = $record->ageOn(app(ContactBirthdays::class)->today());

        return $age === null ? null : __('kokpit.dashboard.birthdays.age', ['age' => $age]);
    }

    /**
     * @return Builder<Contact>
     */
    private function birthdays(): Builder
    {
        $reader = app(ContactBirthdays::class);

        return $reader->query($reader->today());
    }
}
