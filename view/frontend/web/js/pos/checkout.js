/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth_MagePos terminal - checkout modal (`posCheckout()`).
 *
 * Split payments across multiple methods, amount keypad, cash quick tender
 * (exact / next-5 / next-10 / denominations), live remaining + change due,
 * reference inputs for methods with requires_reference, place order via
 * POST pos/checkout/place, then the in-modal receipt view (print / new sale,
 * QR + link for online payment rows). While offline (spec §2/§9) the sale is
 * queued instead via PosOffline.queueOrder() with a client UUID and synced
 * to pos/sync/push by offline.js when the connection returns.
 *
 * The splitting math here mirrors the pinned server rules (§5
 * CheckoutService): Σ payments must cover the grand total, and any overpay
 * (= change due) is only allowed against cash rows. All money math is
 * authoritative server-side; the client only previews it.
 *
 * Cross-component contract (window CustomEvents):
 *   listens    `pos:checkout-open`  {cart}
 *   dispatches `pos:order-placed`   {order, result, cart} (order === result;
 *              register panel refreshes expected cash, receipt modal caches it)
 *   dispatches `pos:new-sale`
 */
(function () {
    'use strict';

    var EPS = 0.005;

    function ui() { return window.PosUi || {}; }
    function money(v) { return ui().money ? ui().money(v) : String(v); }
    function notify(m, type) { if (ui().notify) { ui().notify(m, type); } }
    function t(k, f) { return ui().t ? ui().t(k, f) : (f || k); }
    function emit(n, d) { window.dispatchEvent(new CustomEvent(n, { detail: d || {} })); }
    function round2(n) { return Math.round((Number(n) || 0) * 100) / 100; }
    function errMessage(e, fallback) {
        return (e && e.message) ? e.message : (fallback || t('pos_error', 'Something went wrong.'));
    }

    var ICON_ALIAS = {
        money: 'cash', banknote: 'cash', coins: 'cash', bank: 'cash',
        credit: 'card', 'credit-card': 'card', creditcard: 'card', debit: 'card', 'debit-card': 'card',
        offline: 'card', terminal: 'card', wallet: 'card',
        gift: 'ticket', 'gift-card': 'ticket', giftcard: 'ticket', voucher: 'ticket', coupon: 'ticket',
        online: 'link', url: 'link', 'qr-code': 'qr', qrcode: 'qr'
    };
    var TYPE_ICON = { cash: 'cash', card: 'card', offline: 'card', online: 'link', link: 'link', qr: 'qr' };

    function iconKeyword(icon) {
        var key = String(icon || '').trim().toLowerCase();
        return /^[a-z][a-z0-9_-]*$/.test(key) ? key : '';
    }

    function methodIconId(method) {
        var key = iconKeyword(method && method.icon);
        if (key) {
            key = ICON_ALIAS[key] || key;
            return document.getElementById('pi-' + key) ? key : 'sparkle';
        }
        return TYPE_ICON[method && method.type] || 'sparkle';
    }

    function methodIconText(method) {
        var icon = String((method && method.icon) || '').trim();
        return icon && !iconKeyword(icon) ? icon : '';
    }

    window.posCheckout = function () {
        return {
            open: false,
            view: 'pay', // pay | done
            cart: null,
            rows: [],          // [{method_code, title, type, icon, requires_reference, amount:string, reference:string}]
            activeRow: -1,
            placing: false,
            result: null,
            emailReceipt: false,
            receiptEmail: '',
            denominations: [1, 2, 5, 10, 20, 50, 100],

            init: function () {
                var self = this;
                window.addEventListener('pos:checkout-open', function (e) {
                    self.openModal((e.detail || {}).cart);
                });
                window.addEventListener('keydown', function (e) {
                    if (self.open && e.key === 'Escape' && !self.placing) {
                        self.close();
                    }
                });
            },

            money: function (v) { return money(v); },
            iconId: function (m) { return '#pi-' + methodIconId(m); },
            iconText: function (m) { return methodIconText(m); },
            t: function (k, f) { return t(k, f); },

            /* ---------------------------------------------------- methods */

            methods: function () {
                var s = (window.Alpine && window.Alpine.store) ? (window.Alpine.store('pos') || {}) : {};
                var cfg = ui().bootConfig ? ui().bootConfig() : {};
                return s.paymentMethods
                    || s.payment_methods
                    || (s.state && s.state.payment_methods)
                    || cfg.payment_methods
                    || [];
            },

            methodByCode: function (code) {
                return this.methods().find(function (m) { return m.code === code; }) || null;
            },

            /* ------------------------------------------------------ state */

            openModal: function (cart) {
                if (!cart || !(cart.items || []).length) {
                    return;
                }
                this.cart = cart;
                this.rows = [];
                this.activeRow = -1;
                this.result = null;
                this.view = 'pay';
                this.placing = false;
                this.emailReceipt = false;
                this.receiptEmail = (cart.customer && cart.customer.email) || '';
                this.open = true;

                // Convenience: pre-add the first cash method tendered exact.
                var cash = this.methods().find(function (m) { return m.type === 'cash'; });
                if (cash) {
                    this.addRow(cash);
                }
            },

            close: function () {
                this.open = false;
                this.cart = null;
                this.rows = [];
                this.result = null;
            },

            total: function () {
                return (this.cart && this.cart.totals) ? round2(this.cart.totals.grand_total) : 0;
            },

            paid: function () {
                return round2(this.rows.reduce(function (sum, row) {
                    return sum + (Number(row.amount) || 0);
                }, 0));
            },

            cashPaid: function () {
                return round2(this.rows.reduce(function (sum, row) {
                    return sum + (row.type === 'cash' ? (Number(row.amount) || 0) : 0);
                }, 0));
            },

            remaining: function () {
                return round2(Math.max(0, this.total() - this.paid()));
            },

            changeDue: function () {
                var over = round2(this.paid() - this.total());
                return over > 0 ? over : 0;
            },

            /**
             * Change due for DISPLAY (item 38): change is a cash-tender
             * concept, so it only shows while cash rows actually cover the
             * overpay - a purely-online overpay is a validation error
             * (blockReason), never "change due".
             *
             * Item 49: when the non-cash rows already cover the whole total,
             * the entire cash tender would come straight back - the server
             * drops those redundant cash rows and returns change_due 0, so
             * the preview never advertises change for that state either.
             */
            displayChange: function () {
                var change = this.changeDue();
                if (change <= 0 || change > this.cashPaid() + EPS) {
                    return 0;
                }
                var nonCash = round2(this.paid() - this.cashPaid());
                if (nonCash > 0 && nonCash + EPS >= this.total()) {
                    return 0;
                }
                return change;
            },

            /**
             * Remaining as if row i were empty - used by quick-tender so
             * "Exact" on a row always lands the grand total precisely.
             */
            remainingExcluding: function (i) {
                var paidOther = this.rows.reduce(function (sum, row, idx) {
                    return sum + (idx === i ? 0 : (Number(row.amount) || 0));
                }, 0);
                return round2(Math.max(0, this.total() - paidOther));
            },

            canPlace: function () {
                if (this.placing || !this.rows.length) {
                    return false;
                }
                var paid = this.paid();
                var total = this.total();
                if (paid + EPS < total) {
                    return false;
                }
                var over = paid - total;
                if (over > EPS && over > this.cashPaid() + EPS) {
                    return false; // overpay only allowed against cash (change)
                }
                var refsOk = this.rows.every(function (row) {
                    return !row.requires_reference
                        || (Number(row.amount) || 0) <= 0
                        || (row.reference || '').trim() !== '';
                });
                return refsOk;
            },

            blockReason: function () {
                if (!this.rows.length) {
                    return t('add_payment', 'Add a payment method');
                }
                if (this.paid() + EPS < this.total()) {
                    return t('remaining_label', 'Remaining') + ' ' + money(this.remaining());
                }
                var over = this.paid() - this.total();
                if (over > EPS && over > this.cashPaid() + EPS) {
                    return t('overpay_cash_only', 'Overpayment is only allowed on cash');
                }
                var missingRef = this.rows.find(function (row) {
                    return row.requires_reference
                        && (Number(row.amount) || 0) > 0
                        && (row.reference || '').trim() === '';
                });
                if (missingRef) {
                    return t('reference_required', 'Reference required for') + ' ' + missingRef.title;
                }
                return '';
            },

            /* ------------------------------------------------------- rows */

            addRow: function (method) {
                // Auto-tender the open remainder on the new row (computed
                // BEFORE pushing so we never mutate the raw object after it
                // enters Alpine's reactive array).
                var rem = this.remaining();
                this.rows.push({
                    method_code: method.code,
                    title: method.title,
                    type: method.type,
                    icon: method.icon || '',
                    requires_reference: !!method.requires_reference,
                    instructions: method.instructions || '',
                    amount: rem > 0 ? rem.toFixed(2) : '',
                    reference: ''
                });
                this.activeRow = this.rows.length - 1;
            },

            removeRow: function (i) {
                this.rows.splice(i, 1);
                if (this.activeRow >= this.rows.length) {
                    this.activeRow = this.rows.length - 1;
                }
            },

            selectRow: function (i) {
                this.activeRow = i;
            },

            activeRowData: function () {
                return (this.activeRow >= 0 && this.activeRow < this.rows.length)
                    ? this.rows[this.activeRow]
                    : null;
            },

            /* ----------------------------------------------------- keypad */

            padKey: function (event) {
                var key;
                if (!this.open || this.view !== 'pay' || !window.posPadKey) {
                    return;
                }
                key = window.posPadKey(event);
                if (key === null || key === 'enter') {
                    return;
                }
                event.preventDefault();
                this.keyTap(key);
            },

            keyTap: function (key) {
                var row = this.activeRowData();
                if (!row) {
                    return;
                }
                var value = String(row.amount == null ? '' : row.amount);
                if (key === 'C') {
                    value = '';
                } else if (key === 'back') {
                    value = value.slice(0, -1);
                } else if (key === '.') {
                    if (value.indexOf('.') < 0) {
                        value = value === '' ? '0.' : value + '.';
                    }
                } else if (/^\d$/.test(key)) {
                    if (value === '0') {
                        value = key;
                    } else if (!/\.\d{2}$/.test(value)) {
                        value = value + key;
                    }
                }
                row.amount = value;
            },

            /* ----------------------------------------------- quick tender */

            tenderExact: function () {
                var row = this.activeRowData();
                if (!row) {
                    return;
                }
                row.amount = this.remainingExcluding(this.activeRow).toFixed(2);
            },

            tenderNext: function (step) {
                var row = this.activeRowData();
                if (!row) {
                    return;
                }
                var rem = this.remainingExcluding(this.activeRow);
                var next = Math.ceil((rem + EPS) / step) * step;
                if (next <= 0) {
                    next = step;
                }
                row.amount = next.toFixed(2);
            },

            tenderDenomination: function (denomination) {
                var row = this.activeRowData();
                if (!row) {
                    return;
                }
                row.amount = (round2(Number(row.amount) || 0) + denomination).toFixed(2);
            },

            /* ------------------------------------------------ place order */

            place: function () {
                if (!this.canPlace()) {
                    var reason = this.blockReason();
                    if (reason) {
                        notify(reason, 'error');
                    }
                    return;
                }
                var self = this;
                var payments = this.rows
                    .map(function (row) {
                        var payment = {
                            method_code: row.method_code,
                            amount: round2(Number(row.amount) || 0)
                        };
                        if ((row.reference || '').trim() !== '') {
                            payment.reference = row.reference.trim();
                        }
                        return payment;
                    })
                    .filter(function (payment) { return payment.amount > 0; });

                var body = {
                    quote_id: this.cart.quote_id,
                    payments: payments
                };
                if (this.cart.note) {
                    body.note = this.cart.note;
                }
                if (this.emailReceipt && (this.receiptEmail || '').trim() !== '') {
                    body.email_receipt = true;
                    body.receipt_email = this.receiptEmail.trim();
                }

                // Offline mode (spec §2/§9): queue the sale locally instead of
                // POSTing into the void - offline.js flushes it to pos/sync/push
                // (SyncService dedupes on client_uuid) once the connection returns.
                if (window.PosOffline && window.PosOffline.isOffline()) {
                    this.queueOffline(payments);
                    return;
                }

                this.placing = true;
                window.PosApi.post('checkout/place', body).then(function (result) {
                    self.result = result || {};
                    self.view = 'done';
                    emit('pos:order-placed', { order: self.result, result: self.result, cart: self.cart });
                    self.renderOnlineQrs();
                }).catch(function (e) {
                    // The request itself died at network level -> treat as offline.
                    if (e && e.code === 'network' && window.PosOffline) {
                        return self.queueOffline(payments);
                    }
                    notify(errMessage(e, t('place_failed', 'Could not place the order.')), 'error');
                }).finally(function () {
                    self.placing = false;
                });
            },

            /* ----------------------------------------------- offline queue */

            /**
             * SyncService entry (spec §5):
             * {client_uuid, cart:{items, customer_id, discounts, note}, payments, created_at}.
             * Item prices are only sent when they deviate from the catalog
             * price (custom lines, overrides, line discounts) so the replay
             * reproduces the amount the customer actually paid; the cart-level
             * POS discount and coupon ride along in `discounts`.
             */
            buildQueueEntry: function (payments) {
                var cart = this.cart || {};
                var items = (cart.items || []).map(function (item) {
                    var row = {
                        product_id: item.product_id || null,
                        sku: item.sku || '',
                        name: item.name || '',
                        qty: Number(item.qty) || 1,
                        is_custom: !!item.is_custom
                    };
                    if (item.is_custom
                        || Math.abs(Number(item.price) - Number(item.original_price)) > 0.004) {
                        row.price = round2(Number(item.price) || 0);
                    }
                    if ((item.note || '') !== '') {
                        row.note = item.note;
                    }
                    return row;
                });
                var discounts = {};
                if (cart.coupon_code) {
                    discounts.coupon_code = cart.coupon_code;
                }
                if (cart.pos_discount && cart.pos_discount.type) {
                    discounts.cart = {
                        type: cart.pos_discount.type,
                        value: cart.pos_discount.value
                    };
                }
                return {
                    client_uuid: window.PosOffline.uuid(),
                    cart: {
                        items: items,
                        customer_id: cart.customer ? cart.customer.id : null,
                        discounts: discounts,
                        note: cart.note || null
                    },
                    payments: payments,
                    created_at: new Date().toISOString()
                };
            },

            queueOffline: function (payments) {
                var self = this;
                var entry = this.buildQueueEntry(payments);
                this.placing = true;
                return window.PosOffline.queueOrder(entry).then(function (clientUuid) {
                    self.result = {
                        queued_offline: true,
                        client_uuid: clientUuid,
                        // Cash-only change (item 38): never advertise change
                        // due against a purely-online pending payment.
                        change_due: self.displayChange()
                    };
                    self.view = 'done';
                    notify(
                        t('queued_offline', 'You are offline - the sale was queued and will sync automatically.'),
                        'warning'
                    );
                    emit('pos:order-placed', { order: self.result, result: self.result, cart: self.cart });
                }).catch(function (e) {
                    notify(errMessage(e, t('place_failed', 'Could not place the order.')), 'error');
                }).finally(function () {
                    self.placing = false;
                });
            },

            onlineRows: function () {
                return (this.result && this.result.online) ? this.result.online : [];
            },

            /**
             * Change due on the SALE-COMPLETE view (item 49). The server
             * value is authoritative (it already returns 0 for an
             * online-only / pending payment-link sale), but change is a
             * cash-tender concept, so as a display guard it only ever shows
             * when cash was actually tendered on this sale - never beside a
             * pure "Pending" online amount.
             */
            doneChangeDue: function () {
                var change = this.result ? round2(Number(this.result.change_due) || 0) : 0;
                return (change > 0 && this.cashPaid() > 0) ? change : 0;
            },

            onlineUrl: function (row) {
                return row.redirect_url || row.payment_url || '';
            },

            /** Merchant-facing method title for an online result row. */
            onlineTitle: function (row) {
                var method = this.methodByCode(row.method_code);
                return (method && method.title) || row.method_code || '';
            },

            /** Amount pending on an online method (sum of its payment rows). */
            onlineAmount: function (row) {
                return round2(this.rows.reduce(function (sum, payRow) {
                    return sum + (payRow.method_code === row.method_code
                        ? (Number(payRow.amount) || 0)
                        : 0);
                }, 0));
            },

            /** Admin-configured instructions for an online method ('' if none). */
            onlineInstructions: function (row) {
                var method = this.methodByCode(row.method_code);
                return ((method && method.instructions) || '').trim();
            },

            /**
             * Render the scannable QR (item 38) into every online row that
             * carries a payment/redirect URL. The done view mounts via
             * `template x-if`, so retry over a few frames in case the QR
             * container is not in the DOM yet when the response lands.
             */
            renderOnlineQrs: function (attempt) {
                var self = this;
                var tries = Number(attempt) || 0;
                this.$nextTick(function () {
                    var missing = false;
                    // $root is not reachable when this runs from the async
                    // place() promise (Alpine's evaluation-scope proxy drops
                    // the magic there) - data-pos-qr only exists in this done
                    // view, so document is a safe fallback scope.
                    var root = self.$root || document;
                    self.onlineRows().forEach(function (row, i) {
                        var url = self.onlineUrl(row);
                        if (!url) {
                            return; // no template configured - instructions shown instead
                        }
                        var el = root.querySelector('[data-pos-qr="' + i + '"]');
                        if (!el) {
                            missing = true;
                            return;
                        }
                        if (!el.firstChild && window.PosReceipt && window.PosReceipt.makeQr) {
                            window.PosReceipt.makeQr(el, url);
                        }
                    });
                    if (missing && tries < 10) {
                        window.requestAnimationFrame(function () {
                            self.renderOnlineQrs(tries + 1);
                        });
                    }
                });
            },

            /* ----------------------------------------------- receipt view */

            printReceipt: function () {
                if (this.result && this.result.order_id && window.PosReceipt) {
                    window.PosReceipt.printOrder(this.result.order_id, this.result.receipt_token);
                }
            },

            viewReceipt: function () {
                if (this.result && this.result.order_id && window.PosReceipt) {
                    window.open(
                        window.PosReceipt.receiptUrl(this.result.order_id, this.result.receipt_token),
                        '_blank',
                        'noopener'
                    );
                }
            },

            newSale: function () {
                this.close();
                emit('pos:new-sale', {});
            }
        };
    };
})();
