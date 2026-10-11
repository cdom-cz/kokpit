<?php

declare(strict_types=1);

namespace App\Filament\Pages\Signal;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Signal\Actions\DeleteSignalRecurring;
use App\Domain\Signal\Actions\SaveSignalRecurring;
use App\Domain\Signal\Actions\SaveSignalSettings;
use App\Domain\Signal\Actions\ToggleSignalRecurring;
use App\Domain\Signal\Enums\SignalCategory;
use App\Domain\Signal\Models\SignalRecurringTask;
use App\Domain\Signal\Queries\SignalDayReader;
use App\Domain\Signal\Queries\SignalOverviewReader;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Concerns\InSignalGroup;
use App\Livewire\Signal\RunsSignalActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

/**
 * "Nastavení" of the planner: the default number of deep-work blocks and the recurring task templates
 * (title, colour, weekdays). Everything is saved by Livewire requests.
 *
 * @property-read list<array{id: string, title: string, category: string, label: string, color: string, active: bool, weekdays: list<int>}> $templates
 */
#[AccessRule(Audience::AdminOnly, reason: 'The planner settings belong to one Admin; a Partner has no part of them.')]
class SignalSettingsPage extends Page
{
    use EnforcesPageAccessRule, InSignalGroup, RunsSignalActions;

    protected static ?string $slug = 'signal/settings';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.signal.settings';

    public int $weekdayBlocks = 3;

    public int $weekendBlocks = 0;

    /** The template being edited; null while a new one is entered. */
    public ?string $editingTemplateId = null;

    public string $templateTitle = '';

    public string $templateCategory = 'main';

    /** @var list<int|string> The weekday indexes 0 (Monday) .. 6 (Sunday) ticked in the form. */
    public array $templateWeekdays = [];

    public static function getNavigationLabel(): string
    {
        return __('kokpit.signal.settings.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedCog6Tooth;
    }

    public function getTitle(): string
    {
        return __('kokpit.signal.settings.title');
    }

    public function mount(): void
    {
        $settings = app(SignalDayReader::class)->blockSettings();
        $this->weekdayBlocks = $settings['weekday'];
        $this->weekendBlocks = $settings['weekend'];
    }

    public function saveBlocks(): void
    {
        if ($this->attempt(fn ($actor) => app(SaveSignalSettings::class)->handle($actor, $this->weekdayBlocks, $this->weekendBlocks))) {
            Notification::make()->title(__('kokpit.signal.settings.saved'))->success()->send();
            $this->dispatch('signal-changed');
        }
    }

    /**
     * @return list<array{id: string, title: string, category: string, label: string, color: string, active: bool, weekdays: list<int>}>
     */
    #[Computed]
    public function templates(): array
    {
        return app(SignalOverviewReader::class)->recurringTemplates()
            ->map(static fn (SignalRecurringTask $template): array => [
                'id' => $template->id,
                'title' => $template->title,
                'category' => $template->category->value,
                'label' => $template->category->getLabel(),
                'color' => $template->category->getColor(),
                'active' => $template->active,
                'weekdays' => $template->weekdays,
            ])
            ->values()
            ->all();
    }

    public function saveTemplate(): void
    {
        $weekdays = array_map(intval(...), $this->templateWeekdays);

        if ($this->attempt(fn ($actor) => app(SaveSignalRecurring::class)->handle($actor, $this->editingTemplateId, $this->templateTitle, SignalCategory::tryFrom($this->templateCategory), $weekdays))) {
            $this->resetForm();
            $this->changed();
        }
    }

    public function editTemplate(string $id): void
    {
        foreach ($this->templates as $template) {
            if ($template['id'] === $id) {
                $this->editingTemplateId = $id;
                $this->templateTitle = $template['title'];
                $this->templateCategory = $template['category'];
                $this->templateWeekdays = $template['weekdays'];
            }
        }
    }

    public function resetForm(): void
    {
        $this->editingTemplateId = null;
        $this->templateTitle = '';
        $this->templateCategory = 'main';
        $this->templateWeekdays = [];
    }

    public function toggleTemplate(string $id, bool $active): void
    {
        if ($this->attempt(fn ($actor) => app(ToggleSignalRecurring::class)->handle($actor, $id, $active))) {
            $this->changed();
        }
    }

    public function deleteTemplate(string $id): void
    {
        if ($this->attempt(fn ($actor) => app(DeleteSignalRecurring::class)->handle($actor, $id))) {
            if ($this->editingTemplateId === $id) {
                $this->resetForm();
            }

            $this->changed();
        }
    }

    /**
     * @return array{categories: array<string, string>}
     */
    protected function getViewData(): array
    {
        return [
            'categories' => collect(SignalCategory::plannable())->mapWithKeys(static fn (SignalCategory $category): array => [$category->value => $category->getLabel()])->all(),
        ];
    }

    private function changed(): void
    {
        unset($this->templates);

        $this->dispatch('signal-changed');
    }
}
