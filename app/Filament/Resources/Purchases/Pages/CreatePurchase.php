<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Support\AdminSupport;
use App\Services\PurchaseService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $companyId = AdminSupport::companyId();
        $warehouseId = $data['warehouse_id'] ?? null;
        $supplierId = $data['supplier_id'] ?? null;

        if (! $companyId || ! $warehouseId || ! AdminSupport::canAccessWarehouse($warehouseId)) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Select an authorized warehouse.',
            ]);
        }

        if (! $supplierId) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Select a supplier.',
            ]);
        }

        $receiveImmediately = ($data['status'] ?? null) === 'receive_now';
        $purchaseService = app(PurchaseService::class);
        $purchase = $purchaseService->createPurchase(
            $companyId,
            $warehouseId,
            $supplierId,
            $data['items'] ?? [],
            [
                'branch_id' => AdminSupport::authorizedWarehouseQuery()->whereKey($warehouseId)->value('branch_id'),
                'status' => $receiveImmediately ? 'ordered' : ($data['status'] ?? 'ordered'),
                'purchase_date' => $data['purchase_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
                'shipping_total' => $data['shipping_total'] ?? 0,
                'other_cost_total' => $data['other_cost_total'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ],
        );

        if (! $receiveImmediately) {
            return $purchase;
        }

        $quantities = $purchase->items->mapWithKeys(fn ($item): array => [
            $item->id => $item->ordered_quantity,
        ])->all();

        return $purchaseService->receivePurchase(
            $purchase->id,
            $quantities,
            (string) auth()->id(),
            now(),
        );
    }
}
