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
        Schema::table('users', function (Blueprint $table) {
            $table->time('default_clock_in')->default('09:00')->after('role');
            $table->time('default_clock_out')->default('18:00')->after('default_clock_in');
            $table->unsignedSmallInteger('default_break_minutes')->default(60)->after('default_clock_out');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['default_clock_in', 'default_clock_out', 'default_break_minutes']);
        });
    }
};
