<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('commute_limit_days');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('commute_limit_days')->nullable()->after('work_style');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('commute_limit_days');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('commute_limit_days')->nullable()->after('work_style');
        });
    }
};
