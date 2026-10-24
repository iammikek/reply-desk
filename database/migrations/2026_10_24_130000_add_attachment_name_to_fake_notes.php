<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fake_notes', function (Blueprint $table) {
            $table->string('attachment_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fake_notes', function (Blueprint $table) {
            $table->dropColumn('attachment_name');
        });
    }
};
