<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_manager_activity', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event', 80)->index();
            $table->string('status', 30)->index();
            $table->string('backup_id', 40)->nullable()->index();
            $table->uuid('job_id')->nullable()->index();
            $table->string('disk', 100)->nullable()->index();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_manager_activity');
    }
};
