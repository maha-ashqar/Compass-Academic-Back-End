<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('created_by')
                ->constrained('trainers')
                ->cascadeOnDelete();

            $table->string('title');

            $table->enum('type', [
                'general',
                'policy',
                'competition',
                'course'
            ])->default('general');

            $table->text('content');

            $table->string('related_link')->nullable();

            $table->string('attachment_path')->nullable();

            $table->enum('status', [
                'draft',
                'scheduled',
                'published',
                'archived'
            ])->default('draft');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
