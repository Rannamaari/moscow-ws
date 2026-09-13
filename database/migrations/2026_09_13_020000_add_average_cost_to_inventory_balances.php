<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_balances', function (Blueprint $table): void {
            $table->decimal('average_cost', 15, 4)->nullable()->after('quantity');
        });

        DB::table('inventory_balances')
            ->select(['id', 'product_id'])
            ->orderBy('id')
            ->each(function ($balance): void {
                DB::table('inventory_balances')
                    ->where('id', $balance->id)
                    ->update([
                        'average_cost' => DB::table('products')->where('id', $balance->product_id)->value('cost_price'),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('inventory_balances', function (Blueprint $table): void {
            $table->dropColumn('average_cost');
        });
    }
};
