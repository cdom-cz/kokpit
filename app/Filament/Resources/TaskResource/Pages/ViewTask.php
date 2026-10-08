<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Pages;

use App\Domain\Tasks\Models\Task;
use App\Filament\Resources\TaskResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * The full task page at /admin/tasks/KEY-N (D-09).
 */
final class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    public function getTitle(): string
    {
        $task = $this->getRecord();

        return $task instanceof Task ? $task->reference.' · '.$task->title : __('kokpit.tasks.model_label');
    }
}
