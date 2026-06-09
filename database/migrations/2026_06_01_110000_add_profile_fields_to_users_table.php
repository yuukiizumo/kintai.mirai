<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('hire_date')->nullable()->after('role');
            $table->string('management_number')->nullable()->after('hire_date');
            $table->unsignedInteger('hourly_wage')->nullable()->after('email');
            $table->string('department')->nullable()->after('hourly_wage');
            $table->string('business_category')->nullable()->after('department');
            $table->string('work_style')->nullable()->after('business_category');
            $table->unsignedTinyInteger('commute_limit_days')->nullable()->after('work_style');
            $table->decimal('height_cm', 5, 1)->nullable()->after('commute_limit_days');
            $table->decimal('weight_kg', 5, 1)->nullable()->after('height_cm');
            $table->string('gender')->nullable()->after('weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'hire_date',
                'management_number',
                'hourly_wage',
                'department',
                'business_category',
                'work_style',
                'commute_limit_days',
                'height_cm',
                'weight_kg',
                'gender',
            ]);
        });
    }
};
