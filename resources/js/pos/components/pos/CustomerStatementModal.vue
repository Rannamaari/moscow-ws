<script setup>
import { usePosStore } from '../../stores/posStore';

const emit = defineEmits(['close']);
const store = usePosStore();

function date(value) {
    if (! value) return 'N/A';
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: '2-digit' }).format(new Date(value));
}
</script>

<template>
    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
        <div class="pos-card pos-scrollbar max-h-[92vh] w-full max-w-6xl overflow-y-auto rounded-[32px] p-6">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-[var(--pos-accent-strong)]">Customer account</p>
                    <h2 class="mt-2 text-2xl font-bold text-white">{{ store.customerStatement?.customer?.name }}</h2>
                    <p class="mt-1 text-sm text-[var(--pos-muted)]">
                        {{ store.customerStatement?.customer?.code }}
                        <span v-if="store.customerStatement?.customer?.phone"> • {{ store.customerStatement.customer.phone }}</span>
                    </p>
                </div>
                <button class="pos-button-secondary" @click="emit('close')">Close</button>
                    <p v-if="store.customerStatement?.customer?.tax_number" class="mt-1 text-sm text-[var(--pos-muted)]">GST No. {{ store.customerStatement.customer.tax_number }} • Due {{ store.customerStatement.customer.payment_terms_days }}</p>
            </div>

            <div class="mb-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-[22px] border border-white/8 bg-white/[0.03] p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-[var(--pos-muted)]">Total invoiced</p>
                    <p class="mt-2 text-xl font-bold text-white">{{ store.formatMoney(store.customerStatement?.summary?.total_invoiced) }}</p>
                </div>
                <div class="rounded-[22px] border border-white/8 bg-white/[0.03] p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-[var(--pos-muted)]">Total paid</p>
                    <p class="mt-2 text-xl font-bold text-white">{{ store.formatMoney(store.customerStatement?.summary?.total_paid) }}</p>
                </div>
                <div class="rounded-[22px] border border-[var(--pos-accent)]/35 bg-[var(--pos-accent)]/10 p-4">
                    <p class="text-xs uppercase tracking-[0.16em] text-[var(--pos-muted)]">Outstanding</p>
                    <p class="mt-2 text-xl font-bold text-[var(--pos-accent-strong)]">{{ store.formatMoney(store.customerStatement?.summary?.outstanding_balance) }}</p>
                </div>
            </div>

            <section>
                <h3 class="mb-3 text-lg font-semibold text-white">Invoices</h3>
                <div class="overflow-x-auto rounded-[22px] border border-white/8">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-white/[0.05] text-[var(--pos-muted)]">
                            <tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Invoice</th><th class="px-4 py-3">Due date</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Paid</th><th class="px-4 py-3 text-right">Outstanding</th><th class="px-4 py-3"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-white/8">
                            <tr v-for="invoice in store.customerStatement?.invoices" :key="invoice.id">
                                <td class="whitespace-nowrap px-4 py-3 text-[var(--pos-muted)]">{{ date(invoice.date) }}</td>
                                <td class="px-4 py-3 font-semibold text-white">{{ invoice.sale_number }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-white">{{ store.formatMoney(invoice.grand_total) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-[var(--pos-muted)]">{{ invoice.due_date ? date(invoice.due_date) : 'Paid' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-white">{{ store.formatMoney(invoice.paid_total) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-[var(--pos-accent-strong)]">{{ store.formatMoney(invoice.balance_due) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <button v-if="store.canReceiveCustomerPayments && Number(invoice.balance_due) > 0" class="pos-button-primary whitespace-nowrap" @click="store.openCreditPayment(invoice)">Receive Payment</button>
                                </td>
                            </tr>
                            <tr v-if="!store.customerStatement?.invoices?.length"><td colspan="7" class="px-4 py-8 text-center text-[var(--pos-muted)]">No invoices found for this customer.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="mt-7">
                <h3 class="mb-3 text-lg font-semibold text-white">Payments Received</h3>
                <div class="overflow-x-auto rounded-[22px] border border-white/8">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-white/[0.05] text-[var(--pos-muted)]">
                            <tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Invoice</th><th class="px-4 py-3">Method</th><th class="px-4 py-3">Reference</th><th class="px-4 py-3 text-right">Amount</th></tr>
                        </thead>
                        <tbody class="divide-y divide-white/8">
                            <tr v-for="payment in store.customerStatement?.payments" :key="payment.id">
                                <td class="whitespace-nowrap px-4 py-3 text-[var(--pos-muted)]">{{ store.formatDateTime(payment.paid_at) }}</td>
                                <td class="px-4 py-3 font-semibold text-white">{{ payment.sale_number ?? 'Account payment' }}</td>
                                <td class="px-4 py-3 text-white">{{ store.formatStatus(payment.payment_method) }}</td>
                                <td class="px-4 py-3 text-[var(--pos-muted)]">{{ payment.reference ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-white">{{ store.formatMoney(payment.amount) }}</td>
                            </tr>
                            <tr v-if="!store.customerStatement?.payments?.length"><td colspan="5" class="px-4 py-8 text-center text-[var(--pos-muted)]">No later payments have been recorded yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
