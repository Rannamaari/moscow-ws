<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePosStore } from './stores/posStore';
import PosHeader from './components/pos/PosHeader.vue';
import ProductSearch from './components/pos/ProductSearch.vue';
import ProductSearchResults from './components/pos/ProductSearchResults.vue';
import Cart from './components/pos/Cart.vue';
import CartSummary from './components/pos/CartSummary.vue';
import CustomerSelector from './components/pos/CustomerSelector.vue';
import PaymentModal from './components/pos/PaymentModal.vue';
import HeldSalesModal from './components/pos/HeldSalesModal.vue';
import SaleCompleteModal from './components/pos/SaleCompleteModal.vue';
import SaleLookupModal from './components/pos/SaleLookupModal.vue';
import ShortcutHelpModal from './components/pos/ShortcutHelpModal.vue';
import CashierShiftModal from './components/pos/CashierShiftModal.vue';
import CustomerStatementModal from './components/pos/CustomerStatementModal.vue';
import ReceiveCreditPaymentModal from './components/pos/ReceiveCreditPaymentModal.vue';

const props = defineProps({
    bootstrap: {
        type: Object,
        required: true,
    },
});

const store = usePosStore();
const searchRef = ref(null);
const searchDebounce = ref(null);
const shiftModalMode = ref(null);

store.hydrate(props.bootstrap);

const canOpenPayment = computed(() => store.hasActiveShift && store.items.length > 0);

function focusSearch() {
    searchRef.value?.focusInput();
}

function onSearchInput(value) {
    clearTimeout(searchDebounce.value);
    searchDebounce.value = setTimeout(() => {
        store.searchProducts(value);
    }, 300);
}

function onGlobalKeydown(event) {
    if (event.key === 'F2') {
        event.preventDefault();
        focusSearch();
    }

    if (event.key === 'F4') {
        event.preventDefault();
        store.customerModalOpen = true;
    }

    if (event.key === 'F6') {
        event.preventDefault();
        if (store.canHoldSale) {
            store.holdSale();
        }
    }

    if (event.key === 'F8') {
        event.preventDefault();
        if (canOpenPayment.value) {
            store.paymentModalOpen = true;
        }
    }

    if (event.key === 'Escape') {
        store.paymentModalOpen = false;
        store.customerModalOpen = false;
        store.heldSalesModalOpen = false;
        store.saleLookupModalOpen = false;
        store.shortcutModalOpen = false;
        store.customerStatementModalOpen = false;
        store.creditPaymentModalOpen = false;
        store.creditPaymentSale = null;
        store.saleCompleteModal = null;
        focusSearch();
    }
}

function onBeforeUnload(event) {
    if (! store.dirty) return;
    event.preventDefault();
    event.returnValue = '';
}

async function signOut() {
    if (store.dirty && ! window.confirm('The current sale is not finished yet. Sign out anyway?')) {
        return;
    }

    try {
        await window.axios.post('/logout');
        window.location.href = '/login';
    } catch {
        window.location.href = '/login';
    }
}

function closeShift() {
    if (store.dirty) {
        window.alert('Finish, hold, or start a new sale before closing the cashier shift. The current cart is not part of EOD yet.');
        return;
    }

    shiftModalMode.value = 'close';
}

function shiftSaved(response) {
    if (shiftModalMode.value === 'open') {
        store.setActiveShift(response.data);
        shiftModalMode.value = null;
        store.notify(`Shift ${response.data.shift_number} opened.`, 'success');
        focusSearch();
        return;
    }

    store.setActiveShift(null);
    shiftModalMode.value = null;
    store.notify('Shift closed and EOD report generated.', 'success');
    window.open(response.print_url, '_blank', 'width=900,height=900');
    focusSearch();
}

watch(() => store.items.length, () => {
    focusSearch();
});

onMounted(() => {
    window.addEventListener('keydown', onGlobalKeydown);
    window.addEventListener('beforeunload', onBeforeUnload);
    focusSearch();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onGlobalKeydown);
    window.removeEventListener('beforeunload', onBeforeUnload);
});
</script>

<template>
    <div class="pos-shell min-h-screen p-4 text-[var(--pos-paper)] md:p-6">
        <div class="mx-auto flex min-h-[calc(100vh-2rem)] max-w-[1800px] flex-col gap-4">
            <PosHeader @shortcuts="store.shortcutModalOpen = true" @held-sales="store.heldSalesModalOpen = true; store.loadHeldSales()" @sale-lookup="store.prepareSalesHistory()" @open-shift="shiftModalMode = 'open'" @close-shift="closeShift" @sign-out="signOut" />

            <div v-if="store.errors.length" class="pos-card rounded-[28px] border border-[var(--pos-danger)]/30 bg-[rgba(255,93,115,0.12)] p-4 text-sm text-rose-100">
                <p class="mb-2 font-semibold uppercase tracking-[0.2em] text-rose-200">Attention</p>
                <ul class="space-y-1">
                    <li v-for="error in store.errors" :key="error">{{ error }}</li>
                </ul>
            </div>

            <div v-if="!store.hasActiveShift" class="pos-card rounded-[28px] border border-[var(--pos-accent)]/35 bg-[var(--pos-accent)]/10 p-4 text-sm text-[var(--pos-paper)]">
                <strong>{{ store.t('pos_locked') }}</strong>
                <span class="ml-1 text-[var(--pos-muted)]">{{ store.t('pos_locked_help') }}</span>
            </div>

            <div class="grid flex-1 gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(420px,0.8fr)]" :class="{ 'pointer-events-none opacity-45': !store.hasActiveShift }">
                <section class="pos-card flex min-h-[40rem] flex-col rounded-[32px] p-4 md:p-6">
                    <ProductSearch ref="searchRef" v-model="store.searchInputValue" :disabled="store.loading.completingSale || !store.hasActiveShift" @search-input="onSearchInput" @submit="store.scanOrLookup(store.searchInputValue)" />
                    <ProductSearchResults
                        :results="store.searchResults"
                        :selected-index="store.selectedSearchIndex"
                        @add="store.addProduct"
                        @select-index="store.selectedSearchIndex = $event"
                    />
                </section>

                <section class="pos-card flex min-h-[40rem] flex-col rounded-[32px] p-4 md:p-6">
                    <Cart :is-resumed-sale="store.isResumedSale" @open-customer="store.customerModalOpen = true" />
                    <CartSummary
                        :can-open-payment="canOpenPayment"
                        @hold="store.holdSale()"
                        @payment="store.paymentModalOpen = true"
                        @new-sale="store.resetSale()"
                    />
                </section>
            </div>
        </div>

        <CustomerSelector v-if="store.customerModalOpen" @close="store.customerModalOpen = false; focusSearch()" />
        <PaymentModal v-if="store.paymentModalOpen" @close="store.paymentModalOpen = false; focusSearch()" />
        <HeldSalesModal v-if="store.heldSalesModalOpen" @close="store.heldSalesModalOpen = false; focusSearch()" />
        <SaleCompleteModal v-if="store.saleCompleteModal" @close="store.saleCompleteModal = null; focusSearch()" />
        <SaleLookupModal v-if="store.saleLookupModalOpen" @close="store.saleLookupModalOpen = false; focusSearch()" />
        <ShortcutHelpModal v-if="store.shortcutModalOpen" @close="store.shortcutModalOpen = false; focusSearch()" />
        <CashierShiftModal v-if="shiftModalMode" :mode="shiftModalMode" @close="shiftModalMode = null; focusSearch()" @saved="shiftSaved" />
        <CustomerStatementModal v-if="store.customerStatementModalOpen" @close="store.customerStatementModalOpen = false; focusSearch()" />
        <ReceiveCreditPaymentModal v-if="store.creditPaymentModalOpen" @close="store.creditPaymentModalOpen = false; store.creditPaymentSale = null; focusSearch()" />
    </div>
</template>
