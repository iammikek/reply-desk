<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fake_parties', function (Blueprint $table) {
            $table->string('company_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fake_parties', function (Blueprint $table) {
            $table->dropColumn('company_id');
        });
    }
};
