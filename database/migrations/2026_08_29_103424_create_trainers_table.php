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
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('job_title')->nullable();
            $table->text('bio')->nullable();

            $table->string('phone')->nullable();

            $table->string('university')->nullable();
            $table->string('faculty')->nullable();
            $table->string('department')->nullable();

            $table->string('office')->nullable();
            $table->string('office_hours')->nullable();
            $table->string('extension')->nullable();

            $table->string('github_url')->nullable();
            $table->string('linkedin_url')->nullable();

            $table->enum('status', ['active', 'inactive'])
                ->default('active');

            $table->boolean('is_verified')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trainers');
    }
};
