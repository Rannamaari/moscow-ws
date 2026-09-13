<script setup>
import { ref, watch } from 'vue';
import { usePosStore } from '../../stores/posStore';

const emit = defineEmits(['close']);
const store = usePosStore();
const search = ref('');
const quickForm = ref({
    name: '',
    phone: '',
    email: '',
    tax_number: '',
    payment_terms_days: 7,
});

watch(search, (value) => {
    store.searchCustomers(value);
}, { immediate: true });

async function submitQuickCreate() {
    await store.createCustomer(quickForm.value);
    quickForm.value = { name: '', phone: '', email: '', tax_number: '', payment_terms_days: 7 };
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/55 p-4">
        <div class="pos-card pos-scrollbar max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-[32px] p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-2xl font-bold text-white">Select Customer</h2>
                <button class="pos-button-secondary" @click="emit('close')">Close</button>
            </div>

            <input v-model="search" class="pos-input mb-4" placeholder="Search by name, phone, customer code, or GST number">

            <div class="mb-6 space-y-3">
                <div
                    v-for="entry in store.customerResults"
                    :key="entry.id"
                    class="flex w-full items-center justify-between rounded-[24px] border border-white/8 bg-white/[0.03] px-4 py-4 text-left transition hover:bg-white/[0.06]"
                >
                    <button class="min-w-0 flex-1 text-left" @click="store.setCustomer(entry); emit('close')">
                        <p class="font-semibold text-white">{{ entry.name }}</p>
                        <p class="mt-1 text-sm text-[var(--pos-muted)]">{{ entry.code }}<span v-if="entry.phone"> • {{ entry.phone }}</span></p>
                    </button>
                    <div class="ml-4 flex items-center gap-3 text-right text-sm">
                        <button v-if="!entry.is_walk_in" class="pos-button-secondary" :disabled="store.loading.loadingCustomerStatement" @click="store.openCustomerStatement(entry)">Statement</button>
                        <div>
                        <p class="text-[var(--pos-muted)]">Balance</p>
                        <p class="font-semibold text-white">{{ store.formatMoney(entry.balance) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="store.canCreateCustomer" class="rounded-[28px] border border-white/8 bg-white/[0.03] p-4">
                <h3 class="mb-3 text-lg font-semibold text-white">Quick New Customer</h3>
                <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <input v-model="quickForm.name" class="pos-input" placeholder="Name">
                    <input v-model="quickForm.phone" class="pos-input" placeholder="Phone">
                    <input v-model="quickForm.email" class="pos-input" placeholder="Email (optional)">
                    <input v-model="quickForm.tax_number" class="pos-input" placeholder="Customer GST number (optional)">
                    <select v-model.number="quickForm.payment_terms_days" class="pos-input">
                        <option :value="7">Due 7 (default)</option>
                        <option :value="15">Due 15</option>
                        <option :value="30">Due 30</option>
                    </select>
                </div>
                <div class="mt-3 flex justify-end">
                    <button class="pos-button-primary" @click="submitQuickCreate">Create Customer</button>
                </div>
            </div>
        </div>
    </div>
</template>
