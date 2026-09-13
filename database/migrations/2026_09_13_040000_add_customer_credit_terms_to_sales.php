<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedInteger('payment_terms_days')->default(7)->after('credit_limit');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->string('customer_tax_number')->nullable()->after('customer_id');
            $table->unsignedInteger('payment_terms_days')->nullable()->after('customer_tax_number');
            $table->date('due_date')->nullable()->after('sale_date');
            $table->index(['company_id', 'due_date']);
        });

        DB::transaction(function (): void {
            DB::table('sales')->where('balance_due', '>', 0)->whereNotNull('customer_id')->orderBy('id')->each(function ($sale): void {
                $customer = DB::table('customers')->where('id', $sale->customer_id)->first(['tax_number', 'payment_terms_days']);
                $terms = (int) ($customer?->payment_terms_days ?? 7);

                DB::table('sales')->where('id', $sale->id)->update([
                    'customer_tax_number' => $customer?->tax_number,
                    'payment_terms_days' => $terms,
                    'due_date' => Carbon::parse($sale->sale_date)->addDays($terms)->toDateString(),
                ]);
            });
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'due_date']);
            $table->dropColumn(['customer_tax_number', 'payment_terms_days', 'due_date']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('payment_terms_days');
        });
    }
};
