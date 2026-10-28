<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('subject')->nullable();
            $table->string('summary')->nullable();
            $table->text('body');
            $table->dateTime('at');
            $table->string('receipt_source')->nullable();
            $table->foreignId('closes_id')->nullable()->constrained('emails')->nullOnDelete();
            $table->timestamps();

            $table->unique(['closes_id', 'at']);
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->timestamps();
        });

        $existing = DB::table('drafts')->get();

        Schema::drop('drafts');

        Schema::create('drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_id')->nullable()->unique()->constrained('emails')->nullOnDelete();
            $table->string('inbound_note_id')->nullable()->unique();
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
        });

        foreach ($existing as $draft) {
            DB::table('drafts')->insert([
                'id' => $draft->id,
                'inbound_note_id' => $draft->inbound_note_id,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'created_at' => $draft->created_at,
                'updated_at' => $draft->updated_at,
            ]);
        }

        Schema::dropIfExists('fake_notes');
        Schema::dropIfExists('fake_parties');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('drafts');
        Schema::dropIfExists('emails');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('companies');

        Schema::create('drafts', function (Blueprint $table): void {
            $table->id();
            $table->string('inbound_note_id')->unique();
            $table->string('subject');
            $table->text('body');
            $table->timestamps();
        });
    }
};
