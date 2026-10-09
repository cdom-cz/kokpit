<?php

declare(strict_types=1);

return [
    /*
     * Which column will be used as the order column.
     * The package default stays: the tags and media packages order their models
     * by `order_column`. The Task model names its own `position` column in its
     * `$sortable` property.
     */
    'order_column_name' => 'order_column',

    /*
     * Define if the models should sort when creating.
     * When true, the package will automatically assign the highest order number to a new model.
     * The package default stays for the tags and media models; the Task model turns it off in
     * its `$sortable` property because the creating code appends it under the board lock.
     */
    'sort_when_creating' => true,

    /*
     * Define if the timestamps should be ignored when sorting.
     * When true, updated_at will not be updated when using setNewOrder.
     * On: a drag-and-drop reorder is not an edit of the task.
     */
    'ignore_timestamps' => true,
];
