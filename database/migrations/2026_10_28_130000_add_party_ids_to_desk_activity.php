<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('desk_activity', function (Blueprint $table): void {
            $table->string('company_id')->nullable()->after('contact');
            $table->string('contact_id')->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('desk_activity', function (Blueprint $table): void {
            $table->dropColumn(['company_id', 'contact_id']);
        });
    }
};
