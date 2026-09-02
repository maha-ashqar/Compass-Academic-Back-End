<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('topic')
                ->nullable()
                ->after('id');

            $table->timestamp('accepted_at')
                ->nullable()
                ->after('topic');

            $table->timestamp('declined_at')
                ->nullable()
                ->after('accepted_at');

            $table->foreignId('blocked_by_user_id')
                ->nullable()
                ->after('declined_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->timestamp('cleared_at')
                ->nullable()
                ->after('last_read_at');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('edited_at')
                ->nullable()
                ->after('read_at');

            $table->timestamp('deleted_at')
                ->nullable()
                ->after('edited_at');
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained('messages')
                ->cascadeOnDelete();

            $table->string('original_name');
            $table->string('file_path', 2048);
            $table->string('file_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn([
                'edited_at',
                'deleted_at',
            ]);
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn('cleared_at');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign([
                'blocked_by_user_id',
            ]);

            $table->dropColumn([
                'topic',
                'accepted_at',
                'declined_at',
                'blocked_by_user_id',
            ]);
        });
    }
};
