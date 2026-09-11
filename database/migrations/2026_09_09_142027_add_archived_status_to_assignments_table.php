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
            "ALTER TABLE assignments MODIFY status ENUM(
                'draft',
                'scheduled',
                'active',
                'closed',
                'archived'
            ) NOT NULL DEFAULT 'draft'"
        );

        Schema::table('assignments', function (Blueprint $table) {
            $table->text('close_reason')->nullable()->after('status');
            $table->timestamp('archived_at')->nullable()->after('close_reason');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->text('private_note')->nullable()->after('feedback');
            $table->timestamp('resubmission_due_at')->nullable()->after('private_note');
        });
    }

    public function down(): void
    {
        DB::table('assignments')
            ->where('status', 'archived')
            ->update([
                'status' => 'closed',
                'archived_at' => null,
            ]);

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn([
                'private_note',
                'resubmission_due_at',
            ]);
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn([
                'close_reason',
                'archived_at',
            ]);
        });

        DB::statement(
            "ALTER TABLE assignments MODIFY status ENUM(
                'draft',
                'scheduled',
                'active',
                'closed'
            ) NOT NULL DEFAULT 'draft'"
        );
    }
};
