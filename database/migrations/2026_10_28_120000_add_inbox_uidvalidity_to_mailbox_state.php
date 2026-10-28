<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailbox_state', function (Blueprint $table): void {
            $table->string('inbox_uidvalidity')->nullable()->after('inbox_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_state', function (Blueprint $table): void {
            $table->dropColumn('inbox_uidvalidity');
        });
    }
};
