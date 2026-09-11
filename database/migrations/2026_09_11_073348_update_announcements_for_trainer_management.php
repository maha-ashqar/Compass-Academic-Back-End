<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE announcements
            MODIFY type ENUM(
                'general',
                'policy',
                'competition',
                'course',
                'faculty_instructions'
            ) NOT NULL DEFAULT 'general'
        ");

        DB::statement("
            ALTER TABLE announcement_audiences
            MODIFY audience_type ENUM(
                'all_students',
                'course',
                'department',
                'specific_student',
                'faculty',
                'major'
            ) NOT NULL
        ");

        if (
            !Schema::hasColumn(
                'announcements',
                'attachment_name'
            )
        ) {
            Schema::table(
                'announcements',
                function (Blueprint $table) {
                    $table->string('attachment_name')
                        ->nullable()
                        ->after('attachment_path');
                }
            );
        }

        if (
            !Schema::hasColumn(
                'announcements',
                'attachment_size'
            )
        ) {
            Schema::table(
                'announcements',
                function (Blueprint $table) {
                    $table->unsignedBigInteger(
                        'attachment_size'
                    )
                        ->nullable()
                        ->after('attachment_name');
                }
            );
        }

        if (
            !Schema::hasColumn(
                'announcements',
                'attachment_mime'
            )
        ) {
            Schema::table(
                'announcements',
                function (Blueprint $table) {
                    $table->string('attachment_mime')
                        ->nullable()
                        ->after('attachment_size');
                }
            );
        }

        if (
            !Schema::hasColumn(
                'announcement_audiences',
                'audience_value'
            )
        ) {
            Schema::table(
                'announcement_audiences',
                function (Blueprint $table) {
                    $table->string('audience_value')
                        ->nullable()
                        ->after('audience_id');
                }
            );
        }
    }

    public function down(): void
    {
        if (
            Schema::hasColumn(
                'announcement_audiences',
                'audience_value'
            )
        ) {
            Schema::table(
                'announcement_audiences',
                function (Blueprint $table) {
                    $table->dropColumn(
                        'audience_value'
                    );
                }
            );
        }

        if (
            Schema::hasColumn(
                'announcements',
                'attachment_mime'
            )
        ) {
            Schema::table(
                'announcements',
                function (Blueprint $table) {
                    $table->dropColumn(
                        'attachment_mime'
                    );
                }
            );
        }

        if (
            Schema::hasColumn(
                'announcements',
                'attachment_size'
            )
        ) {
            Schema::table(
                'announcements',
                function (Blueprint $table) {
                    $table->dropColumn(
                        'attachment_size'
                    );
                }
            );
        }

        if (
            Schema::hasColumn(
                'announcements',
                'attachment_name'
            )
        ) {
            Schema::table(
                'announcements',
                function (Blueprint $table) {
                    $table->dropColumn(
                        'attachment_name'
                    );
                }
            );
        }

        DB::table('announcements')
            ->where(
                'type',
                'faculty_instructions'
            )
            ->update([
                'type' => 'general',
            ]);

        DB::table('announcement_audiences')
            ->whereIn(
                'audience_type',
                [
                    'faculty',
                    'major',
                ]
            )
            ->delete();

        DB::statement("
            ALTER TABLE announcement_audiences
            MODIFY audience_type ENUM(
                'all_students',
                'course',
                'department',
                'specific_student'
            ) NOT NULL
        ");

        DB::statement("
            ALTER TABLE announcements
            MODIFY type ENUM(
                'general',
                'policy',
                'competition',
                'course'
            ) NOT NULL DEFAULT 'general'
        ");
    }
};