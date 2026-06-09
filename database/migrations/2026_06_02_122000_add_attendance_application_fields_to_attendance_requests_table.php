<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_requests', function (Blueprint $table) {
            $table->foreignId('applied_attendance_record_id')->nullable()->after('service_manager_checked')->constrained('attendance_records')->nullOnDelete();
            $table->json('attendance_record_snapshot')->nullable()->after('applied_attendance_record_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_attendance_record_id');
            $table->dropColumn('attendance_record_snapshot');
        });
    }
};
