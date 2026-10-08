<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerTaskResource\Pages;

use App\Domain\Tasks\Models\Task;
use App\Filament\Partner\Resources\PartnerTaskResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewPartnerTask extends ViewRecord
{
    protected static string $resource = PartnerTaskResource::class;

    public function getTitle(): string
    {
        $task = $this->getRecord();

        return $task instanceof Task ? $task->reference.' · '.$task->title : (string) __('kokpit.partner_tasks.model_label');
    }

    /**
     * The page is read-only: no edit or delete action. Escalation follows with
     * the Partner comments.
     *
     * @return array<never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
