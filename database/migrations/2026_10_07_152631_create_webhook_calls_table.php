<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_calls', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->string('name');
            $table->string('url', 512);
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            // Already part of the create stub, so the package's add_attachments
            // upgrade migration is not published.
            $table->json('attachments')->nullable();
            $table->text('exception')->nullable();

            $table->timestampsTz();
        });
    }
};
