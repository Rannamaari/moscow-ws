<script setup>
import { computed, ref, watch } from 'vue';
import { usePosStore } from '../../stores/posStore';

const emit = defineEmits(['close']);
const store = usePosStore();
const form = ref({
    amount: '',
    payment_method: 'cash',
    reference: '',
    notes: '',
});
const localError = ref('');
const sale = computed(() => store.creditPaymentSale);
const balance = computed(() => Number(sale.value?.balance_due ?? 0));

watch(sale, (value) => {
    form.value.amount = Number(value?.balance_due ?? 0).toFixed(2);
}, { immediate: true });

async function submit() {
    const amount = Number(form.value.amount);

    if (! Number.isFinite(amount) || amount <= 0) {
        localError.value = 'Enter a payment amount greater than zero.';
        return;
    }

    if (Math.round(amount * 100) > Math.round(balance.value * 100)) {
        localError.value = 'Payment cannot be greater than the outstanding balance.';
        return;
    }

    localError.value = '';
    await store.receiveCreditPayment({
        ...form.value,
        amount,
        reference: form.value.reference || null,
        notes: form.value.notes || null,
    });
}
</script>

<template>
    <div class="fixed inset-0 z-[70] flex items-center justify-center bg-black/65 p-4">
        <div class="pos-card w-full max-w-xl rounded-[32px] p-6">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs uppercase tracking-[0.2em] text-[var(--pos-accent-strong)]">Credit collection</p>
                    <h2 class="mt-2 text-2xl font-bold text-white">Receive Payment</h2>
                </div>
                <button class="pos-button-secondary" @click="emit('close')">Close</button>
            </div>

            <div class="mb-5 grid gap-3 rounded-[24px] border border-white/8 bg-white/[0.03] p-4 sm:grid-cols-2">
                <div>
                    <p class="text-xs uppercase tracking-[0.16em] text-[var(--pos-muted)]">Invoice</p>
                    <p class="mt-1 font-semibold text-white">{{ sale?.sale_number }}</p>
                    <p class="mt-1 text-sm text-[var(--pos-muted)]">{{ sale?.customer?.name }}</p>
                </div>
                <div class="sm:text-right">
                    <p class="text-xs uppercase tracking-[0.16em] text-[var(--pos-muted)]">Outstanding</p>
                    <p class="mt-1 text-2xl font-bold text-[var(--pos-accent-strong)]">{{ store.formatMoney(balance) }}</p>
                </div>
            </div>

            <p v-if="localError" class="mb-4 rounded-2xl bg-rose-500/15 px-4 py-3 text-sm text-rose-100">{{ localError }}</p>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-2 block text-sm font-semibold text-white">Amount</span>
                    <input v-model="form.amount" class="pos-input" inputmode="decimal" type="number" min="0.01" :max="balance" step="0.01">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-semibold text-white">Payment Method</span>
                    <select v-model="form.payment_method" class="pos-input">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-sm font-semibold text-white">Reference (optional)</span>
                    <input v-model="form.reference" class="pos-input" maxlength="255" placeholder="Transfer, cheque, or receipt reference">
                </label>
                <label class="block sm:col-span-2">
                    <span class="mb-2 block text-sm font-semibold text-white">Notes (optional)</span>
                    <textarea v-model="form.notes" class="pos-input min-h-24" maxlength="1000" placeholder="Payment notes"></textarea>
                </label>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button class="pos-button-secondary" @click="emit('close')">Cancel</button>
                <button class="pos-button-primary" :disabled="store.loading.receivingCreditPayment" @click="submit">
                    {{ store.loading.receivingCreditPayment ? 'Recording…' : 'Receive Payment' }}
                </button>
            </div>
        </div>
    </div>
</template>
