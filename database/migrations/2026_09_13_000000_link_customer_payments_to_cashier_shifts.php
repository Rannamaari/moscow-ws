<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table): void {
            $table->uuid('cashier_shift_id')->nullable();
            $table->foreign('cashier_shift_id')->references('id')->on('cashier_shifts')->nullOnDelete();
            $table->index(['company_id', 'cashier_shift_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table): void {
            $table->dropForeign(['cashier_shift_id']);
            $table->dropIndex(['company_id', 'cashier_shift_id', 'paid_at']);
            $table->dropColumn('cashier_shift_id');
        });
    }
};
