<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Enums\TaxCategory;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturnItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturnItem;
use Illuminate\Support\Collection;

class MiraGstReportService
{
    /** @return array<string, mixed> */
    public function report(string $companyId, string $dateFrom, string $dateTo, float $adjustments = 0, float $excessGstCollected = 0): array
    {
        $company = Company::query()->findOrFail($companyId);
        $outputRows = $this->outputRows($companyId, $dateFrom, $dateTo);
        $inputRows = $this->inputRows($companyId, $dateFrom, $dateTo);

        $byCategory = fn (string $category): float => round((float) $outputRows
            ->where('tax_category', $category)
            ->sum('gross_amount'), 4);

        $outputTax = round((float) $outputRows->sum('tax_amount'), 4);
        $inputTax = round((float) $inputRows->sum('claimable_tax'), 4);

        return [
            'company' => $company,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'output_rows' => $outputRows,
            'input_rows' => $inputRows,
            'summary' => [
                'box_1_standard_rated_sales_inclusive' => $byCategory(TaxCategory::StandardRated->value),
                'box_2_zero_rated_sales' => $byCategory(TaxCategory::ZeroRated->value),
                'box_3_exempt_sales' => $byCategory(TaxCategory::Exempt->value),
                'box_4_out_of_scope_sales' => $byCategory(TaxCategory::OutOfScope->value),
                'box_5_total_sales' => round((float) $outputRows->sum('gross_amount'), 4),
                'box_6_output_tax' => $outputTax,
                'box_7_input_tax' => $inputTax,
                'box_8_adjustments' => round($adjustments, 4),
                'box_9_excess_gst_collected' => round($excessGstCollected, 4),
                'box_10_gst_liability' => round($outputTax - $inputTax - $adjustments + $excessGstCollected, 4),
                'non_claimable_input_tax' => round((float) $inputRows->sum('non_claimable_tax'), 4),
            ],
            'foreign_currency_transactions' => $this->foreignCurrencyCount($companyId, $dateFrom, $dateTo),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function outputRows(string $companyId, string $dateFrom, string $dateTo): Collection
    {
        $salesQuery = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.company_id', $companyId)
            ->where('sales.currency', 'MVR')
            ->whereDate('sales.sale_date', '>=', $dateFrom)
            ->whereDate('sales.sale_date', '<=', $dateTo);

        $sales = Sale::constrainToReportable($salesQuery)
            ->select([
                'sales.sale_date as transaction_date', 'sales.sale_number as invoice_number',
                'customers.name as party_name', 'customers.tax_number as party_tax_number',
                'sale_items.description', 'sale_items.tax_category', 'sale_items.tax_rate',
                'sale_items.tax_amount', 'sale_items.line_total',
            ])
            ->get()
            ->map(fn (object $row): array => $this->outputRow($row, 'sale', 1));

        $returns = SaleReturnItem::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')
            ->leftJoin('customers', 'customers.id', '=', 'sale_returns.customer_id')
            ->where('sale_returns.company_id', $companyId)
            ->where('sales.currency', 'MVR')
            ->whereDate('sale_returns.return_date', '>=', $dateFrom)
            ->whereDate('sale_returns.return_date', '<=', $dateTo)
            ->select([
                'sale_returns.return_date as transaction_date', 'sale_returns.sale_return_number as invoice_number',
                'customers.name as party_name', 'customers.tax_number as party_tax_number',
                'sale_items.description', 'sale_items.tax_category', 'sale_return_items.tax_rate',
                'sale_return_items.tax_amount', 'sale_return_items.line_total',
            ])
            ->get()
            ->map(fn (object $row): array => $this->outputRow($row, 'credit_note', -1));

        return $sales->concat($returns)->sortBy('transaction_date')->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function inputRows(string $companyId, string $dateFrom, string $dateTo): Collection
    {
        $purchases = PurchaseItem::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->where('purchases.company_id', $companyId)
            ->where('purchases.currency', 'MVR')
            ->whereIn('purchases.status', [PurchaseStatus::PartiallyReceived->value, PurchaseStatus::Received->value])
            ->whereDate('purchases.purchase_date', '>=', $dateFrom)
            ->whereDate('purchases.purchase_date', '<=', $dateTo)
            ->select([
                'purchases.purchase_date as transaction_date', 'purchases.purchase_number',
                'purchases.supplier_invoice_number as invoice_number', 'suppliers.name as party_name',
                'suppliers.tax_number as party_tax_number', 'purchase_items.description',
                'purchase_items.tax_category', 'purchase_items.tax_rate', 'purchase_items.tax_amount',
                'purchase_items.line_total', 'purchase_items.input_tax_claimable',
            ])
            ->get()
            ->map(fn (object $row): array => $this->inputRow($row, 'purchase', 1));

        $returns = PurchaseReturnItem::query()
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->join('purchases', 'purchases.id', '=', 'purchase_returns.purchase_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'purchase_return_items.purchase_item_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_returns.supplier_id')
            ->where('purchase_returns.company_id', $companyId)
            ->where('purchases.currency', 'MVR')
            ->whereDate('purchase_returns.return_date', '>=', $dateFrom)
            ->whereDate('purchase_returns.return_date', '<=', $dateTo)
            ->select([
                'purchase_returns.return_date as transaction_date', 'purchase_returns.purchase_return_number as purchase_number',
                'purchases.supplier_invoice_number as invoice_number', 'suppliers.name as party_name',
                'suppliers.tax_number as party_tax_number', 'purchase_items.description',
                'purchase_items.tax_category', 'purchase_return_items.tax_rate', 'purchase_return_items.tax_amount',
                'purchase_return_items.line_total', 'purchase_items.input_tax_claimable',
            ])
            ->get()
            ->map(fn (object $row): array => $this->inputRow($row, 'credit_note', -1));

        return $purchases->concat($returns)->sortBy('transaction_date')->values();
    }

    /** @return array<string, mixed> */
    private function outputRow(object $row, string $type, int $sign): array
    {
        $tax = round($sign * (float) $row->tax_amount, 4);
        $gross = round($sign * (float) $row->line_total, 4);
        $taxCategory = $row->tax_category instanceof TaxCategory ? $row->tax_category->value : $row->tax_category;

        return [
            'type' => $type,
            'transaction_date' => (string) $row->transaction_date,
            'invoice_number' => $row->invoice_number,
            'customer_name' => $row->party_name ?: 'Walk-in Customer',
            'customer_gst_number' => $row->party_tax_number,
            'description' => $row->description,
            'tax_category' => $taxCategory,
            'tax_rate' => (float) $row->tax_rate,
            'taxable_amount' => round($gross - $tax, 4),
            'tax_amount' => $tax,
            'gross_amount' => $gross,
        ];
    }

    /** @return array<string, mixed> */
    private function inputRow(object $row, string $type, int $sign): array
    {
        $tax = round($sign * (float) $row->tax_amount, 4);
        $gross = round($sign * (float) $row->line_total, 4);
        $claimable = (bool) $row->input_tax_claimable;
        $taxCategory = $row->tax_category instanceof TaxCategory ? $row->tax_category->value : $row->tax_category;

        return [
            'type' => $type,
            'transaction_date' => (string) $row->transaction_date,
            'purchase_number' => $row->purchase_number,
            'supplier_invoice_number' => $row->invoice_number,
            'supplier_name' => $row->party_name,
            'supplier_gst_number' => $row->party_tax_number,
            'description' => $row->description,
            'tax_category' => $taxCategory,
            'tax_rate' => (float) $row->tax_rate,
            'taxable_amount' => round($gross - $tax, 4),
            'tax_amount' => $tax,
            'claimable_tax' => $claimable ? $tax : 0.0,
            'non_claimable_tax' => $claimable ? 0.0 : $tax,
            'gross_amount' => $gross,
        ];
    }

    private function foreignCurrencyCount(string $companyId, string $dateFrom, string $dateTo): int
    {
        $sales = Sale::query()->reportable()->where('company_id', $companyId)
            ->where('currency', '!=', 'MVR')->whereBetween('sale_date', [$dateFrom, $dateTo])->count();
        $purchases = Purchase::query()->where('company_id', $companyId)
            ->whereIn('status', [PurchaseStatus::PartiallyReceived->value, PurchaseStatus::Received->value])
            ->where('currency', '!=', 'MVR')->whereBetween('purchase_date', [$dateFrom, $dateTo])->count();

        return $sales + $purchases;
    }
}
