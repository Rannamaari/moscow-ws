<script setup>
import { computed, reactive } from 'vue';
import { usePosStore } from '../../stores/posStore';

const emit = defineEmits(['close']);
const store = usePosStore();

const returnMap = reactive({});

const periodOptions = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'last_7_days', label: 'Last 7 Days' },
    { value: 'custom', label: 'Custom Range' },
];

const statusOptions = [
    { value: '', label: 'All Statuses' },
    { value: 'completed', label: 'Completed' },
    ...(store.canViewCancelledSales ? [{ value: 'cancelled', label: 'Cancelled' }] : []),
    { value: 'refunded', label: 'Refunded' },
    { value: 'partially_refunded', label: 'Partially Refunded' },
];

const paymentMethodOptions = [
    { value: '', label: 'All Payments' },
    { value: 'cash', label: 'Cash' },
    { value: 'card', label: 'Card' },
    { value: 'bank_transfer', label: 'Bank Transfer' },
    { value: 'other', label: 'Other' },
];

const activeSaleDate = computed(() => store.activeSaleLookup?.completed_at ?? store.activeSaleLookup?.created_at ?? null);
const hasPagination = computed(() => store.salesHistoryMeta.last_page > 1);
const canReturnSelectedSale = computed(() => store.canReturnSales && store.activeSaleLookup && store.activeSaleLookup.items.some((item) => Number(item.returnable_quantity) > 0));

async function applyFilters() {
    await store.applySalesHistoryFilters();
}

async function resetFilters() {
    await store.clearSalesHistoryFilters();
}

async function openSale(saleId) {
    returnMapClear();
    await store.loadSale(saleId);
}

function returnMapClear() {
    Object.keys(returnMap).forEach((key) => {
        delete returnMap[key];
    });
}

async function submitReturn() {
    const items = Object.entries(returnMap)
        .filter(([, quantity]) => Number(quantity) > 0)
        .map(([sale_item_id, quantity]) => ({
            sale_item_id,
            quantity: Number(quantity),
        }));

    if (! items.length) return;

    await store.processReturn(items);
    returnMapClear();
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/55 p-4">
        <div class="pos-card pos-scrollbar max-h-[92vh] w-full max-w-7xl overflow-y-auto rounded-[32px] p-6">
            <div class="mb-5 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-white">Sales History</h2>
                    <p class="mt-1 text-sm text-[var(--pos-muted)]">Browse recent sales, inspect details, and process returns without losing the current checkout cart.</p>
                </div>
                <button class="pos-button-secondary" @click="emit('close')">Back to Checkout</button>
            </div>

            <div class="mb-5 grid gap-3 rounded-[28px] border border-white/8 bg-white/[0.03] p-4 md:grid-cols-2 xl:grid-cols-4">
                <label class="space-y-2">
                    <span class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Search</span>
                    <input
                        :value="store.salesHistoryFilters.search"
                        class="pos-input"
                        placeholder="Sale number, customer, phone"
                        @input="store.updateSalesHistoryFilter('search', $event.target.value)"
                        @keydown.enter.prevent="applyFilters"
                    >
                </label>

                <label class="space-y-2">
                    <span class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Period</span>
                    <select
                        :value="store.salesHistoryFilters.period"
                        class="pos-input"
                        @change="store.updateSalesHistoryFilter('period', $event.target.value)"
                    >
                        <option v-for="option in periodOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Cashier</span>
                    <input
                        :value="store.salesHistoryFilters.cashier"
                        class="pos-input"
                        placeholder="Cashier name"
                        @input="store.updateSalesHistoryFilter('cashier', $event.target.value)"
                        @keydown.enter.prevent="applyFilters"
                    >
                </label>

                <label class="space-y-2">
                    <span class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Customer</span>
                    <input
                        :value="store.salesHistoryFilters.customer"
                        class="pos-input"
                        placeholder="Customer name or phone"
                        @input="store.updateSalesHistoryFilter('customer', $event.target.value)"
                        @keydown.enter.prevent="applyFilters"
                    >
                </label>

                <label class="space-y-2">
                    <span class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Status</span>
                    <select
                        :value="store.salesHistoryFilters.status"
                        class="pos-input"
                        @change="store.updateSalesHistoryFilter('status', $event.target.value)"
                    >
                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Payment Method</span>
                    <select
                        :value="store.salesHistoryFilters.payment_method"
                        class="pos-input"
                        @change="store.updateSalesHistoryFilter('payment_method', $event.target.value)"
                    >
                        <option v-for="option in paymentMethodOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>

                <label v-if="store.salesHistoryFilters.period === 'custom'" class="space-y-2">
                    <span class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Date From</span>
                    <input
                        :value="store.salesHistoryFilters.date_from"
                        type="date"
                        class="pos-input"
                        @input="store.updateSalesHistoryFilter('date_from', $event.target.value)"
                    >
                </label>

                <label v-if="store.salesHistoryFilters.period === 'custom'" class="space-y-2">
                    <span class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Date To</span>
                    <input
                        :value="store.salesHistoryFilters.date_to"
                        type="date"
                        class="pos-input"
                        @input="store.updateSalesHistoryFilter('date_to', $event.target.value)"
                    >
                </label>

                <div class="flex flex-wrap items-end gap-2 xl:col-span-4">
                    <button class="pos-button-primary" :disabled="store.loading.loadingSaleLookup" @click="applyFilters">
                        {{ store.loading.loadingSaleLookup ? 'Loading…' : 'Apply Filters' }}
                    </button>
                    <button class="pos-button-secondary" @click="resetFilters">Reset</button>
                </div>
            </div>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                <section class="rounded-[28px] border border-white/8 bg-white/[0.03]">
                    <div class="flex items-center justify-between border-b border-white/8 px-4 py-3 text-sm text-[var(--pos-muted)]">
                        <p>
                            Showing {{ store.salesHistoryMeta.from ?? 0 }}-{{ store.salesHistoryMeta.to ?? 0 }}
                            of {{ store.salesHistoryMeta.total }}
                        </p>
                        <p>{{ store.formatStatus(store.salesHistoryFilters.period).replace('_', ' ') }}</p>
                    </div>

                    <div v-if="store.saleSearchResults.length" class="divide-y divide-white/8">
                        <button
                            v-for="result in store.saleSearchResults"
                            :key="result.id"
                            class="w-full px-4 py-4 text-left transition hover:bg-white/[0.04]"
                            @click="openSale(result.id)"
                        >
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold text-white">{{ result.sale_number }}</p>
                                        <span class="pos-badge">{{ store.formatStatus(result.status) }}</span>
                                    </div>
                                    <p class="mt-1 text-sm text-[var(--pos-muted)]">{{ store.formatDateTime(result.date) }}</p>
                                    <p class="mt-2 text-sm text-white">{{ result.customer }}</p>
                                    <p class="mt-1 text-sm text-[var(--pos-muted)]">
                                        {{ result.cashier ?? 'Unknown cashier' }}
                                        <span v-if="result.payment_method">• {{ result.payment_method }}</span>
                                    </p>
                                    <p v-if="result.branch" class="mt-1 text-xs uppercase tracking-[0.16em] text-[var(--pos-accent)]">{{ result.branch }}</p>
                                </div>

                                <div class="grid shrink-0 gap-1 text-right text-sm">
                                    <p class="font-semibold text-[var(--pos-accent-strong)]">{{ store.formatMoney(result.total) }}</p>
                                    <p class="text-[var(--pos-muted)]">Paid {{ store.formatMoney(result.paid) }}</p>
                                    <p class="text-[var(--pos-muted)]">Balance {{ store.formatMoney(result.balance) }}</p>
                                </div>
                            </div>
                        </button>
                    </div>

                    <div v-else class="px-4 py-10 text-center text-sm text-[var(--pos-muted)]">
                        No sales matched the current filters.
                    </div>

                    <div v-if="hasPagination" class="flex items-center justify-between border-t border-white/8 px-4 py-3">
                        <button class="pos-button-secondary" :disabled="store.salesHistoryMeta.current_page <= 1" @click="store.goToSalesHistoryPage(store.salesHistoryMeta.current_page - 1)">
                            Previous
                        </button>
                        <p class="text-sm text-[var(--pos-muted)]">
                            Page {{ store.salesHistoryMeta.current_page }} of {{ store.salesHistoryMeta.last_page }}
                        </p>
                        <button class="pos-button-secondary" :disabled="store.salesHistoryMeta.current_page >= store.salesHistoryMeta.last_page" @click="store.goToSalesHistoryPage(store.salesHistoryMeta.current_page + 1)">
                            Next
                        </button>
                    </div>
                </section>

                <section class="rounded-[28px] border border-white/8 bg-white/[0.03] p-4">
                    <div v-if="store.activeSaleLookup" class="space-y-5">
                        <div class="flex flex-col gap-4 border-b border-white/8 pb-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h3 class="text-2xl font-bold text-white">{{ store.activeSaleLookup.sale_number }}</h3>
                                <p class="mt-1 text-sm text-[var(--pos-muted)]">{{ store.formatDateTime(activeSaleDate) }}</p>
                                <p class="mt-2 text-sm text-white">{{ store.activeSaleLookup.customer?.name ?? 'Walk-in Customer' }}</p>
                                <p class="mt-1 text-sm text-[var(--pos-muted)]">
                                    {{ store.activeSaleLookup.cashier?.name ?? 'Unknown cashier' }}
                                    <span v-if="store.activeSaleLookup.payment_method_summary">• {{ store.activeSaleLookup.payment_method_summary }}</span>
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button class="pos-button-secondary" @click="store.printActiveSale(undefined, 'thermal')">Thermal Receipt</button>
                                <button class="pos-button-secondary" @click="store.printActiveSale(undefined, 'a4')">A4 Tax Invoice</button>
                                <button
                                    v-if="store.canReceiveCustomerPayments && store.activeSaleLookup.customer && !store.activeSaleLookup.customer.is_walk_in && Number(store.activeSaleLookup.balance_due) > 0"
                                    class="pos-button-primary"
                                    @click="store.openCreditPayment(store.activeSaleLookup)"
                                >Receive Payment</button>
                                <button v-if="canReturnSelectedSale" class="pos-button-primary" :disabled="store.loading.processingReturn" @click="submitReturn">
                                    {{ store.loading.processingReturn ? 'Processing…' : 'Return Items' }}
                                </button>
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2">
                            <div class="rounded-[22px] border border-white/8 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Company / Branch / Warehouse</p>
                                <p class="mt-2 font-semibold text-white">{{ store.activeSaleLookup.company?.name }}</p>
                                <p class="mt-1 text-sm text-[var(--pos-muted)]">{{ store.activeSaleLookup.branch?.name }} / {{ store.activeSaleLookup.warehouse?.name }}</p>
                            </div>

                            <div class="rounded-[22px] border border-white/8 p-4">
                                <p class="text-xs uppercase tracking-[0.2em] text-[var(--pos-muted)]">Totals</p>
                                <div class="mt-2 space-y-1 text-sm">
                                    <p class="flex items-center justify-between"><span>Subtotal</span><span class="text-white">{{ store.formatMoney(store.activeSaleLookup.subtotal) }}</span></p>
                                    <p class="flex items-center justify-between"><span>Discount</span><span class="text-white">{{ store.formatMoney(store.activeSaleLookup.discount_total) }}</span></p>
                                    <p class="flex items-center justify-between"><span>Tax</span><span class="text-white">{{ store.formatMoney(store.activeSaleLookup.tax_total) }}</span></p>
                                    <p class="flex items-center justify-between font-semibold text-white"><span>Grand Total</span><span>{{ store.formatMoney(store.activeSaleLookup.grand_total) }}</span></p>
                                    <p class="flex items-center justify-between"><span>Paid</span><span class="text-white">{{ store.formatMoney(store.activeSaleLookup.paid_total) }}</span></p>
                                    <p class="flex items-center justify-between"><span>{{ store.activeSaleLookup.status === 'cancelled' ? 'Amount Due (cancelled)' : 'Balance Due' }}</span><span class="text-white">{{ store.formatMoney(store.activeSaleLookup.status === 'cancelled' ? 0 : store.activeSaleLookup.balance_due) }}</span></p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h4 class="mb-3 text-lg font-semibold text-white">Items</h4>
                            <div class="space-y-3">
                                <div v-for="item in store.activeSaleLookup.items" :key="item.id" class="rounded-[22px] border border-white/8 p-4">
                                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div>
                                            <p class="font-semibold text-white">{{ item.name }}</p>
                                            <p class="mt-1 text-sm text-[var(--pos-muted)]">SKU {{ item.sku ?? 'N/A' }}</p>
                                            <p class="mt-1 text-sm text-[var(--pos-muted)]">Qty {{ item.quantity }} • Unit {{ store.formatMoney(item.unit_price) }}</p>
                                        </div>
                                        <div class="text-right text-sm">
                                            <p class="font-semibold text-[var(--pos-accent-strong)]">{{ store.formatMoney(item.line_total) }}</p>
                                            <p class="mt-1 text-[var(--pos-muted)]">Discount {{ store.formatMoney(item.discount_amount) }}</p>
                                            <p class="mt-1 text-[var(--pos-muted)]">Tax {{ store.formatMoney(item.tax_amount) }}</p>
                                        </div>
                                    </div>

                                    <div v-if="store.canReturnSales" class="mt-3 grid gap-3 md:grid-cols-[repeat(4,minmax(0,1fr))_160px]">
                                        <div class="rounded-2xl bg-black/10 px-3 py-2 text-sm">
                                            <p class="text-[var(--pos-muted)]">Original</p>
                                            <p class="mt-1 text-white">{{ item.quantity }}</p>
                                        </div>
                                        <div class="rounded-2xl bg-black/10 px-3 py-2 text-sm">
                                            <p class="text-[var(--pos-muted)]">Previously Returned</p>
                                            <p class="mt-1 text-white">{{ item.returned_quantity }}</p>
                                        </div>
                                        <div class="rounded-2xl bg-black/10 px-3 py-2 text-sm">
                                            <p class="text-[var(--pos-muted)]">Returnable</p>
                                            <p class="mt-1 text-white">{{ item.returnable_quantity }}</p>
                                        </div>
                                        <div class="rounded-2xl bg-black/10 px-3 py-2 text-sm">
                                            <p class="text-[var(--pos-muted)]">Barcode</p>
                                            <p class="mt-1 text-white">{{ item.barcode ?? 'N/A' }}</p>
                                        </div>
                                        <input
                                            v-if="Number(item.returnable_quantity) > 0"
                                            v-model="returnMap[item.id]"
                                            class="pos-input"
                                            :placeholder="`Return qty (max ${item.returnable_quantity})`"
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h4 class="mb-3 text-lg font-semibold text-white">Payments</h4>
                            <div class="space-y-3">
                                <div v-for="payment in store.activeSaleLookup.payments" :key="payment.id" class="rounded-[22px] border border-white/8 p-4">
                                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                        <div>
                                            <p class="font-semibold text-white">{{ store.formatStatus(payment.payment_method) }}</p>
                                            <p class="mt-1 text-sm text-[var(--pos-muted)]">{{ payment.reference ?? 'No reference' }}</p>
                                            <p v-if="payment.paid_at" class="mt-1 text-sm text-[var(--pos-muted)]">{{ store.formatDateTime(payment.paid_at) }}</p>
                                        </div>
                                        <div class="grid gap-1 text-right text-sm">
                                            <p class="text-white">Amount {{ store.formatMoney(payment.amount) }}</p>
                                            <p v-if="payment.amount_tendered" class="text-[var(--pos-muted)]">Tendered {{ store.formatMoney(payment.amount_tendered) }}</p>
                                            <p v-if="payment.change_due" class="text-[var(--pos-muted)]">Change {{ store.formatMoney(payment.change_due) }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex min-h-[20rem] items-center justify-center rounded-[24px] border border-dashed border-white/10 text-center text-sm text-[var(--pos-muted)]">
                        Select a sale from the left to inspect items, payments, and returnable quantities.
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
