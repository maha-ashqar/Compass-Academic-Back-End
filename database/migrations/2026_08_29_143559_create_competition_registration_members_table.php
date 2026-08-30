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
        Schema::create('competition_registration_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('competition_registration_id');

            $table->foreign(
                'competition_registration_id',
                'crm_registration_fk'
            )
                ->references('id')
                ->on('competition_registrations')
                ->cascadeOnDelete();

            $table->foreignId('student_id');

            $table->foreign(
                'student_id',
                'crm_student_fk'
            )
                ->references('id')
                ->on('students')
                ->cascadeOnDelete();

            $table->enum('role', [
                'leader',
                'member'
            ])->default('member');

            $table->timestamps();

            $table->unique(
                ['competition_registration_id', 'student_id'],
                'crm_registration_student_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competition_registration_members');
    }
};
