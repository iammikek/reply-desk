<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_message_ids', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique();
            $table->string('note_id');
            $table->string('kind');
            $table->timestamps();
        });

        Schema::table('drafts', function (Blueprint $table) {
            $table->string('mailbox_draft_id')->nullable()->after('body');
        });

        Schema::create('mailbox_state', function (Blueprint $table) {
            $table->id();
            $table->boolean('polling_enabled')->default(true);
            $table->unsignedInteger('poll_interval_minutes')->default(5);
            $table->string('inbox_cursor')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->string('last_poll_error')->nullable();
            $table->timestamps();
        });

        Schema::create('desk_activity', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('summary');
            $table->string('inbound_id')->nullable();
            $table->string('note_id')->nullable();
            $table->string('company')->nullable();
            $table->string('contact')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_activity');
        Schema::dropIfExists('mailbox_state');
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn('mailbox_draft_id');
        });
        Schema::dropIfExists('mail_message_ids');
    }
};
