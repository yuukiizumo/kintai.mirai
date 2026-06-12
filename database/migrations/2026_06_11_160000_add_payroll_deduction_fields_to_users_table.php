<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('health_insurance_deduction')->default(0)->after('hourly_wage');
            $table->unsignedInteger('nursing_care_insurance_deduction')->default(0)->after('health_insurance_deduction');
            $table->unsignedInteger('welfare_pension_deduction')->default(0)->after('nursing_care_insurance_deduction');
            $table->decimal('employment_insurance_rate', 5, 3)->default(0)->after('welfare_pension_deduction');
            $table->unsignedInteger('income_tax_deduction')->default(0)->after('employment_insurance_rate');
            $table->unsignedInteger('resident_tax_deduction')->default(0)->after('income_tax_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'health_insurance_deduction',
                'nursing_care_insurance_deduction',
                'welfare_pension_deduction',
                'employment_insurance_rate',
                'income_tax_deduction',
                'resident_tax_deduction',
            ]);
        });
    }
};
