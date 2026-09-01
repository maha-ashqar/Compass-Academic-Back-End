<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->string('tier')
                ->default('bronze')
                ->after('icon');
        });

        Schema::table('student_credentials', function (Blueprint $table) {
            $table->text('description')
                ->nullable()
                ->after('credential_url');

            $table->string('credential_url', 2048)
                ->nullable()
                ->change();
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->string('verification_url', 2048)
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->dropColumn('tier');
        });

        Schema::table('student_credentials', function (Blueprint $table) {
            $table->dropColumn('description');

            $table->string('credential_url', 191)
                ->nullable()
                ->change();
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->string('verification_url', 191)
                ->nullable()
                ->change();
        });
    }
};
