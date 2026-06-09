<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedTinyInteger('meal_percentage')->nullable()->after('work_location');
            $table->boolean('missed_meal')->default(false)->after('meal_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['meal_percentage', 'missed_meal']);
        });
    }
};
