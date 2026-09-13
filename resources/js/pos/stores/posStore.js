import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { addOrIncrementItem, decimal, formatMoney, normalizeQuantity, paymentSummary, previewCartTotals } from '../lib/cart';

function uuid() {
    return crypto.randomUUID();
}

function defaultSalesHistoryFilters() {
    return {
        search: '',
        period: 'today',
        date_from: '',
        date_to: '',
        cashier: '',
        customer: '',
        status: '',
        payment_method: '',
        page: 1,
        per_page: 25,
    };
}

export const usePosStore = defineStore('pos', () => {
    const bootstrap = ref({});
    const items = ref([]);
    const customer = ref(null);
    const searchResults = ref([]);
    const customerResults = ref([]);
    const heldSales = ref([]);
    const saleSearchResults = ref([]);
    const salesHistoryMeta = ref({
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
        from: null,
        to: null,
    });
    const salesHistoryFilters = ref(defaultSalesHistoryFilters());
    const resumedSaleId = ref(null);
    const payments = ref([]);
    const loading = ref({
        searchingProducts: false,
        searchingCustomers: false,
        holdingSale: false,
        completingSale: false,
        loadingHeldSales: false,
        loadingSaleLookup: false,
        processingReturn: false,
        loadingCustomerStatement: false,
        receivingCreditPayment: false,
    });
    const errors = ref([]);
    const notifications = ref([]);
    const paymentModalOpen = ref(false);
    const customerModalOpen = ref(false);
    const heldSalesModalOpen = ref(false);
    const saleLookupModalOpen = ref(false);
    const shortcutModalOpen = ref(false);
    const customerStatementModalOpen = ref(false);
    const creditPaymentModalOpen = ref(false);
    const saleCompleteModal = ref(null);
    const selectedSearchIndex = ref(0);
    const activeSaleLookup = ref(null);
    const customerStatement = ref(null);
    const creditPaymentSale = ref(null);
    const searchInputValue = ref('');

    const currency = computed(() => bootstrap.value.company?.currency ?? 'MVR');
    const permissions = computed(() => new Set(bootstrap.value.user?.permissions ?? []));
    const canOverridePrice = computed(() => permissions.value.has('sales.price_override'));
    const canDiscount = computed(() => permissions.value.has('sales.discount'));
    const canCreateCustomer = computed(() => permissions.value.has('customers.create'));
    const canUseCredit = computed(() => permissions.value.has('sales.credit'));
    const canViewSalesHistory = computed(() => permissions.value.has('sales.view'));
    const canReturnSales = computed(() => permissions.value.has('sales.return'));
    const canResumeHeldSales = computed(() => permissions.value.has('sales.resume') || permissions.value.has('sales.hold'));
    const canCancelHeldSales = computed(() => permissions.value.has('sales.cancel_held'));
    const canViewCancelledSales = computed(() => permissions.value.has('sales.view_cancelled'));
    const canReceiveCustomerPayments = computed(() => permissions.value.has('customers.payments'));
    const isResumedSale = computed(() => resumedSaleId.value !== null);
    const hasNamedCustomer = computed(() => Boolean(customer.value && !customer.value.is_walk_in));
    const canHoldSale = computed(() => items.value.length > 0 && hasNamedCustomer.value);
    const totals = computed(() => previewCartTotals(items.value));
    const paymentTotals = computed(() => paymentSummary(payments.value, totals.value.grandTotal));
    const dirty = computed(() => items.value.length > 0);
    const hasSalesHistoryResults = computed(() => saleSearchResults.value.length > 0);
    const hasActiveShift = computed(() => Boolean(bootstrap.value.active_shift));

    function t(key) {
        return bootstrap.value.translations?.[key] ?? key;
    }

    function hydrate(data) {
        bootstrap.value = data;
        customer.value = data.walk_in_customer;
    }

    function setActiveShift(shift) {
        bootstrap.value = { ...bootstrap.value, active_shift: shift };
    }

    function notify(message, type = 'info') {
        notifications.value = [{ id: uuid(), message, type }];
    }

    function setErrors(payload) {
        const messages = [];

        if (typeof payload === 'string') {
            messages.push(payload);
        } else if (Array.isArray(payload)) {
            messages.push(...payload);
        } else if (payload && typeof payload === 'object') {
            Object.values(payload).forEach((value) => {
                if (Array.isArray(value)) {
                    value.forEach((entry) => {
                        if (typeof entry === 'string') {
                            messages.push(entry);
                        } else if (entry?.product_name) {
                            messages.push(`${entry.product_name}: requested ${entry.requested}, available ${entry.available}.`);
                        }
                    });
                }
            });
        }

        errors.value = messages;
    }

    function clearErrors() {
        errors.value = [];
    }

    function setSearchResults(results) {
        searchResults.value = results;
        selectedSearchIndex.value = 0;
    }

    function addProduct(product) {
        const nextItems = addOrIncrementItem(items.value, product);
        const scannedItem = nextItems.find((item) => item.productId === product.id);

        // Keep the last scanned line at the top of the cashier-facing cart.
        items.value = [
            ...nextItems.filter((item) => item.productId !== product.id),
            scannedItem,
        ];
        searchInputValue.value = '';
        setSearchResults([]);
        notify(`${product.name} added`, 'success');
    }

    function incrementItem(productId) {
        const line = items.value.find((item) => item.productId === productId);
        if (! line) return;
        line.quantity = normalizeQuantity(line.quantity + 1, line.unit.precision);
    }

    function decrementItem(productId) {
        const line = items.value.find((item) => item.productId === productId);
        if (! line) return;
        const next = normalizeQuantity(line.quantity - 1, line.unit.precision);
        if (next <= 0) {
            removeItem(productId);
            return;
        }
        line.quantity = next;
    }

    function updateItemQuantity(productId, value) {
        const line = items.value.find((item) => item.productId === productId);
        if (! line) return;
        const quantity = normalizeQuantity(value, line.unit.precision);
        if (! Number.isFinite(quantity) || quantity <= 0) return;
        line.quantity = quantity;
    }

    function updateItemPrice(productId, value) {
        if (! canOverridePrice.value) return;
        const line = items.value.find((item) => item.productId === productId);
        if (! line) return;
        line.price = decimal(value);
    }

    function updateItemDiscount(productId, value) {
        if (! canDiscount.value) return;
        const line = items.value.find((item) => item.productId === productId);
        if (! line) return;
        line.discountAmount = decimal(value);
    }

    function removeItem(productId) {
        items.value = items.value.filter((item) => item.productId !== productId);
    }

    function setCustomer(selectedCustomer) {
        customer.value = selectedCustomer;
        customerModalOpen.value = false;
    }

    function resetSale() {
        items.value = [];
        searchResults.value = [];
        customerResults.value = [];
        payments.value = [];
        resumedSaleId.value = null;
        saleCompleteModal.value = null;
        activeSaleLookup.value = null;
        searchInputValue.value = '';
        customer.value = bootstrap.value.walk_in_customer;
        clearErrors();
    }

    function resetSalesHistoryFilters() {
        salesHistoryFilters.value = defaultSalesHistoryFilters();
    }

    function serializeItems() {
        return items.value.map((item) => ({
            product_id: item.productId,
            quantity: item.quantity,
            unit_price: decimal(item.price),
            discount_amount: decimal(item.discountAmount),
        }));
    }

    function salesHistoryParams(page = salesHistoryFilters.value.page) {
        const filters = salesHistoryFilters.value;

        return {
            search: filters.search || undefined,
            period: filters.period,
            date_from: filters.period === 'custom' ? (filters.date_from || undefined) : undefined,
            date_to: filters.period === 'custom' ? (filters.date_to || undefined) : undefined,
            cashier: filters.cashier || undefined,
            customer: filters.customer || undefined,
            status: filters.status || undefined,
            payment_method: filters.payment_method || undefined,
            page,
            per_page: filters.per_page,
        };
    }

    function formatDateTime(value) {
        if (! value) return 'N/A';

        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(value));
    }

    function formatStatus(status) {
        if (! status) return '';

        return status
            .split('_')
            .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
            .join(' ');
    }

    async function searchProducts(query) {
        searchInputValue.value = query;

        if (! query.trim()) {
            setSearchResults([]);
            return;
        }

        loading.value.searchingProducts = true;
        clearErrors();

        try {
            const response = await window.axios.get('/pos/api/products/search', {
                params: { q: query },
            });
            setSearchResults(response.data.data);
        } catch (error) {
            setErrors(error.response?.data?.errors ?? 'Unable to search products.');
        } finally {
            loading.value.searchingProducts = false;
        }
    }

    async function scanOrLookup(query) {
        const value = query.trim();

        if (! value) return false;

        clearErrors();

        try {
            const response = await window.axios.get(`/pos/api/products/barcode/${encodeURIComponent(value)}`);
            addProduct(response.data.data);
            return true;
        } catch (error) {
            if (error.response?.status === 404) {
                await searchProducts(value);
                if (searchResults.value.length === 1) {
                    addProduct(searchResults.value[0]);
                    return true;
                }
                setErrors(error.response?.data?.errors ?? 'Barcode not found.');
                return false;
            }

            setErrors(error.response?.data?.errors ?? 'Unable to look up barcode.');
            return false;
        }
    }

    async function searchCustomers(query) {
        loading.value.searchingCustomers = true;
        try {
            const response = await window.axios.get('/pos/api/customers/search', {
                params: { q: query },
            });
            customerResults.value = response.data.data;
        } catch (error) {
            setErrors(error.response?.data?.errors ?? 'Unable to search customers.');
        } finally {
            loading.value.searchingCustomers = false;
        }
    }

    async function createCustomer(payload) {
        const response = await window.axios.post('/pos/api/customers', payload);
        customer.value = response.data.data;
        customerResults.value = [response.data.data];
        customerModalOpen.value = false;
        notify('Customer created', 'success');
    }

    async function openCustomerStatement(selectedCustomer) {
        if (! selectedCustomer?.id || selectedCustomer.is_walk_in) return;

        loading.value.loadingCustomerStatement = true;
        clearErrors();

        try {
            const response = await window.axios.get(`/pos/api/customers/${selectedCustomer.id}/statement`);
            customerStatement.value = response.data.data;
            customerStatementModalOpen.value = true;
            customerModalOpen.value = false;
        } catch (error) {
            setErrors(error.response?.data?.errors ?? error.response?.data?.message ?? 'Unable to load customer statement.');
        } finally {
            loading.value.loadingCustomerStatement = false;
        }
    }

    function openCreditPayment(sale) {
        if (! canReceiveCustomerPayments.value || Number(sale?.balance_due ?? 0) <= 0) return;
        if (! hasActiveShift.value) {
            notify('Open a cashier shift before receiving a payment.', 'error');
            return;
        }
        creditPaymentSale.value = sale;
        creditPaymentModalOpen.value = true;
    }

    async function receiveCreditPayment(payload) {
        if (! creditPaymentSale.value) return false;

        loading.value.receivingCreditPayment = true;
        clearErrors();

        try {
            const response = await window.axios.post(`/pos/api/sales/${creditPaymentSale.value.id}/payments`, payload);
            const updatedSale = response.data.data;

            if (activeSaleLookup.value?.id === updatedSale.id) {
                activeSaleLookup.value = updatedSale;
            }

            if (customerStatement.value?.customer?.id) {
                const statementResponse = await window.axios.get(`/pos/api/customers/${customerStatement.value.customer.id}/statement`);
                customerStatement.value = statementResponse.data.data;
            }

            creditPaymentModalOpen.value = false;
            creditPaymentSale.value = null;
            notify('Customer payment received', 'success');
            return true;
        } catch (error) {
            setErrors(error.response?.data?.errors ?? error.response?.data?.message ?? 'Unable to receive customer payment.');
            return false;
        } finally {
            loading.value.receivingCreditPayment = false;
        }
    }

    async function loadHeldSales() {
        loading.value.loadingHeldSales = true;
        try {
            const response = await window.axios.get('/pos/api/held-sales');
            heldSales.value = response.data.data;
        } catch (error) {
            setErrors(error.response?.data?.errors ?? 'Unable to load held sales.');
        } finally {
            loading.value.loadingHeldSales = false;
        }
    }

    async function resumeHeldSale(saleId) {
        clearErrors();

        try {
            const response = await window.axios.get(`/pos/api/sales/${saleId}/resume`);
            hydrateSaleResponse(response.data.data, true);
            heldSalesModalOpen.value = false;
            notify(`Held sale ${response.data.data.sale_number} resumed`, 'success');
        } catch (error) {
            setErrors(error.response?.data?.errors ?? error.response?.data?.message ?? 'Unable to resume held sale.');
        }
    }

    function hydrateSaleResponse(sale, resumed = false) {
        resumedSaleId.value = resumed ? sale.id : null;
        customer.value = sale.customer ?? bootstrap.value.walk_in_customer;
        items.value = sale.items.map((item) => ({
            productId: item.product_id,
            name: item.name,
            sku: item.sku,
            barcode: item.barcode,
            price: decimal(item.unit_price),
            taxRate: decimal(item.tax_rate),
            discountAmount: decimal(item.discount_amount),
            quantity: decimal(item.quantity),
            unit: item.unit,
            trackInventory: item.track_inventory,
            stock: null,
        }));
        payments.value = [];
    }

    async function holdSale(notes = '') {
        if (! hasNamedCustomer.value) {
            setErrors(['Select a saved customer before holding a sale.']);
            return;
        }

        loading.value.holdingSale = true;
        clearErrors();

        try {
            const payload = {
                customer_id: customer.value?.id ?? null,
                notes,
                items: serializeItems(),
            };
            const response = resumedSaleId.value
                ? await window.axios.post(`/pos/api/sales/${resumedSaleId.value}/hold`, payload)
                : await window.axios.post('/pos/api/sales/hold', {
                    ...payload,
                    client_transaction_uuid: uuid(),
                });
            resetSale();
            notify(`Held ${response.data.data.sale_number}`, 'success');
        } catch (error) {
            setErrors(error.response?.data?.errors ?? error.response?.data?.message ?? 'Unable to hold sale.');
        } finally {
            loading.value.holdingSale = false;
        }
    }

    async function completeSale(notes = '') {
        loading.value.completingSale = true;
        clearErrors();

        try {
            const payload = {
                customer_id: customer.value?.id ?? null,
                notes,
                items: serializeItems(),
                payments: payments.value.map((payment) => ({
                    payment_method: payment.payment_method,
                    amount: decimal(payment.amount),
                    amount_tendered: payment.amount_tendered ? decimal(payment.amount_tendered) : null,
                    reference: payment.reference || null,
                    notes: payment.notes || null,
                })),
            };

            const response = resumedSaleId.value
                ? await window.axios.post(`/pos/api/sales/${resumedSaleId.value}/complete`, payload)
                : await window.axios.post('/pos/api/sales', {
                    ...payload,
                    client_transaction_uuid: uuid(),
                });

            paymentModalOpen.value = false;
            resetSale();
            saleCompleteModal.value = response.data.data;
            notify(`Sale ${response.data.data.sale_number} complete`, 'success');
        } catch (error) {
            setErrors(error.response?.data?.errors ?? error.response?.data?.message ?? 'Unable to complete sale.');
        } finally {
            loading.value.completingSale = false;
        }
    }

    async function loadSalesHistory(page = 1) {
        loading.value.loadingSaleLookup = true;
        clearErrors();

        try {
            const response = await window.axios.get('/pos/api/sales', {
                params: salesHistoryParams(page),
            });
            saleSearchResults.value = response.data.data;
            salesHistoryMeta.value = response.data.meta;
            salesHistoryFilters.value = {
                ...salesHistoryFilters.value,
                ...response.data.filters,
                page: response.data.meta.current_page,
                per_page: response.data.meta.per_page,
            };
        } catch (error) {
            setErrors(error.response?.data?.errors ?? 'Unable to load sales history.');
        } finally {
            loading.value.loadingSaleLookup = false;
        }
    }

    async function searchSales(query, period = salesHistoryFilters.value.period) {
        salesHistoryFilters.value = {
            ...salesHistoryFilters.value,
            search: query,
            period,
            page: 1,
        };

        await loadSalesHistory(1);
    }

    async function prepareSalesHistory() {
        if (! canViewSalesHistory.value) return;

        activeSaleLookup.value = null;
        saleLookupModalOpen.value = true;

        if (! saleSearchResults.value.length) {
            resetSalesHistoryFilters();
        }

        await loadSalesHistory(1);
    }

    async function loadSale(saleId) {
        loading.value.loadingSaleLookup = true;
        clearErrors();

        try {
            const response = await window.axios.get(`/pos/api/sales/${saleId}`);
            activeSaleLookup.value = response.data.data;
        } catch (error) {
            setErrors(error.response?.data?.errors ?? 'Unable to load sale.');
        } finally {
            loading.value.loadingSaleLookup = false;
        }
    }

    async function processReturn(itemsToReturn, notes = '') {
        if (! activeSaleLookup.value) return;

        loading.value.processingReturn = true;
        clearErrors();

        try {
            const response = await window.axios.post(`/pos/api/sales/${activeSaleLookup.value.id}/returns`, {
                items: itemsToReturn,
                notes,
            });
            activeSaleLookup.value = response.data.data;
            await loadSalesHistory(salesHistoryFilters.value.page);
            notify('Sale return processed', 'success');
        } catch (error) {
            setErrors(error.response?.data?.errors ?? 'Unable to process return.');
        } finally {
            loading.value.processingReturn = false;
        }
    }

    function updateSalesHistoryFilter(key, value) {
        salesHistoryFilters.value = {
            ...salesHistoryFilters.value,
            [key]: value,
        };
    }

    async function applySalesHistoryFilters() {
        salesHistoryFilters.value = {
            ...salesHistoryFilters.value,
            page: 1,
        };

        await loadSalesHistory(1);
    }

    async function clearSalesHistoryFilters() {
        resetSalesHistoryFilters();
        await loadSalesHistory(1);
    }

    async function goToSalesHistoryPage(page) {
        if (page < 1 || page > salesHistoryMeta.value.last_page) return;
        await loadSalesHistory(page);
    }

    function printActiveSale(sale = activeSaleLookup.value ?? saleCompleteModal.value, format = 'thermal') {
        if (!sale) return;

        const isA4Invoice = format === 'a4';
        const escape = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
        }[character]));
        const money = (value) => `${sale.currency ?? sale.company?.currency ?? currency.value} ${Number(value ?? 0).toFixed(2)}`;
        const receiptProfile = sale.receipt ?? sale.company ?? {};
        const address = [receiptProfile.address, receiptProfile.city, receiptProfile.country].filter(Boolean).join(', ');
        const shopName = receiptProfile.shop_name || receiptProfile.receipt_shop_name || sale.branch?.name || receiptProfile.name || 'Moscow Traders Wholesale';
        const gstLabel = receiptProfile.gst_label || receiptProfile.receipt_gst_label || 'GST No.';
        const taxNumber = receiptProfile.tax_number || 'Not configured';
        const headerText = receiptProfile.header ?? receiptProfile.receipt_header;
        const footerText = receiptProfile.footer ?? receiptProfile.receipt_footer;
        const showAddress = receiptProfile.show_address ?? receiptProfile.receipt_show_address ?? true;
        const showPhone = receiptProfile.show_phone ?? receiptProfile.receipt_show_phone ?? true;
        const header = headerText ? `<div class="muted message">${escape(headerText).replace(/\n/g, '<br>')}</div>` : '';
        const footer = footerText ? `${escape(footerText).replace(/\n/g, '<br>')}<br>` : 'Thank you for shopping with us.<br>';
        const itemRows = (sale.items ?? []).map((item) => `
            <tr><td>${escape(item.name)}<br><small>${escape(item.sku ?? '')}</small></td><td class="num">${Number(item.quantity).toFixed(2)}</td><td class="num">${money(item.line_total)}</td></tr>
            ${Number(item.tax_rate ?? 0) > 0 ? `<tr class="tax"><td colspan="2">GST ${Number(item.tax_rate).toFixed(2)}%</td><td class="num">${money(item.tax_amount)}</td></tr>` : ''}
        `).join('');
        const payments = (sale.payments ?? []).map((payment) => {
            const method = escape(String(payment.payment_method ?? 'payment').replace('_', ' '));
            const isCash = payment.payment_method === 'cash';
            const hasTenderedAmount = payment.amount_tendered !== null && payment.amount_tendered !== undefined;

            if (isCash || hasTenderedAmount) {
                const tenderedAmount = hasTenderedAmount ? payment.amount_tendered : payment.amount;

                return `<div><span>${method} received</span><span>${money(tenderedAmount)}</span></div>
                    <div><span>${method} applied</span><span>${money(payment.amount)}</span></div>
                    <div><strong>Change given</strong><strong>${money(payment.change_due ?? 0)}</strong></div>`;
            }

            return `<div><span>${method} paid</span><span>${money(payment.amount)}</span></div>`;
        }).join('');
        const saleDate = escape(new Date(sale.completed_at ?? sale.created_at).toLocaleString());
        const customerTaxNumber = sale.customer_tax_number || sale.customer?.tax_number;
        const customer = sale.customer?.name
            ? `<div>Customer: ${escape(sale.customer.name)}</div>${customerTaxNumber ? `<div>Customer GST No.: ${escape(customerTaxNumber)}</div>` : ''}`
            : '';
        const cancelled = sale.status === 'cancelled';
        const outstanding = !cancelled && Number(sale.balance_due ?? 0) > 0;
        const paymentStatus = cancelled
            ? '<div class="payment-status cancelled">CANCELLED</div>'
            : outstanding
                ? '<div class="payment-status unpaid">NOT PAID</div>'
                : '<div class="payment-status paid">PAID</div>';
        const dueDetails = outstanding && sale.due_date
            ? `<div><strong>Terms:</strong> Due ${escape(sale.payment_terms_days ?? 7)}</div><div><strong>Due date:</strong> ${escape(sale.due_date)}</div>`
            : '';
        const balanceDetails = outstanding ? `<div class="balance-due"><span>Balance due</span><strong>${money(sale.balance_due)}</strong></div>` : '';
        const cancellationDetails = cancelled
            ? `<div><strong>Cancellation reason:</strong> ${escape(sale.cancellation_reason || 'Cancelled')}</div>${sale.cancellation_notes ? `<div><strong>Cancellation notes:</strong> ${escape(sale.cancellation_notes)}</div>` : ''}${sale.cancelled_at ? `<div><strong>Cancelled:</strong> ${escape(new Date(sale.cancelled_at).toLocaleString())}</div>` : ''}`
            : '';
        const paymentDetails = cancelled
            ? '<div>No payment required — cancelled</div>'
            : `${payments || '<div>No payment received</div>'}${balanceDetails}`;
        const documentLabel = cancelled ? 'Cancelled Sale' : (isA4Invoice ? 'Tax Invoice' : 'Receipt');
        const receipt = window.open('', '_blank', isA4Invoice ? 'width=900,height=900' : 'width=420,height=720');

        if (!receipt) {
            notify('Allow pop-ups to print receipts from this browser.', 'error');
            return;
        }

        const thermalReceipt = `<header class="center"><div class="shop">${escape(shopName)}</div><div class="muted">${escape(gstLabel)}: ${escape(taxNumber)}</div>${showAddress && address ? `<div class="muted">${escape(address)}</div>` : ''}${showPhone && receiptProfile.phone ? `<div class="muted">${escape(receiptProfile.phone)}</div>` : ''}${header}</header>
            ${paymentStatus}<div class="rule"></div><div>Receipt: ${escape(sale.sale_number)}</div><div>Date: ${saleDate}</div>${customer}${dueDetails}${cancellationDetails}<div class="rule"></div>
            <table><thead><tr><td><strong>Item</strong></td><td class="num"><strong>Qty</strong></td><td class="num"><strong>Amount</strong></td></tr></thead><tbody>${itemRows}</tbody></table>
            <div class="rule"></div><div class="totals"><div><span>Subtotal</span><span>${money(sale.subtotal)}</span></div><div><span>GST</span><span>${money(sale.tax_total)}</span></div>${Number(sale.discount_total ?? 0) > 0 ? `<div><span>Discount</span><span>-${money(sale.discount_total)}</span></div>` : ''}<div class="grand"><span>Total</span><span>${money(sale.grand_total)}</span></div></div>
            <div class="rule"></div><div class="payments">${paymentDetails}</div><div class="footer">${footer}</div>`;
        const a4Invoice = `<main class="invoice"><header class="invoice-head"><div><div class="shop">${escape(shopName)}</div><div>${escape(gstLabel)}: ${escape(taxNumber)}</div>${showAddress && address ? `<div>${escape(address)}</div>` : ''}${showPhone && receiptProfile.phone ? `<div>${escape(receiptProfile.phone)}</div>` : ''}${header}</div><div class="invoice-title"><h1>${cancelled ? 'CANCELLED SALE' : 'TAX INVOICE'}</h1><strong>${escape(sale.sale_number)}</strong><div>${saleDate}</div>${paymentStatus}</div></header><section class="invoice-meta">${customer || '<div>Customer: Walk-in Customer</div>'}${dueDetails}${cancellationDetails}</section><table><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead><tbody>${itemRows}</tbody></table><section class="invoice-bottom"><div class="payments"><h2>Payments</h2>${paymentDetails}</div><div class="totals"><div><span>Subtotal</span><span>${money(sale.subtotal)}</span></div><div><span>GST</span><span>${money(sale.tax_total)}</span></div>${Number(sale.discount_total ?? 0) > 0 ? `<div><span>Discount</span><span>-${money(sale.discount_total)}</span></div>` : ''}<div class="grand"><span>Total</span><span>${money(sale.grand_total)}</span></div></div></section><footer class="footer">${footer}</footer></main>`;
        const styles = isA4Invoice
            ? `@page{size:A4;margin:14mm}*{box-sizing:border-box}body{margin:0;font:12px/1.45 Arial,sans-serif;color:#14212b}.invoice{max-width:182mm;margin:auto}.invoice-head{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #14212b;padding-bottom:14px}.shop{font-size:22px;font-weight:700}.invoice-title{text-align:right}.invoice-title h1{margin:0 0 8px;font-size:24px;letter-spacing:.08em}.invoice-meta{padding:16px 0}table{width:100%;border-collapse:collapse}th{background:#edf2f5;text-align:left}th,td{border:1px solid #b9c5cc;padding:8px;vertical-align:top}.num{text-align:right;white-space:nowrap}.tax td{padding-top:3px;font-size:11px;color:#52616b}.invoice-bottom{display:grid;grid-template-columns:1fr 260px;gap:30px;margin-top:22px}.totals div,.payments div{display:flex;justify-content:space-between;gap:20px;padding:4px 0}.totals{border-top:2px solid #14212b;padding-top:6px}.grand{font-size:16px;font-weight:700;border-top:1px solid #14212b;margin-top:5px;padding-top:7px!important}.payments h2{margin:0 0 7px;font-size:13px}.footer{margin-top:38px;border-top:1px solid #b9c5cc;padding-top:12px;text-align:center;color:#52616b}.message{margin-top:6px}.print-controls{position:fixed;top:12px;right:12px;z-index:10}.print-controls button{border:0;border-radius:8px;background:#14212b;color:#fff;cursor:pointer;font:600 14px Arial,sans-serif;padding:10px 14px}@media print{body{width:auto}.print-controls{display:none}}`
            : `@page { size: 80mm auto; margin: 3mm; } *{box-sizing:border-box} body{width:74mm;margin:0;font:12px/1.35 Arial,sans-serif;color:#000}.center{text-align:center}.shop{font-size:18px;font-weight:700}.muted,small{font-size:10px;color:#333}.rule{border-top:1px dashed #000;margin:9px 0}table{width:100%;border-collapse:collapse}td{padding:3px 0;vertical-align:top}.num{text-align:right;white-space:nowrap}.tax td{padding-top:0;font-size:10px}.totals div,.payments div{display:flex;justify-content:space-between;padding:2px 0}.grand{font-size:15px;font-weight:700;margin-top:3px}.footer{margin-top:14px;font-size:10px;text-align:center}.print-controls{position:fixed;top:12px;right:12px;z-index:10}.print-controls button{border:0;border-radius:8px;background:#14212b;color:#fff;cursor:pointer;font:600 14px Arial,sans-serif;padding:10px 14px}@media print{body{width:74mm}.print-controls{display:none}}`;

        const statusStyles = `.invoice-meta div{margin:2px 0}.grand,.balance-due{font-weight:700}.payment-status{display:inline-block;margin-top:10px;border:3px solid currentColor;padding:5px 10px;font-size:17px;font-weight:900;letter-spacing:.12em}.unpaid,.cancelled{color:#b42318}.paid{color:#16803d}`;
        receipt.document.write(`<!doctype html><html><head><title>${documentLabel} ${escape(sale.sale_number)}</title><style>${styles}${statusStyles}</style></head><body><div class="print-controls"><button type="button" onclick="window.print()">Print</button></div>${isA4Invoice ? a4Invoice : thermalReceipt}</body></html>`);
        receipt.document.close();
    }

    function addPayment(payment) {
        payments.value.push({
            id: uuid(),
            payment_method: payment.payment_method,
            amount: decimal(payment.amount),
            amount_tendered: payment.amount_tendered ? decimal(payment.amount_tendered) : null,
            reference: payment.reference ?? '',
            notes: payment.notes ?? '',
        });
    }

    function removePayment(paymentId) {
        payments.value = payments.value.filter((payment) => payment.id !== paymentId);
    }

    async function cancelHeldSale(saleId, reason, notes = '') {
        if (! canCancelHeldSales.value) return false;

        clearErrors();

        try {
            await window.axios.post(`/pos/api/sales/${saleId}/cancel-held`, {
                reason,
                notes,
            });
            heldSales.value = heldSales.value.filter((sale) => sale.id !== saleId);
            if (resumedSaleId.value === saleId) {
                resetSale();
            }
            notify('Held sale cancelled', 'success');
            return true;
        } catch (error) {
            setErrors(error.response?.data?.errors ?? error.response?.data?.message ?? 'Unable to cancel held sale.');
            return false;
        }
    }

    return {
        bootstrap,
        items,
        customer,
        searchResults,
        customerResults,
        heldSales,
        saleSearchResults,
        salesHistoryMeta,
        salesHistoryFilters,
        resumedSaleId,
        payments,
        loading,
        errors,
        notifications,
        paymentModalOpen,
        customerModalOpen,
        heldSalesModalOpen,
        saleLookupModalOpen,
        shortcutModalOpen,
        customerStatementModalOpen,
        creditPaymentModalOpen,
        saleCompleteModal,
        selectedSearchIndex,
        activeSaleLookup,
        customerStatement,
        creditPaymentSale,
        searchInputValue,
        currency,
        permissions,
        canOverridePrice,
        canDiscount,
        canCreateCustomer,
        canUseCredit,
        canViewSalesHistory,
        canReturnSales,
        canResumeHeldSales,
        canCancelHeldSales,
        canViewCancelledSales,
        canReceiveCustomerPayments,
        isResumedSale,
        hasNamedCustomer,
        canHoldSale,
        totals,
        paymentTotals,
        dirty,
        hasSalesHistoryResults,
        hasActiveShift,
        hydrate,
        setActiveShift,
        t,
        formatMoney: (amount) => formatMoney(amount, currency.value),
        formatDateTime,
        formatStatus,
        notify,
        setErrors,
        clearErrors,
        setSearchResults,
        addProduct,
        incrementItem,
        decrementItem,
        updateItemQuantity,
        updateItemPrice,
        updateItemDiscount,
        removeItem,
        setCustomer,
        resetSale,
        resetSalesHistoryFilters,
        searchProducts,
        scanOrLookup,
        searchCustomers,
        createCustomer,
        openCustomerStatement,
        openCreditPayment,
        receiveCreditPayment,
        loadHeldSales,
        resumeHeldSale,
        holdSale,
        completeSale,
        loadSalesHistory,
        searchSales,
        prepareSalesHistory,
        loadSale,
        processReturn,
        updateSalesHistoryFilter,
        applySalesHistoryFilters,
        clearSalesHistoryFilters,
        goToSalesHistoryPage,
        printActiveSale,
        addPayment,
        removePayment,
        cancelHeldSale,
    };
});
