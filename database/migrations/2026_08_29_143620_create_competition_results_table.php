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
        Schema::create('competition_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('competition_registration_id')
                ->constrained('competition_registrations')
                ->cascadeOnDelete();

            $table->unsignedInteger('rank')->nullable();

            $table->decimal('final_score', 8, 2)->nullable();

            $table->string('award')->nullable();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique('competition_registration_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('competition_results');
    }
};
