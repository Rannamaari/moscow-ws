<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales')
            ->where('status', 'cancelled')
            ->update([
                'paid_total' => 0,
                'balance_due' => 0,
                'due_date' => null,
            ]);
    }

    public function down(): void
    {
        // Cancelled sales are not receivables, so their old balances must not be restored.
    }
};
