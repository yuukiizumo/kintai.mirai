<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_adjustments', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_id']);
            $table->unique(['user_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_adjustments', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'source_type', 'source_id']);
            $table->unique(['source_type', 'source_id']);
        });
    }
};
