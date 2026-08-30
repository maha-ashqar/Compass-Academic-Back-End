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
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assignment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('student_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('submission_name')->nullable();

            $table->text('note')->nullable();

            $table->enum('status', [
                'draft',
                'submitted',
                'late',
                'graded',
                'revision_requested',
                'resubmitted'
            ])->default('draft');

            $table->timestamp('submitted_at')->nullable();

            $table->decimal('grade', 8, 2)->nullable();

            $table->text('feedback')->nullable();

            $table->foreignId('graded_by')
                ->nullable()
                ->constrained('trainers')
                ->nullOnDelete();

            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            $table->unique(['assignment_id', 'student_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
