<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_educations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('degree')->nullable();
            $table->string('major')->nullable();

            $table->string('university')->nullable();
            $table->string('faculty')->nullable();
            $table->string('department')->nullable();

            $table->string('academic_year')->nullable();

            $table->unsignedSmallInteger('start_year')->nullable();

            $table->date('expected_graduation_date')->nullable();

            $table->string('location')->nullable();

            $table->boolean('is_current')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_education');
    }
};
