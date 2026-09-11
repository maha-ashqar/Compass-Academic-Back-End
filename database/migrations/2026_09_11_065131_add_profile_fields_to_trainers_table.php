<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainers', function (Blueprint $table) {
            $table->string('employee_id')->nullable()->unique();
            $table->string('national_id')->nullable()->unique();

            $table->string('employment_status')->nullable();
            $table->string('years_of_experience')->nullable();

            $table->string('academic_degree')->nullable();
            $table->string('degree_specialization')->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->string('degree_certificate_number')->nullable();

            $table->string('degree_certificate_path')->nullable();
            $table->string('degree_certificate_original_name')->nullable();
            $table->unsignedBigInteger('degree_certificate_size')->nullable();
            $table->boolean('degree_certificate_verified')
                ->default(false);
        });

        Schema::create('trainer_specializations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trainer_id')
                ->constrained('trainers')
                ->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('position')->default(1);

            $table->timestamps();

            $table->unique([
                'trainer_id',
                'name',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_specializations');

        Schema::table('trainers', function (Blueprint $table) {
            $table->dropUnique([
                'employee_id',
            ]);

            $table->dropUnique([
                'national_id',
            ]);

            $table->dropColumn([
                'employee_id',
                'national_id',
                'employment_status',
                'years_of_experience',
                'academic_degree',
                'degree_specialization',
                'graduation_year',
                'degree_certificate_number',
                'degree_certificate_path',
                'degree_certificate_original_name',
                'degree_certificate_size',
                'degree_certificate_verified',
            ]);
        });
    }
};