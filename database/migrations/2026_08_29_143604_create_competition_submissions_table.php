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
        Schema::create('competition_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('competition_registration_id')
                ->constrained('competition_registrations')
                ->cascadeOnDelete();

            $table->string('title');

            $table->text('description')->nullable();

            $table->string('github_url')->nullable();
            $table->string('demo_url')->nullable();

            $table->enum('status', [
                'draft',
                'submitted',
                'under_review',
                'judged'
            ])->default('draft');

            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->unique('competition_registration_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competition_submissions');
    }
};
