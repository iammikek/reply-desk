<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fake_parties', function (Blueprint $table): void {
            $table->string('id');
            $table->string('kind');
            $table->string('name');
            $table->string('email')->default('');
            $table->timestamps();

            $table->primary(['kind', 'id']);
        });

        Schema::create('fake_notes', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->text('body');
            $table->string('company_id');
            $table->string('company_name');
            $table->string('contact_id');
            $table->string('contact_name');
            $table->string('contact_email');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fake_notes');
        Schema::dropIfExists('fake_parties');
    }
};
