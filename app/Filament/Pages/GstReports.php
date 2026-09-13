<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminSupport;
use App\Services\MiraGstReportService;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GstReports extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'MIRA GST Reports';

    protected static ?string $title = 'MIRA GST Reports';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.gst-reports';

    public string $dateFrom = '';

    public string $dateTo = '';

    public float $adjustments = 0;

    public float $excessGstCollected = 0;

    public static function canAccess(): bool
    {
        return (bool) AdminSupport::user()?->can('reports.view');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.reports');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess() && AdminSupport::companyId(), 403);
        $this->useCurrentPeriod();
    }

    public function useCurrentPeriod(): void
    {
        $today = Carbon::now(config('app.business_timezone'));
        $frequency = AdminSupport::company()?->gst_filing_frequency ?: 'quarterly';

        $this->dateFrom = ($frequency === 'monthly' ? $today->copy()->startOfMonth() : $today->copy()->startOfQuarter())->toDateString();
        $this->dateTo = ($frequency === 'monthly' ? $today->copy()->endOfMonth() : $today->copy()->endOfQuarter())->toDateString();
    }

    public function updatedDateFrom(): void
    {
        if ($this->dateFrom > $this->dateTo) {
            $this->dateTo = $this->dateFrom;
        }
    }

    public function updatedDateTo(): void
    {
        if ($this->dateTo < $this->dateFrom) {
            $this->dateFrom = $this->dateTo;
        }
    }

    /** @return array<string, mixed> */
    public function getReportProperty(): array
    {
        return app(MiraGstReportService::class)->report(
            AdminSupport::companyId(),
            $this->dateFrom,
            $this->dateTo,
            max(0, $this->adjustments),
            max(0, $this->excessGstCollected),
        );
    }

    public function downloadMira205(): StreamedResponse
    {
        $report = $this->report;
        $summary = $report['summary'];

        return $this->csvResponse('mira-205', [
            ['MIRA 205 GST Return Summary'],
            ['GST Registration Number', $report['company']->tax_number],
            ['Registered Business', $report['company']->legal_name ?: $report['company']->name],
            ['Taxable Period', $this->dateFrom.' to '.$this->dateTo],
            ['Currency', 'MVR'],
            [],
            ['Box', 'Description', 'Amount (MVR)'],
            [1, 'Sales subject to GST (inclusive of GST)', $summary['box_1_standard_rated_sales_inclusive']],
            [2, 'Zero-rated sales', $summary['box_2_zero_rated_sales']],
            [3, 'Exempt sales', $summary['box_3_exempt_sales']],
            [4, 'Out-of-scope sales', $summary['box_4_out_of_scope_sales']],
            [5, 'Total sales', $summary['box_5_total_sales']],
            [6, 'Output tax', $summary['box_6_output_tax']],
            [7, 'Claimable input tax', $summary['box_7_input_tax']],
            [8, 'Adjustments', $summary['box_8_adjustments']],
            [9, 'GST collected in excess', $summary['box_9_excess_gst_collected']],
            [10, 'GST liability / (credit)', $summary['box_10_gst_liability']],
        ]);
    }

    public function downloadInputStatement(): StreamedResponse
    {
        $report = $this->report;
        $rows = $report['input_rows']->map(fn (array $row): array => [
            $report['company']->tax_number, $this->dateFrom, $this->dateTo,
            $row['type'], $row['transaction_date'], $row['purchase_number'], $row['supplier_invoice_number'],
            $row['supplier_name'], $row['supplier_gst_number'], $row['description'], $row['tax_category'],
            $row['tax_rate'], $row['taxable_amount'], $row['tax_amount'], $row['claimable_tax'],
            $row['non_claimable_tax'], $row['gross_amount'],
        ])->prepend([
            'Business GST No.', 'Period From', 'Period To',
            'Type', 'Date', 'Purchase No.', 'Supplier Invoice No.', 'Supplier', 'Supplier GST No.',
            'Description', 'GST Classification', 'Rate %', 'Value Excluding GST', 'GST',
            'Claimable Input GST', 'Non-claimable GST', 'Total Including GST',
        ])->all();

        return $this->csvResponse('mira-input-tax-statement', $rows);
    }

    public function downloadOutputStatement(): StreamedResponse
    {
        $report = $this->report;
        $rows = $report['output_rows']->map(fn (array $row): array => [
            $report['company']->tax_number, $this->dateFrom, $this->dateTo,
            $row['type'], $row['transaction_date'], $row['invoice_number'], $row['customer_name'],
            $row['customer_gst_number'], $row['description'], $row['tax_category'], $row['tax_rate'],
            $row['taxable_amount'], $row['tax_amount'], $row['gross_amount'],
        ])->prepend([
            'Business GST No.', 'Period From', 'Period To',
            'Type', 'Date', 'Invoice / Credit Note No.', 'Customer', 'Customer GST No.',
            'Description', 'GST Classification', 'Rate %', 'Value Excluding GST', 'Output GST',
            'Total Including GST',
        ])->all();

        return $this->csvResponse('mira-output-tax-statement', $rows);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function csvResponse(string $name, array $rows): StreamedResponse
    {
        $filename = sprintf('%s-%s-to-%s.csv', $name, $this->dateFrom, $this->dateTo);

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($output, array_map(fn ($value) => is_float($value) ? number_format($value, 2, '.', '') : $value, $row));
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
