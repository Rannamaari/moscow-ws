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
            $table->decimal('default_tax_rate', 8, 4)->default(0);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_taxable')->default(false)->index();
        });

        DB::table('products')->where('tax_rate', '>', 0)->update(['is_taxable' => true]);

        DB::table('companies')->orderBy('id')->each(function (object $company): void {
            $rate = DB::table('products')
                ->where('company_id', $company->id)
                ->where('tax_rate', '>', 0)
                ->max('tax_rate');

            if ($rate !== null) {
                DB::table('companies')->where('id', $company->id)->update(['default_tax_rate' => $rate]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['is_taxable']);
            $table->dropColumn('is_taxable');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('default_tax_rate');
        });
    }
};
