<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->timestamp('clock_in_recorded_at')->nullable()->after('clock_in');
            $table->timestamp('clock_out_recorded_at')->nullable()->after('clock_out');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['clock_in_recorded_at', 'clock_out_recorded_at']);
        });
    }
};
