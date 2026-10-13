<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('librarium.runs.connection'))->create(config('librarium.runs.table', 'librarium_runs'), function (Blueprint $table): void {
            $table->string('id', 30)->primary();
            $table->string('created_at')->index();
            $table->unsignedBigInteger('version');
            $table->json('document');
        });
    }

    public function down(): void
    {
        Schema::connection(config('librarium.runs.connection'))->dropIfExists(config('librarium.runs.table', 'librarium_runs'));
    }
};
