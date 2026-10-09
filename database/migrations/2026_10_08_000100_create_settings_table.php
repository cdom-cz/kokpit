<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Storage of spatie/laravel-settings on the project conventions (D-01): UUID v7
 * key, timestamptz timestamps, jsonb payload. The package writes through
 * SettingsProperty (App\Domain\Shared\Models), registered in config/settings.php.
 * Rows are Admin and system only; see the model for the Partner rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->jsonb('payload');

            $table->timestampsTz();

            $table->unique(['group', 'name']);
        });
    }
};
