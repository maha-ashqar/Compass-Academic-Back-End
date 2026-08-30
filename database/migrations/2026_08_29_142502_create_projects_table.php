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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            $table->foreignId('owner_student_id')
                ->nullable()
                ->constrained('students')
                ->nullOnDelete();
            $table->string('author_name')->nullable();
            $table->foreignId('course_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('learning_path_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('title');

            $table->text('idea')->nullable();
            $table->text('description')->nullable();
            $table->text('problem')->nullable();
            $table->text('solution')->nullable();

            $table->enum('project_type', [
                'individual',
                'team'
            ])->default('individual');

            $table->string('cover_image')->nullable();
            $table->string('logo')->nullable();
            $table->string('intro_video')->nullable();

            $table->string('github_url')->nullable();
            $table->string('live_url')->nullable();

            $table->string('presentation_file')->nullable();
            $table->string('documentation_file')->nullable();

            $table->enum('status', [
                'draft',
                'in_review',
                'revision_requested',
                'published',
                'rejected',
                'hidden'
            ])->default('draft');

            $table->boolean('is_featured')->default(false);

            $table->timestamp('submitted_for_review_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
