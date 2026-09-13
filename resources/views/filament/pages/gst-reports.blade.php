<x-filament-panels::page>
    @php($report = $this->report)
    @php($summary = $report['summary'])
    <div class="space-y-6">
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5 print:shadow-none">
            <div class="grid gap-4 md:grid-cols-3">
                <label class="text-sm font-medium">From
                    <input wire:model.live="dateFrom" type="date" class="mt-2 w-full rounded-lg border-gray-300 dark:border-white/15 dark:bg-gray-900">
                </label>
                <label class="text-sm font-medium">To
                    <input wire:model.live="dateTo" type="date" class="mt-2 w-full rounded-lg border-gray-300 dark:border-white/15 dark:bg-gray-900">
                </label>
                <div class="flex items-end"><x-filament::button wire:click="useCurrentPeriod" color="gray">Use Current {{ ucfirst($report['company']->gst_filing_frequency) }} Period</x-filament::button></div>
            </div>
            <dl class="mt-5 grid gap-3 text-sm md:grid-cols-3">
                <div><dt class="text-gray-500">Registered Business</dt><dd class="font-semibold">{{ $report['company']->legal_name ?: $report['company']->name }}</dd></div>
                <div><dt class="text-gray-500">GST Registration Number</dt><dd class="font-semibold">{{ $report['company']->tax_number ?: 'Not configured' }}</dd></div>
                <div><dt class="text-gray-500">Currency</dt><dd class="font-semibold">MVR</dd></div>
            </dl>
            <div class="mt-5 grid gap-4 border-t border-gray-100 pt-5 md:grid-cols-2 dark:border-white/10">
                <label class="text-sm font-medium">Box 8 — Adjustments reducing GST liability
                    <input wire:model.live.debounce.400ms="adjustments" type="number" min="0" step="0.01" class="mt-2 w-full rounded-lg border-gray-300 dark:border-white/15 dark:bg-gray-900">
                    <span class="mt-1 block text-xs font-normal text-gray-500">Use only for eligible MIRA adjustments such as the applicable bad-debt or rate-change credit-note amount.</span>
                </label>
                <label class="text-sm font-medium">Box 9 — GST collected in excess
                    <input wire:model.live.debounce.400ms="excessGstCollected" type="number" min="0" step="0.01" class="mt-2 w-full rounded-lg border-gray-300 dark:border-white/15 dark:bg-gray-900">
                    <span class="mt-1 block text-xs font-normal text-gray-500">Enter excess GST collected for this taxable period, if any.</span>
                </label>
            </div>
            @if (!$report['company']->tax_number)
                <div class="mt-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-400/10 dark:text-warning-300">Add the GST registration number under Administration → Settings before filing.</div>
            @endif
            @if ($report['foreign_currency_transactions'] > 0)
                <div class="mt-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-800 dark:bg-danger-400/10 dark:text-danger-300">{{ $report['foreign_currency_transactions'] }} foreign-currency transaction(s) were excluded. Convert them to MVR before preparing the MIRA return.</div>
            @endif
        </section>

        <section class="grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5"><p class="text-sm text-gray-500">Output GST</p><p class="mt-2 text-2xl font-bold">MVR {{ number_format($summary['box_6_output_tax'], 2) }}</p></div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5"><p class="text-sm text-gray-500">Claimable Input GST</p><p class="mt-2 text-2xl font-bold text-success-600">MVR {{ number_format($summary['box_7_input_tax'], 2) }}</p></div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5"><p class="text-sm text-gray-500">GST Liability / (Credit)</p><p class="mt-2 text-2xl font-bold {{ $summary['box_10_gst_liability'] < 0 ? 'text-success-600' : 'text-warning-600' }}">MVR {{ number_format($summary['box_10_gst_liability'], 2) }}</p></div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-semibold">MIRA 205 Summary</h2><p class="text-sm text-gray-500">Review these figures against your supporting documents before submission.</p></div><div class="flex flex-wrap gap-2 print:hidden"><x-filament::button wire:click="downloadMira205">Download MIRA 205 CSV</x-filament::button><x-filament::button color="gray" x-on:click="window.print()">Print / Save PDF</x-filament::button></div></div>
            @php($boxes = [1 => ['Sales subject to GST (inclusive)', 'box_1_standard_rated_sales_inclusive'], 2 => ['Zero-rated sales', 'box_2_zero_rated_sales'], 3 => ['Exempt sales', 'box_3_exempt_sales'], 4 => ['Out-of-scope sales', 'box_4_out_of_scope_sales'], 5 => ['Total sales', 'box_5_total_sales'], 6 => ['Output tax', 'box_6_output_tax'], 7 => ['Claimable input tax', 'box_7_input_tax'], 8 => ['Adjustments', 'box_8_adjustments'], 9 => ['GST collected in excess', 'box_9_excess_gst_collected'], 10 => ['GST liability / (credit)', 'box_10_gst_liability']])
            <div class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead class="text-left text-gray-500"><tr><th class="pb-3">Box</th><th class="pb-3">Description</th><th class="pb-3 text-right">MVR</th></tr></thead><tbody>@foreach($boxes as $box => [$label, $key])<tr class="border-t border-gray-100 dark:border-white/10"><td class="py-3">{{ $box }}</td><td class="py-3">{{ $label }}</td><td class="py-3 text-right font-medium">{{ number_format($summary[$key], 2) }}</td></tr>@endforeach</tbody></table></div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5"><div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-semibold">Input Tax Statement</h2><p class="text-sm text-gray-500">{{ $report['input_rows']->count() }} purchase and supplier credit-note lines.</p></div><x-filament::button wire:click="downloadInputStatement" size="sm">Download CSV</x-filament::button></div><dl class="mt-5 space-y-3 text-sm"><div class="flex justify-between"><dt>Claimable GST</dt><dd class="font-semibold">MVR {{ number_format($summary['box_7_input_tax'], 2) }}</dd></div><div class="flex justify-between"><dt>Non-claimable GST</dt><dd>MVR {{ number_format($summary['non_claimable_input_tax'], 2) }}</dd></div></dl></div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-white/5"><div class="flex items-center justify-between gap-3"><div><h2 class="text-lg font-semibold">Output Tax Statement</h2><p class="text-sm text-gray-500">{{ $report['output_rows']->count() }} sale and customer credit-note lines.</p></div><x-filament::button wire:click="downloadOutputStatement" size="sm">Download CSV</x-filament::button></div><dl class="mt-5 space-y-3 text-sm"><div class="flex justify-between"><dt>Standard-rated sales</dt><dd>MVR {{ number_format($summary['box_1_standard_rated_sales_inclusive'], 2) }}</dd></div><div class="flex justify-between"><dt>Output GST</dt><dd class="font-semibold">MVR {{ number_format($summary['box_6_output_tax'], 2) }}</dd></div></dl></div>
        </section>
    </div>
</x-filament-panels::page>
