/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth_MagePos terminal - order search + refund flow (spec §9).
 *
 * Registers the reactive Alpine.store('posOrders') backing
 * modal-refund.phtml: order search by increment id / email / receipt number
 * (pos/order/search; empty query = last 25 POS orders for the register),
 * refundable-qty steppers, refund payment split, restock toggle, reason and
 * confirm (pos/order/refund). The server enforces the can_refund permission
 * and validates that the refund payment split equals the credit memo total.
 *
 * Refund totals are SERVER-COMPUTED (contract §11): on every qty change the
 * store debounce-calls pos/order/preview, which builds an unsaved credit memo
 * and returns the exact discount/tax-aware refund_total + per-line amounts.
 * The first (untouched) payment split auto-fills to that previewed total and
 * Confirm validates against it, so refunds of discounted orders no longer fail
 * on a client-side price × qty estimate.
 */
(function () {
    'use strict';

    var EPSILON = 0.005;
    var PREVIEW_DEBOUNCE_MS = 250;

    function posStore() {
        try {
            return window.Alpine && typeof window.Alpine.store === 'function'
                ? window.Alpine.store('pos')
                : null;
        } catch (e) {
            return null;
        }
    }

    function t(text) {
        return window.Pos && typeof window.Pos.t === 'function' ? window.Pos.t(text) : text;
    }

    function notify(message, type) {
        var store = posStore();
        if (store && typeof store.notify === 'function') {
            store.notify(message, type || 'info');
        }
    }

    function api() {
        if (!window.PosApi) {
            throw { message: t('POS API is not available.') };
        }
        return window.PosApi;
    }

    function round2(value) {
        return Math.round((Number(value) || 0) * 100) / 100;
    }

    document.addEventListener('alpine:init', function () {
        window.Alpine.store('posOrders', {
            open: false,
            query: '',
            searching: false,
            searched: false,
            results: [],
            selected: null,
            qty: {},
            restock: true,
            reason: '',
            payments: [],
            paymentsTouched: false,
            submitting: false,
            result: null,
            preview: null,
            previewing: false,
            previewSeq: 0,
            _previewTimer: null,

            init: function () {
                var self = this;
                window.addEventListener('pos:open-refund', function () {
                    self.openModal();
                });
                window.addEventListener('pos:open-refund-modal', function () {
                    self.openModal();
                });
            },

            money: function (value) {
                var store = posStore();
                var symbol = (store && store.config && store.config.currency_symbol) || '';
                var amount = Number(value || 0);
                var sign = amount < 0 ? '-' : '';
                return sign + symbol + Math.abs(amount).toFixed(2);
            },

            canRefund: function () {
                var store = posStore();
                return !!(store && store.user && store.user.permissions
                    && store.user.permissions.can_refund);
            },

            methods: function () {
                var store = posStore();
                var rows = store && (store.payment_methods || store.paymentMethods);
                return Array.isArray(rows) ? rows : [];
            },

            methodByCode: function (code) {
                return this.methods().find(function (method) {
                    return method.code === code;
                }) || null;
            },

            requiresReference: function (code) {
                var method = this.methodByCode(code);
                return !!(method && method.requires_reference);
            },

            defaultMethodCode: function () {
                var rows = this.methods();
                var cash = rows.find(function (method) {
                    return method.type === 'cash';
                });
                return (cash || rows[0] || { code: 'cash' }).code;
            },

            openModal: function () {
                this.open = true;
                this.reset();
                this.search();
            },

            close: function () {
                if (!this.submitting) {
                    this.open = false;
                }
            },

            reset: function () {
                this.cancelPreview();
                this.selected = null;
                this.result = null;
                this.qty = {};
                this.restock = true;
                this.reason = '';
                this.payments = [];
                this.paymentsTouched = false;
                this.preview = null;
                this.previewing = false;
            },

            backToList: function () {
                this.cancelPreview();
                this.selected = null;
                this.result = null;
                this.preview = null;
                this.previewing = false;
                this.paymentsTouched = false;
            },

            search: function () {
                var self = this;
                if (this.searching) {
                    return Promise.resolve(this.results);
                }
                this.searching = true;
                return Promise.resolve().then(function () {
                    return api().get('order/search', { q: self.query.trim() });
                }).then(function (data) {
                    // Contract: order/search returns OBJECT {orders:[...]}
                    // (a bare array is tolerated defensively).
                    if (data && Array.isArray(data.orders)) {
                        self.results = data.orders;
                    } else if (Array.isArray(data)) {
                        self.results = data;
                    } else {
                        self.results = [];
                    }
                    self.searched = true;
                    return self.results;
                }).catch(function (error) {
                    notify((error && error.message) || t('Unable to search orders.'), 'error');
                    return [];
                }).finally(function () {
                    self.searching = false;
                });
            },

            select: function (order) {
                if (!order || !order.can_refund) {
                    notify(t('This order cannot be refunded.'), 'error');
                    return;
                }
                this.cancelPreview();
                this.selected = order;
                this.result = null;
                this.reason = '';
                this.restock = true;
                this.preview = null;
                this.previewing = false;
                this.paymentsTouched = false;
                var qty = {};
                (order.items || []).forEach(function (item) {
                    qty[item.item_id] = 0;
                });
                this.qty = qty;
                this.payments = [{ method_code: this.defaultMethodCode(), amount: 0, reference: '' }];
            },

            itemQty: function (item) {
                return Number(this.qty[item.item_id] || 0);
            },

            /**
             * Refundable quantity for a line per the verified order shape:
             * qty_available when the backend provides it, otherwise derived
             * from qty_ordered − qty_refunded.
             */
            refundableQty: function (item) {
                if (!item) {
                    return 0;
                }
                if (item.qty_available !== undefined && item.qty_available !== null) {
                    return Math.max(0, Number(item.qty_available) || 0);
                }
                var ordered = Number(item.qty_ordered) || 0;
                var refunded = Number(item.qty_refunded) || 0;
                return Math.max(0, ordered - refunded);
            },

            setQty: function (item, value) {
                var qty = Number(value);
                if (isNaN(qty) || qty < 0) {
                    qty = 0;
                }
                this.qty[item.item_id] = Math.min(qty, this.refundableQty(item));
                this.schedulePreview();
            },

            inc: function (item) {
                this.setQty(item, this.itemQty(item) + 1);
            },

            dec: function (item) {
                this.setQty(item, this.itemQty(item) - 1);
            },

            selectAll: function () {
                var self = this;
                if (!this.selected) {
                    return;
                }
                (this.selected.items || []).forEach(function (item) {
                    self.qty[item.item_id] = self.refundableQty(item);
                });
                this.schedulePreview();
            },

            clearAll: function () {
                var self = this;
                if (!this.selected) {
                    return;
                }
                (this.selected.items || []).forEach(function (item) {
                    self.qty[item.item_id] = 0;
                });
                this.schedulePreview();
            },

            /**
             * Whether any quantity is selected for refund.
             */
            hasSelection: function () {
                var self = this;
                if (!this.selected) {
                    return false;
                }
                return (this.selected.items || []).some(function (item) {
                    return self.itemQty(item) > 0;
                });
            },

            /**
             * Server-previewed refundable amount for a single line (the exact,
             * discount/tax-aware payback for the currently selected qty). Falls
             * back to the order/search per-line refundable_unit estimate until
             * the preview returns.
             */
            lineAmount: function (item) {
                if (!item) {
                    return 0;
                }
                if (this.preview && this.preview.items && this.preview.items[item.item_id]) {
                    return round2(this.preview.items[item.item_id].amount);
                }
                var unit = item.refundable_unit != null
                    ? Number(item.refundable_unit)
                    : Number(item.price || 0);
                return round2(this.itemQty(item) * unit);
            },

            /**
             * Refundable amount per unit for the full remaining qty of a line,
             * surfaced by order/search (discount/tax aware). Display only.
             */
            refundableUnit: function (item) {
                if (item && item.refundable_unit != null) {
                    return round2(item.refundable_unit);
                }
                return round2(item ? Number(item.price || 0) : 0);
            },

            /**
             * The authoritative refund total: the server preview when present,
             * otherwise a best-effort estimate from the per-line refundable
             * unit amounts (never a raw price × qty sum on its own).
             */
            refundTotal: function () {
                if (this.preview && this.preview.refund_total != null) {
                    return round2(this.preview.refund_total);
                }
                var self = this;
                if (!this.selected) {
                    return 0;
                }
                var total = 0;
                (this.selected.items || []).forEach(function (item) {
                    total += self.lineAmount(item);
                });
                return round2(total);
            },

            previewedTotal: function () {
                return this.refundTotal();
            },

            paymentsTotal: function () {
                return round2(this.payments.reduce(function (sum, row) {
                    return sum + (Number(row.amount) || 0);
                }, 0));
            },

            paymentsMatch: function () {
                return Math.abs(this.paymentsTotal() - this.refundTotal()) <= EPSILON;
            },

            /**
             * Mark the split as edited so auto-fill stops overwriting it.
             */
            touchPayments: function () {
                this.paymentsTouched = true;
            },

            /**
             * Auto-fill the (first) split row with the previewed total whenever
             * the cashier has not manually touched the split. Multi-row splits
             * are left to the cashier once touched.
             */
            autofillPayments: function () {
                if (this.paymentsTouched) {
                    return;
                }
                if (!this.payments.length) {
                    this.payments = [{ method_code: this.defaultMethodCode(), amount: 0, reference: '' }];
                }
                if (this.payments.length === 1) {
                    this.payments[0].amount = this.refundTotal();
                }
            },

            addPayment: function () {
                this.paymentsTouched = true;
                this.payments.push({
                    method_code: this.defaultMethodCode(),
                    amount: Math.max(0, round2(this.refundTotal() - this.paymentsTotal())),
                    reference: ''
                });
            },

            removePayment: function (index) {
                this.payments.splice(index, 1);
                if (this.payments.length <= 1) {
                    this.paymentsTouched = false;
                    this.autofillPayments();
                }
            },

            /**
             * Debounce a server refund preview after qty changes.
             */
            schedulePreview: function () {
                var self = this;
                if (this._previewTimer) {
                    clearTimeout(this._previewTimer);
                }
                // Clear the stale previewed total immediately so the UI never
                // shows a mismatched amount while the new preview is in flight.
                this.preview = null;
                if (!this.hasSelection()) {
                    this.previewing = false;
                    this.autofillPayments();
                    return;
                }
                this.previewing = true;
                this._previewTimer = setTimeout(function () {
                    self.runPreview();
                }, PREVIEW_DEBOUNCE_MS);
            },

            cancelPreview: function () {
                if (this._previewTimer) {
                    clearTimeout(this._previewTimer);
                    this._previewTimer = null;
                }
            },

            /**
             * Fetch the server-computed refund preview (discount/tax aware) for
             * the current selection. A monotonically increasing sequence guards
             * against out-of-order responses from rapid qty changes.
             */
            runPreview: function () {
                var self = this;
                if (!this.selected) {
                    return Promise.resolve(null);
                }
                var items = {};
                (this.selected.items || []).forEach(function (item) {
                    var qty = self.itemQty(item);
                    if (qty > 0) {
                        items[item.item_id] = qty;
                    }
                });
                var seq = ++this.previewSeq;
                this.previewing = true;
                return Promise.resolve().then(function () {
                    return api().post('order/preview', {
                        order_id: self.selected.order_id,
                        items: items
                    });
                }).then(function (data) {
                    if (seq !== self.previewSeq) {
                        return null; // a newer request superseded this one
                    }
                    self.preview = data || null;
                    self.autofillPayments();
                    return data;
                }).catch(function (error) {
                    if (seq === self.previewSeq) {
                        self.preview = null;
                        notify((error && error.message) || t('Unable to preview the refund.'), 'error');
                    }
                    return null;
                }).finally(function () {
                    if (seq === self.previewSeq) {
                        self.previewing = false;
                    }
                });
            },

            canSubmit: function () {
                return !!this.selected
                    && !this.submitting
                    && !this.previewing
                    && !!this.preview
                    && this.refundTotal() > 0
                    && this.paymentsMatch();
            },

            confirm: function () {
                var self = this;
                if (!this.canRefund()) {
                    notify(t('You do not have permission to refund orders.'), 'error');
                    return Promise.resolve(null);
                }
                if (this.previewing || !this.preview) {
                    notify(t('Calculating the refund total - please wait.'), 'error');
                    return Promise.resolve(null);
                }
                if (!this.hasSelection() || this.refundTotal() <= 0) {
                    notify(t('Select refund quantities first.'), 'error');
                    return Promise.resolve(null);
                }
                if (!this.paymentsMatch()) {
                    // Currency-formatted, not raw floats (contract §11).
                    notify(
                        this.money(this.paymentsTotal()) + ' '
                        + t('must equal') + ' ' + this.money(this.refundTotal()),
                        'error'
                    );
                    return Promise.resolve(null);
                }
                if (!this.canSubmit()) {
                    notify(t('Select refund quantities and balance the refund payments first.'), 'error');
                    return Promise.resolve(null);
                }
                var items = {};
                (this.selected.items || []).forEach(function (item) {
                    var qty = self.itemQty(item);
                    if (qty > 0) {
                        items[item.item_id] = qty;
                    }
                });
                var payments = this.payments.filter(function (row) {
                    return (Number(row.amount) || 0) > 0;
                }).map(function (row) {
                    var payment = {
                        method_code: row.method_code,
                        amount: round2(row.amount)
                    };
                    if (row.reference && String(row.reference).trim() !== '') {
                        payment.reference = String(row.reference).trim();
                    }
                    return payment;
                });

                this.submitting = true;
                return Promise.resolve().then(function () {
                    return api().post('order/refund', {
                        order_id: self.selected.order_id,
                        items: items,
                        payments: payments,
                        restock: self.restock,
                        reason: self.reason.trim()
                    });
                }).then(function (data) {
                    self.result = data;
                    notify(t('Refund completed.'), 'success');
                    window.dispatchEvent(new CustomEvent('pos:order-refunded', { detail: data }));
                    self.search();
                    return data;
                }).catch(function (error) {
                    notify((error && error.message) || t('Unable to process the refund.'), 'error');
                    return null;
                }).finally(function () {
                    self.submitting = false;
                });
            },

            formatDate: function (value) {
                if (!value) {
                    return '';
                }
                var date = new Date(String(value).replace(' ', 'T') + 'Z');
                if (isNaN(date.getTime())) {
                    return String(value);
                }
                return date.toLocaleString();
            }
        });
    });
})();
