<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Database\CzechCollation;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Partner\Resources\PartnerProjectResource;
use App\Filament\Support\ProjectColumns;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Moje projekty" on the Partner dashboard (D-12, UI-SPEC Surface F2): the own client's visible
 * projects, at most ten, ordered by name in Czech collation.
 *
 * The query is the scoped `Project` model and never removes the scope: only the own client's
 * client-visible, non-archived projects exist for a Partner. The columns are taken by name from
 * the pinned Partner builder `ProjectColumns::partnerColumns()`, so the formatting matches the
 * Partner project list; no column outside that list is built or selected.
 *
 * Visible to a Partner with a client only; the Admin has no Partner dashboard, so a forged mount
 * is refused (the declaration admits the Admin, `canView()` narrows it).
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'The Partner dashboard lists the own client\'s visible projects through the Partner scope with the pinned Partner columns; the Admin is refused by canView().')]
final class PartnerProjects extends TableWidget
{
    use EnforcesWidgetAccessRule;

    /** The most rows the widget shows. */
    public const int LIMIT = 10;

    /**
     * The columns the widget shows, all of them Partner project fields.
     *
     * @var list<string>
     */
    public const array FIELDS = ['name', 'key', 'status', 'priority', 'end_date'];

    protected static ?int $sort = 20;

    /** The page tests and the empty-state decision read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    /**
     * The declaration AND a Partner with a client, and not the Admin: like the Partner resources,
     * so the Admin never gets a Partner widget.
     */
    public static function canView(): bool
    {
        $context = app(PartnerContext::class);

        return AccessRules::allows(self::class)
            && ! $context->isAdmin()
            && $context->partnerClientId() !== null;
    }

    /**
     * How many projects the Partner can see, the rows beyond the limit included.
     */
    public static function visibleProjectCount(): int
    {
        return Project::query()->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('kokpit.dashboard.partner_projects.heading'))
            ->query(static fn (): Builder => CzechCollation::orderBy(
                Project::query()->select(['id', ...self::FIELDS]),
                'name',
            )->orderBy('id')->limit(self::LIMIT))
            ->columns($this->columns())
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            ->emptyStateHeading(__('kokpit.dashboard.partner_projects.empty_heading'))
            ->emptyStateDescription(__('kokpit.dashboard.partner_projects.empty_description'));
    }

    /**
     * The pinned Partner columns restricted to `FIELDS`, without search and sort (the widget has
     * neither), the name linking to the Partner project page.
     *
     * @return list<Column>
     */
    private function columns(): array
    {
        $columns = [];

        foreach (ProjectColumns::partnerColumns() as $column) {
            if (! in_array($column->getName(), self::FIELDS, true)) {
                continue;
            }

            $column->searchable(false)->sortable(false);

            if ($column->getName() === 'name') {
                $column->url(static fn (Project $record): string => PartnerProjectResource::getUrl('view', ['record' => $record]));
            }

            $columns[] = $column;
        }

        return $columns;
    }
}
