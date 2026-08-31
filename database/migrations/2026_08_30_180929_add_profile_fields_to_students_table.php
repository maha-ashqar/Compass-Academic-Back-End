<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('student_code');
            $table->enum('gender', ['male', 'female'])->nullable()->after('phone');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('nationality')->nullable()->after('date_of_birth');
            $table->string('github_url', 2048)->nullable()->after('professional_summary');
            $table->string('linkedin_url', 2048)->nullable()->after('github_url');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'gender',
                'date_of_birth',
                'nationality',
                'github_url',
                'linkedin_url',
            ]);
        });
    }
};
