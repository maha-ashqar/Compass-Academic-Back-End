<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')
                ->unique()
                ->constrained('students')
                ->cascadeOnDelete();

            $table->string('theme', 20)->default('light');
            $table->string('language', 10)->default('en');
            $table->string('font_size', 20)->default('medium');

            $table->boolean('assignment_notifications')->default(true);
            $table->boolean('grade_notifications')->default(true);
            $table->boolean('course_notifications')->default(true);
            $table->boolean('project_notifications')->default(true);
            $table->boolean('competition_notifications')->default(true);
            $table->boolean('message_notifications')->default(true);
            $table->boolean('announcement_notifications')->default(true);
            $table->boolean('achievement_notifications')->default(true);

            $table->string('profile_visibility', 30)->default('academy');
            $table->boolean('show_activity')->default(true);
            $table->boolean('show_achievements')->default(true);
            $table->boolean('allow_instructor_messages')->default(true);
            $table->string('portfolio_visibility', 20)->default('public');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_settings');
    }
};
