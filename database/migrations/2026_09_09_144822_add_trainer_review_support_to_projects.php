<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE project_reviews MODIFY status ENUM(
                'draft',
                'approved',
                'changes_requested',
                'rejected'
            ) NOT NULL"
        );

        Schema::table('project_reviews', function (Blueprint $table) {
            $table->text('private_note')
                ->nullable()
                ->after('feedback');

            $table->boolean('notify_team')
                ->default(false)
                ->after('private_note');

            $table->timestamp('changes_due_at')
                ->nullable()
                ->after('notify_team');

            $table->unsignedSmallInteger('total_score')
                ->nullable()
                ->after('changes_due_at');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('deleted_at')
                ->nullable()
                ->after('published_at');

            $table->text('deletion_reason')
                ->nullable()
                ->after('deleted_at');

            $table->foreignId('deleted_by')
                ->nullable()
                ->after('deletion_reason')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('project_review_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_review_id')
                ->constrained('project_reviews')
                ->cascadeOnDelete();

            $table->string('criterion_key');
            $table->string('criterion_label');
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('max_score');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(
                ['project_review_id', 'criterion_key'],
                'project_review_scores_unique'
            );
        });

        Schema::create('project_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('action');
            $table->text('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_audit_logs');
        Schema::dropIfExists('project_review_scores');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);

            $table->dropColumn([
                'deleted_at',
                'deletion_reason',
                'deleted_by',
            ]);
        });

        Schema::table('project_reviews', function (Blueprint $table) {
            $table->dropColumn([
                'private_note',
                'notify_team',
                'changes_due_at',
                'total_score',
            ]);
        });

        DB::table('project_reviews')
            ->where('status', 'draft')
            ->delete();

        DB::statement(
            "ALTER TABLE project_reviews MODIFY status ENUM(
                'approved',
                'changes_requested',
                'rejected'
            ) NOT NULL"
        );
    }
};
