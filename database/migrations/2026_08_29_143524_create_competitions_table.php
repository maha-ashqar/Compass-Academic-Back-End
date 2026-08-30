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
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('created_by')
                ->constrained('trainers')
                ->cascadeOnDelete();

            $table->string('title');

            $table->text('description')->nullable();
            $table->text('objective')->nullable();

            $table->enum('participation_type', [
                'individual',
                'team',
                'individual_or_team'
            ])->default('individual');

            $table->unsignedInteger('max_team_members')
                ->nullable();

            $table->timestamp('registration_start_at')->nullable();
            $table->timestamp('registration_end_at')->nullable();

            $table->timestamp('work_start_at')->nullable();
            $table->timestamp('work_end_at')->nullable();

            $table->timestamp('submission_deadline_at')->nullable();
            $table->timestamp('results_at')->nullable();

            $table->string('prize')->nullable();

            $table->enum('status', [
                'draft',
                'registration_open',
                'registration_closed',
                'submissions_open',
                'judging',
                'results_published',
                'completed'
            ])->default('draft');

            $table->timestamp('results_published_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
