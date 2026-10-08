<?php

declare(strict_types=1);

use App\Providers\AccessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\LocalisationServiceProvider;
use App\Providers\ModelConventionsServiceProvider;
use App\Providers\OperationsServiceProvider;

return [
    AppServiceProvider::class,
    AccessServiceProvider::class,
    AdminPanelProvider::class,
    ModelConventionsServiceProvider::class,
    LocalisationServiceProvider::class,
    OperationsServiceProvider::class,
];
