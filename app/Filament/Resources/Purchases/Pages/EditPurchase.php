<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Support\AdminSupport;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Purchase $purchase */
        $purchase = $this->getRecord()->loadMissing('items');
        $data['items'] = $purchase->items
            ->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'description' => $item->description,
                'ordered_quantity' => (string) $item->ordered_quantity,
                'unit_cost' => (string) $item->unit_cost,
                'discount_amount' => (string) $item->discount_amount,
                'tax_rate' => (string) $item->tax_rate,
                'tax_category' => $item->tax_category?->value,
                'price_includes_tax' => (bool) $item->price_includes_tax,
                'input_tax_claimable' => (bool) $item->input_tax_claimable,
            ])
            ->values()
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Purchase) {
            return $record;
        }

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
        $purchase = $purchaseService->updatePurchase(
            $record->id,
            $companyId,
            $warehouseId,
            $supplierId,
            $data['items'] ?? [],
            [
                'branch_id' => AdminSupport::authorizedWarehouseQuery()->whereKey($warehouseId)->value('branch_id'),
                'status' => $receiveImmediately ? 'ordered' : ($data['status'] ?? $record->status->value),
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
            $item->id => max(0, (float) $item->ordered_quantity - (float) $item->received_quantity),
        ])->filter(fn (float $quantity): bool => $quantity > 0)->all();

        return $purchaseService->receivePurchase(
            $purchase->id,
            $quantities,
            (string) auth()->id(),
            now(),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
