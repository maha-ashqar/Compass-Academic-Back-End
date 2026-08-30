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
        Schema::create('competition_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('competition_submission_id')
                ->constrained('competition_submissions')
                ->cascadeOnDelete();

            $table->foreignId('judge_id')
                ->constrained('trainers')
                ->cascadeOnDelete();

            $table->foreignId('criterion_id')
                ->constrained('competition_evaluation_criteria')
                ->cascadeOnDelete();

            $table->decimal('score', 5, 2);

            $table->text('feedback')->nullable();

            $table->timestamps();

            $table->unique(
                [
                    'competition_submission_id',
                    'judge_id',
                    'criterion_id'
                ],
                'comp_scores_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competition_scores');
    }
};
