<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('gst_filing_frequency')->default('quarterly');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('tax_category')->default('standard_rated');
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->string('tax_category')->default('standard_rated');
        });

        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->string('tax_category')->default('standard_rated');
            $table->boolean('price_includes_tax')->default(false);
            $table->boolean('input_tax_claimable')->default(true);
        });

        DB::table('products')->where('is_taxable', false)->update(['tax_category' => 'exempt']);
        DB::table('sale_items')->where('tax_rate', '<=', 0)->update(['tax_category' => 'exempt']);
        DB::table('purchase_items')->where('tax_rate', '<=', 0)->update([
            'tax_category' => 'exempt',
            'input_tax_claimable' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropColumn(['tax_category', 'price_includes_tax', 'input_tax_claimable']);
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('tax_category');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('tax_category');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('gst_filing_frequency');
        });
    }
};
