<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_requests', function (Blueprint $table) {
            $table->boolean('admin_checked')->default(false)->after('status');
            $table->boolean('service_manager_checked')->default(false)->after('admin_checked');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_requests', function (Blueprint $table) {
            $table->dropColumn(['admin_checked', 'service_manager_checked']);
        });
    }
};
