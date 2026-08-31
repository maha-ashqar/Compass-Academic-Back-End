<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
            $table->string('member_name')->nullable()->after('student_id');
            $table->string('project_role')->nullable()->after('role');
            $table->string('specialty')->nullable()->after('project_role');
        });

        Schema::create('project_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['project_id', 'student_id'],
                'project_likes_project_student_unique'
            );
        });

        Schema::create('project_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();
            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->timestamps();

            $table->unique(
                ['project_id', 'student_id'],
                'project_ratings_project_student_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_ratings');
        Schema::dropIfExists('project_likes');

        DB::table('project_members')
            ->whereNull('student_id')
            ->delete();

        Schema::table('project_members', function (Blueprint $table) {
            $table->dropColumn([
                'member_name',
                'project_role',
                'specialty',
            ]);

            $table->unsignedBigInteger('student_id')
                ->nullable(false)
                ->change();
        });
    }
};
