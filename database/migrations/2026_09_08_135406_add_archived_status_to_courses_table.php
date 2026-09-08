<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE courses MODIFY status ENUM('draft','published','hidden','archived') NOT NULL DEFAULT 'draft'"
        );
    }

    public function down(): void
    {
        DB::table('courses')
            ->where('status', 'archived')
            ->update([
                'status' => 'hidden',
            ]);

        DB::statement(
            "ALTER TABLE courses MODIFY status ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft'"
        );
    }
};
