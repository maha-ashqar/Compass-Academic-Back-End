<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->string('category')->nullable()->after('title');
        });

        Schema::table('competition_registrations', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('rejection_reason');
        });

        Schema::table('competition_registration_members', function (Blueprint $table) {
            $table->unsignedBigInteger('student_id')->nullable()->change();
            $table->string('member_name')->nullable()->after('student_id');
            $table->string('member_email')->nullable()->after('member_name');
            $table->string('member_role')->nullable()->after('role');
        });

        DB::statement(
            "ALTER TABLE competition_submissions MODIFY status
            ENUM(
                'draft',
                'submitted',
                'under_review',
                'changes_requested',
                'approved',
                'scored',
                'judged',
                'deleted'
            ) NOT NULL DEFAULT 'draft'"
        );

        Schema::table('competition_submissions', function (Blueprint $table) {
            $table->text('feedback')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('feedback');
            $table->text('delete_reason')->nullable()->after('reviewed_at');
            $table->timestamp('deleted_at')->nullable()->after('delete_reason');
        });
    }

    public function down(): void
    {
        Schema::table('competition_submissions', function (Blueprint $table) {
            $table->dropColumn([
                'feedback',
                'reviewed_at',
                'delete_reason',
                'deleted_at',
            ]);
        });

        DB::statement(
            "ALTER TABLE competition_submissions MODIFY status
            ENUM('draft','submitted','under_review','judged')
            NOT NULL DEFAULT 'draft'"
        );

        DB::table('competition_registration_members')
            ->whereNull('student_id')
            ->delete();

        Schema::table('competition_registration_members', function (Blueprint $table) {
            $table->dropColumn([
                'member_name',
                'member_email',
                'member_role',
            ]);

            $table->unsignedBigInteger('student_id')
                ->nullable(false)
                ->change();
        });

        Schema::table('competition_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'rejection_reason',
                'reviewed_at',
            ]);
        });

        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
