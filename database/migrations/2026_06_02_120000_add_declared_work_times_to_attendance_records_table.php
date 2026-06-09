<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->time('declared_clock_in')->nullable()->after('clock_out');
            $table->time('declared_clock_out')->nullable()->after('declared_clock_in');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['declared_clock_in', 'declared_clock_out']);
        });
    }
};
