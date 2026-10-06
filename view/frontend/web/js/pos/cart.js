/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth_MagePos terminal - cart panel (`posCart()`).
 *
 * Multi-cart tabs (quote ids persisted in localStorage), every cart operation
 * goes through window.PosApi against the pinned §7 cart endpoints, qty
 * changes are applied optimistically and reconciled with the canonical cart
 * payload the server returns from every mutation.
 *
 * SINGLE SOURCE OF TRUTH for multi-cart tabs (UI-fix contract item 52):
 * this component's tabs/activeId state (persisted under TABS_KEY/ACTIVE_KEY
 * below) is THE cart-tab list, rendered exactly once - the "#N + x" strip
 * in the SALE block header (panel-cart.phtml). The old duplicate header
 * strip ("Cart 1 / Cart 2", fed by app.js's separate panth_pos_carts list)
 * was removed because the two lists drifted; any future tab UI elsewhere
 * must read THIS component's state, never keep its own list.
 *
 * Cross-component contract (window CustomEvents):
 *   listens  `pos:authenticated`      {user} -> boot (or reload) the cart; the
 *                                     pre-auth attempt fails `unauthorized`
 *   listens  `pos:auth-changed`       {authenticated} -> same, when true
 *   listens  `pos:add-product`        {product_id|sku, qty, name, price, image}
 *   listens  `pos:order-placed`       {result} -> consume tab, start fresh cart
 *   listens  `pos:new-sale`           -> guarantee a fresh active cart
 *   listens  `pos:customer-selected`  {customer_id} -> attach to active cart
 *   listens  `pos:cart-updated`       §7 cart payload as detail (from
 *                                     customer.js) -> adopt for the active tab
 *   listens  `pos:hold-restore`       {cart_json|cart, customer_id} (replay client-side)
 *   listens  `pos:cart-restored`      {cart} server-rebuilt quote (from holds.js) -> adopt tab
 *   dispatches `pos:cart-updated`     {cart}
 *   dispatches `pos:checkout-open`    {cart}   (CHARGE)
 *   dispatches `pos:open-customer-modal` {quote_id} (handled by customer.js)
 *   dispatches `pos:holds-changed`    after a hold is saved
 */
(function () {
    'use strict';

    var TABS_KEY = 'panth_pos_cart_tabs';
    var ACTIVE_KEY = 'panth_pos_cart_active';

    function ui() { return window.PosUi || {}; }
    function money(v) { return ui().money ? ui().money(v) : String(v); }
    function notify(m, type) { if (ui().notify) { ui().notify(m, type); } }
    function t(k, f) { return ui().t ? ui().t(k, f) : (f || k); }
    function emit(n, d) { window.dispatchEvent(new CustomEvent(n, { detail: d || {} })); }
    function errMessage(e, fallback) {
        return (e && e.message) ? e.message : (fallback || t('pos_error', 'Something went wrong.'));
    }
    function isUnauthorized(e) {
        return !!(e && (e.code === 'unauthorized' || e.message === 'unauthorized'));
    }

    window.posCart = function () {
        return {
            tabs: [],          // [{quote_id}]
            activeId: null,
            cart: null,
            booted: false,
            booting: false,
            loading: false,    // skeleton flag: boot + tab switch
            saving: false,
            _newTimer: null,   // clears transient item._new flags
            _amountTimer: null, // clears the .pos-amount--changed pop class
            modal: null,       // item|discount|coupon|custom|note|hold

            // item modal state
            mItem: null,
            mQty: '',
            mPrice: '',
            mDiscType: 'percent',
            mDiscValue: '',
            mNote: '',
            keypadTarget: 'qty',

            // cart discount / coupon / custom / note / hold modal state
            dType: 'percent',
            dValue: '',
            couponCode: '',
            cName: '',
            cPrice: '',
            cQty: '1',
            noteDraft: '',
            holdLabel: '',

            _chain: Promise.resolve(),

            /* ------------------------------------------------------- boot */

            init: function () {
                var self = this;
                window.addEventListener('pos:add-product', function (e) { self.addProduct(e.detail || {}); });
                window.addEventListener('pos:order-placed', function () { self.afterOrder(); });
                window.addEventListener('pos:new-sale', function () { self.ensureFreshTab(); });
                window.addEventListener('pos:customer-selected', function (e) { self.attachCustomer(e.detail || {}); });
                window.addEventListener('pos:cart-updated', function (e) { self.onCartUpdated(e.detail || {}); });
                window.addEventListener('pos:hold-restore', function (e) { self.restoreHold(e.detail || {}); });
                window.addEventListener('pos:cart-restored', function (e) {
                    // holds.js already rebuilt the quote server-side - just
                    // adopt it as a tab and make it the active cart.
                    var detail = e.detail || {};
                    self.setCart(detail.cart || detail);
                });
                // Boot (or reload) once the cashier is signed in - the pre-auth
                // attempt below fails with `unauthorized`, so app.js's
                // 'pos:authenticated' event is what actually brings the cart up.
                window.addEventListener('pos:authenticated', function () {
                    self.reloadAfterAuth();
                });
                window.addEventListener('pos:auth-changed', function (e) {
                    if (e.detail && e.detail.authenticated) {
                        self.reloadAfterAuth();
                    }
                });
                this.ensureBoot().catch(function () {});
            },

            /**
             * Serialize the post-auth (re)load behind any in-flight pre-auth
             * boot attempt so a login that completes while the failing
             * pre-auth boot is still running can never be swallowed.
             */
            reloadAfterAuth: function () {
                var self = this;
                this._chain = this._chain.then(function () {
                    return self.booted ? self.refresh() : self.ensureBoot();
                }).catch(function () {});
            },

            ensureBoot: function () {
                var self = this;
                if (this.booted) {
                    return Promise.resolve();
                }
                if (this.booting) {
                    return this._chain;
                }
                this.booting = true;
                this.loading = true;
                var promise = (function () {
                    var ids = [];
                    try {
                        ids = JSON.parse(localStorage.getItem(TABS_KEY) || '[]') || [];
                    } catch (e) {
                        ids = [];
                    }
                    var act = Number(localStorage.getItem(ACTIVE_KEY) || 0);
                    var chain = Promise.resolve();
                    ids.forEach(function (id) {
                        id = Number(id);
                        if (!id) {
                            return;
                        }
                        chain = chain.then(function () {
                            return window.PosApi.get('cart/get', { quote_id: id }).then(function (cart) {
                                var exists = self.tabs.some(function (tab) {
                                    return Number(tab.quote_id) === id;
                                });
                                if (!exists) {
                                    self.tabs.push({ quote_id: id });
                                }
                                if (id === act || !self.cart) {
                                    self.setCart(cart);
                                }
                            }).catch(function (e) {
                                if (isUnauthorized(e)) {
                                    throw e; // not signed in yet - retry later
                                }
                                // stale/consumed quote: silently drop the tab
                            });
                        });
                    });
                    return chain.then(function () {
                        if (!self.tabs.length) {
                            return self.newTab();
                        }
                        if (!self.cart) {
                            return self.switchTab(self.tabs[0].quote_id);
                        }
                        return null;
                    }).then(function () {
                        self.booted = true;
                        self.persistTabs();
                    });
                })().catch(function (e) {
                    if (!isUnauthorized(e)) {
                        notify(errMessage(e), 'error');
                    }
                    throw e;
                }).finally(function () {
                    self.booting = false;
                    self.loading = false;
                });
                this._chain = promise.catch(function () {});
                return promise;
            },

            persistTabs: function () {
                try {
                    localStorage.setItem(TABS_KEY, JSON.stringify(this.tabs.map(function (tab) {
                        return tab.quote_id;
                    })));
                    localStorage.setItem(ACTIVE_KEY, String(this.activeId || ''));
                } catch (e) { /* storage unavailable */ }
            },

            /* ---------------------------------------------------- helpers */

            money: function (v) { return money(v); },
            t: function (k, f) { return t(k, f); },

            userInfo: function () {
                var s = (window.Alpine && window.Alpine.store) ? (window.Alpine.store('pos') || {}) : {};
                return s.user || (s.state && s.state.user) || (s.auth && s.auth.user) || null;
            },

            can: function (key) {
                var u = this.userInfo();
                var perms = (u && u.permissions) || {};
                return !!perms[key];
            },

            maxDiscountPercent: function () {
                var u = this.userInfo();
                if (!u) {
                    return 100;
                }
                var v = (u.max_discount_percent != null)
                    ? u.max_discount_percent
                    : (u.permissions || {}).max_discount_percent;
                return v == null ? 100 : Number(v);
            },

            /**
             * Reverse sync: customer.js dispatches `pos:cart-updated` with the
             * full §7 cart payload as the event detail after attaching or
             * detaching a customer there - adopt it so the cart panel reflects
             * group pricing / new totals. Our own setCart() emits the wrapped
             * shape {cart: ...} (detail.quote_id undefined), so self-dispatched
             * events are filtered out and cannot loop.
             */
            onCartUpdated: function (detail) {
                if (detail.quote_id !== undefined
                    && Number(detail.quote_id) === Number(this.activeId)
                    && detail !== this.cart) {
                    this.setCart(detail);
                }
            },

            setCart: function (cart) {
                if (!cart || !cart.quote_id) {
                    return;
                }
                var prev = this.cart;
                var sameQuote = !!prev && Number(prev.quote_id) === Number(cart.quote_id);
                var prevTotal = (prev && prev.totals)
                    ? Number(prev.totals.grand_total || 0)
                    : null;
                this.markNewLines(cart);
                this.cart = cart;
                this.activeId = Number(cart.quote_id);
                // Signature moment 1 (money ticker): pop the grand total when
                // its VALUE changes on the same quote. Tab switches load a
                // different quote and intentionally never pop.
                if (sameQuote
                    && prevTotal !== null
                    && cart.totals
                    && Math.abs(Number(cart.totals.grand_total || 0) - prevTotal) > 0.004) {
                    this.flashGrandTotal();
                }
                var exists = this.tabs.some(function (tab) {
                    return Number(tab.quote_id) === Number(cart.quote_id);
                });
                if (!exists) {
                    this.tabs.push({ quote_id: Number(cart.quote_id) });
                }
                this.persistTabs();
                emit('pos:cart-updated', { cart: cart });
            },

            /**
             * Transient `_new` flag for the slide-in animation: lines that
             * were not part of the previous payload of the SAME quote are
             * flagged so the template can key the entrance animation on them;
             * the flag is cleared shortly after the animation finishes so
             * later re-renders never replay it. Tab switches load a different
             * quote and intentionally flag nothing (the panel stagger covers
             * that case).
             */
            markNewLines: function (cart) {
                var prev = this.cart;
                if (!prev
                    || Number(prev.quote_id) !== Number(cart.quote_id)
                    || !Array.isArray(cart.items)) {
                    return;
                }
                var seen = {};
                (prev.items || []).forEach(function (item) {
                    if (Number(item.item_id)) {
                        seen[Number(item.item_id)] = true;
                    }
                });
                var flagged = false;
                cart.items.forEach(function (item) {
                    if (Number(item.item_id) && !seen[Number(item.item_id)]) {
                        item._new = true;
                        flagged = true;
                    }
                });
                if (flagged) {
                    this.scheduleNewFlagClear();
                }
            },

            /** Drop every `_new` flag once the slide-in animation is over. */
            scheduleNewFlagClear: function () {
                var self = this;
                if (this._newTimer) {
                    clearTimeout(this._newTimer);
                }
                this._newTimer = setTimeout(function () {
                    self._newTimer = null;
                    (self.cart && self.cart.items || []).forEach(function (item) {
                        if (item._new) {
                            item._new = false;
                        }
                    });
                }, 700);
            },

            /**
             * Signature moment 1 - money ticker: briefly apply the
             * `.pos-amount--changed` scale-pop/flash (pos.css keyframe
             * `pos-amount-pop`, 360ms) to every figure showing the grand
             * total - the totals "Total" row and the CHARGE button amount.
             * Runs one frame later so Alpine has painted the new value first;
             * remove -> reflow -> re-add restarts the keyframe on rapid
             * successive changes. prefers-reduced-motion is honoured by the
             * CSS itself.
             */
            flashGrandTotal: function () {
                var self = this;
                if (this._amountTimer) {
                    clearTimeout(this._amountTimer);
                    this._amountTimer = null;
                }
                window.requestAnimationFrame(function () {
                    var els = document.querySelectorAll(
                        '#pos-panel-cart .pos-totals__row--grand .pos-amount, '
                        + '#pos-panel-cart .pos-panel__footer .pos-btn--lg .pos-amount'
                    );
                    if (!els.length) {
                        return;
                    }
                    Array.prototype.forEach.call(els, function (el) {
                        el.classList.remove('pos-amount--changed');
                        void el.offsetWidth; // restart the animation
                        el.classList.add('pos-amount--changed');
                    });
                    self._amountTimer = setTimeout(function () {
                        self._amountTimer = null;
                        Array.prototype.forEach.call(els, function (el) {
                            el.classList.remove('pos-amount--changed');
                        });
                    }, 420);
                });
            },

            /**
             * Serialize server mutations so optimistic taps can never race
             * each other; every op reconciles with the returned payload and
             * any failure falls back to a full refresh.
             *
             * Stale-response guard (UIFIX §33 round 2): only reconcile a
             * payload that still targets the ACTIVE quote. The active quote
             * can change outside this chain while a queued response is in
             * flight - e.g. confirmHold()'s trailing cart/clear races a
             * hold restore: holds.js rebuilds server-side and dispatches
             * `pos:cart-restored` (setCart adopts the fresh quote
             * immediately), then the late clear response for the OLD quote
             * (0 items) lands and used to overwrite the freshly restored
             * cart. Quote-changing ops (newTab/switchTab/restore) all call
             * setCart() themselves before this reconciliation runs, so
             * matching payloads still adopt normally; mismatches are
             * server-confirmed state for a background quote and are simply
             * skipped (its tab refetches on switch).
             */
            queue: function (fn) {
                var self = this;
                this._chain = this._chain.then(function () {
                    self.saving = true;
                    return fn();
                }).then(function (cart) {
                    if (cart && cart.quote_id
                        && Number(cart.quote_id) === Number(self.activeId)) {
                        self.setCart(cart);
                    }
                }).catch(function (e) {
                    notify(errMessage(e), 'error');
                    return self.refresh();
                }).finally(function () {
                    self.saving = false;
                });
                return this._chain;
            },

            refresh: function () {
                var self = this;
                if (!this.activeId) {
                    return Promise.resolve();
                }
                return window.PosApi.get('cart/get', { quote_id: this.activeId })
                    .then(function (cart) { self.setCart(cart); })
                    .catch(function () {});
            },

            itemCount: function () {
                return (this.cart && this.cart.totals) ? Number(this.cart.totals.items_qty || 0) : 0;
            },

            grandTotal: function () {
                return (this.cart && this.cart.totals) ? Number(this.cart.totals.grand_total || 0) : 0;
            },

            hasDiscount: function (item) {
                return this.lineSaving(item) > 0;
            },

            /**
             * Money saved on a line: explicit discount amount plus any price
             * override delta. Custom items only count the discount amount -
             * their "original" price is the placeholder product's and must
             * never be presented as a markdown.
             */
            lineSaving: function (item) {
                var saving = Number(item.discount_amount || 0);
                if (!item.is_custom
                    && Number(item.original_price) - Number(item.price) > 0.0001) {
                    saving += (Number(item.original_price) - Number(item.price))
                        * Number(item.qty || 0);
                }
                return Math.round(saving * 100) / 100;
            },

            /** "unit price × qty" line under the product name. */
            lineMeta: function (item) {
                return money(item.price) + ' × ' + Number(item.qty || 0);
            },

            discountTotal: function () {
                return (this.cart && this.cart.totals)
                    ? Number(this.cart.totals.discount || 0)
                    : 0;
            },

            posDiscountTotal: function () {
                return (this.cart && this.cart.totals)
                    ? Number(this.cart.totals.pos_discount || 0)
                    : 0;
            },

            posDiscountLabel: function () {
                var pd = this.cart && this.cart.pos_discount;
                if (!pd) {
                    return '';
                }
                return pd.type === 'percent' ? Number(pd.value) + '%' : money(pd.value);
            },

            /** Item-modal keypad display: value of the focused field. */
            activeKeypadValue: function () {
                if (this.keypadTarget === 'price') {
                    return this.mPrice || '0';
                }
                if (this.keypadTarget === 'discount') {
                    return (this.mDiscValue || '0') + (this.mDiscType === 'percent' ? '%' : '');
                }
                return this.mQty || '0';
            },

            /** Custom-item modal keypad display. */
            customKeypadValue: function () {
                return this.keypadTarget === 'customQty'
                    ? (this.cQty || '1')
                    : (this.cPrice || '0');
            },

            /* -------------------------------------------------------- tabs */

            newTab: function () {
                var self = this;
                return window.PosApi.post('cart/create', {}).then(function (cart) {
                    self.setCart(cart);
                    return cart;
                });
            },

            addTab: function () {
                var self = this;
                this.queue(function () { return self.newTab(); });
            },

            switchTab: function (quoteId) {
                var self = this;
                quoteId = Number(quoteId);
                if (quoteId === this.activeId) {
                    return Promise.resolve();
                }
                this.loading = true;
                return window.PosApi.get('cart/get', { quote_id: quoteId }).then(function (cart) {
                    self.setCart(cart);
                }).catch(function (e) {
                    notify(errMessage(e), 'error');
                    self.dropTab(quoteId);
                }).finally(function () {
                    self.loading = false;
                });
            },

            dropTab: function (quoteId) {
                quoteId = Number(quoteId);
                this.tabs = this.tabs.filter(function (tab) {
                    return Number(tab.quote_id) !== quoteId;
                });
                this.persistTabs();
            },

            closeTab: function (quoteId) {
                var self = this;
                quoteId = Number(quoteId);
                this.dropTab(quoteId);
                if (quoteId === this.activeId) {
                    this.cart = null;
                    this.activeId = null;
                    if (this.tabs.length) {
                        this.switchTab(this.tabs[0].quote_id);
                    } else {
                        this.queue(function () { return self.newTab(); });
                    }
                }
            },

            afterOrder: function () {
                // Active quote was consumed by the order - replace the tab.
                var self = this;
                var consumed = this.activeId;
                this.dropTab(consumed);
                this.cart = null;
                this.activeId = null;
                this.queue(function () { return self.newTab(); });
            },

            ensureFreshTab: function () {
                var self = this;
                this.ensureBoot().then(function () {
                    if (!self.tabs.length) {
                        self.queue(function () { return self.newTab(); });
                    }
                }).catch(function () {});
            },

            /* ------------------------------------------------------- lines */

            addProduct: function (detail) {
                var self = this;
                this.ensureBoot().then(function () {
                    // Optimistic merge: bump qty on an existing plain line.
                    var qty = Number(detail.qty || 1);
                    if (self.cart && detail.product_id) {
                        var line = (self.cart.items || []).find(function (item) {
                            return Number(item.product_id) === Number(detail.product_id) && !item.is_custom;
                        });
                        if (line) {
                            self.bumpLocal(line, qty);
                        } else {
                            self.cart.items.push({
                                item_id: 0,
                                product_id: Number(detail.product_id),
                                sku: detail.sku || '',
                                name: detail.name || '',
                                qty: qty,
                                price: Number(detail.price || 0),
                                original_price: Number(detail.price || 0),
                                row_total: Number(detail.price || 0) * qty,
                                discount_amount: 0,
                                tax_amount: 0,
                                note: null,
                                is_custom: false,
                                image: detail.image || null,
                                _optimistic: true,
                                _new: true
                            });
                            self.scheduleNewFlagClear();
                            self.bumpTotalsLocal(Number(detail.price || 0) * qty, qty);
                        }
                    }
                    var body = { quote_id: self.activeId, qty: qty };
                    if (detail.product_id) {
                        body.product_id = Number(detail.product_id);
                    } else if (detail.sku) {
                        body.sku = detail.sku;
                    } else {
                        return;
                    }
                    self.queue(function () { return window.PosApi.post('cart/add', body); });
                }).catch(function () {
                    notify(t('login_first', 'Sign in to start selling.'), 'error');
                });
            },

            bumpLocal: function (item, delta) {
                var qty = Number(item.qty || 0) + delta;
                item.qty = qty;
                item.row_total = Number(item.price || 0) * qty;
                this.bumpTotalsLocal(Number(item.price || 0) * delta, delta);
            },

            bumpTotalsLocal: function (amountDelta, qtyDelta) {
                if (!this.cart || !this.cart.totals) {
                    return;
                }
                var tot = this.cart.totals;
                tot.subtotal = Number(tot.subtotal || 0) + amountDelta;
                tot.grand_total = Number(tot.grand_total || 0) + amountDelta;
                tot.items_qty = Number(tot.items_qty || 0) + qtyDelta;
                // Optimistic adds/steps repaint the grand total immediately -
                // pop it now; the later server reconcile lands on the same
                // figure and (correctly) does not pop a second time.
                if (Math.abs(amountDelta) > 0.004) {
                    this.flashGrandTotal();
                }
            },

            stepQty: function (item, delta) {
                if (item._optimistic) {
                    return;
                }
                var self = this;
                var newQty = Number(item.qty || 0) + delta;
                if (newQty <= 0) {
                    this.removeLine(item);
                    return;
                }
                this.bumpLocal(item, delta); // optimistic; reconciled by queue()
                this.queue(function () {
                    return window.PosApi.post('cart/update', {
                        quote_id: self.activeId,
                        item_id: item.item_id,
                        qty: newQty
                    });
                });
            },

            removeLine: function (item) {
                if (item._optimistic) {
                    return;
                }
                var self = this;
                this.cart.items = this.cart.items.filter(function (line) {
                    return line.item_id !== item.item_id;
                });
                this.queue(function () {
                    return window.PosApi.post('cart/remove', {
                        quote_id: self.activeId,
                        item_id: item.item_id
                    });
                });
            },

            clearCart: function () {
                var self = this;
                if (!this.cart || !(this.cart.items || []).length) {
                    return;
                }
                this.queue(function () {
                    return window.PosApi.post('cart/clear', { quote_id: self.activeId });
                });
            },

            /* -------------------------------------------------- item modal */

            openItem: function (item) {
                if (item._optimistic) {
                    return;
                }
                this.mItem = item;
                this.mQty = String(Number(item.qty || 1));
                this.mPrice = Number(item.price || 0).toFixed(2);
                this.mDiscType = 'percent';
                this.mDiscValue = '';
                this.mNote = item.note || '';
                this.keypadTarget = 'qty';
                this.modal = 'item';
            },

            closeModal: function () {
                this.modal = null;
                this.mItem = null;
            },

            padKey: function (event) {
                var handlers = { item: 'keyTap', discount: 'keyTapDiscount', custom: 'keyTapCustom' };
                var handler = handlers[this.modal];
                var key;
                if (!handler || !window.posPadKey) {
                    return;
                }
                key = window.posPadKey(event);
                if (key === null || key === 'enter') {
                    return;
                }
                event.preventDefault();
                this[handler](key);
            },

            keyTap: function (key) {
                var map = { qty: 'mQty', price: 'mPrice', discount: 'mDiscValue' };
                var prop = map[this.keypadTarget] || 'mQty';
                this[prop] = this.applyKey(this[prop], key);
            },

            applyKey: function (value, key) {
                value = String(value == null ? '' : value);
                if (key === 'C') {
                    return '';
                }
                if (key === 'back') {
                    return value.slice(0, -1);
                }
                if (key === '.') {
                    if (value.indexOf('.') >= 0) {
                        return value;
                    }
                    return value === '' ? '0.' : value + '.';
                }
                if (/^\d$/.test(key)) {
                    if (value === '0') {
                        return key;
                    }
                    if (/\.\d{2}$/.test(value)) {
                        return value; // cap at 2 decimals
                    }
                    return value + key;
                }
                return value;
            },

            saveItem: function () {
                var self = this;
                var item = this.mItem;
                if (!item) {
                    return;
                }
                var qty = Number(this.mQty);
                var price = Number(this.mPrice);
                var note = this.mNote;
                var discValue = Number(this.mDiscValue);
                var discType = this.mDiscType;

                if (!isFinite(qty) || qty <= 0) {
                    notify(t('qty_invalid', 'Quantity must be greater than zero.'), 'error');
                    return;
                }
                if (discValue > 0 && discType === 'percent' && discValue > this.maxDiscountPercent()) {
                    notify(t('discount_over_cap', 'Discount exceeds your allowed maximum of')
                        + ' ' + this.maxDiscountPercent() + '%', 'error');
                    return;
                }

                var body = { quote_id: this.activeId, item_id: item.item_id };
                var dirty = false;
                if (Math.abs(qty - Number(item.qty)) > 0.0001) {
                    body.qty = qty;
                    dirty = true;
                }
                if (this.can('can_price_override') && Math.abs(price - Number(item.price)) > 0.004) {
                    body.price = price;
                    dirty = true;
                }
                if ((note || '') !== (item.note || '')) {
                    body.note = note;
                    dirty = true;
                }

                this.queue(function () {
                    var step = dirty
                        ? window.PosApi.post('cart/update', body)
                        : Promise.resolve(null);
                    if (discValue > 0) {
                        step = step.then(function () {
                            return window.PosApi.post('cart/discount', {
                                quote_id: self.activeId,
                                scope: 'item',
                                item_id: item.item_id,
                                type: discType,
                                value: discValue
                            });
                        });
                    }
                    return step;
                });
                this.closeModal();
            },

            removeItemFromModal: function () {
                var item = this.mItem;
                this.closeModal();
                if (item) {
                    this.removeLine(item);
                }
            },

            /* ----------------------------------------- cart discount modal */

            openDiscount: function () {
                var pd = this.cart && this.cart.pos_discount;
                this.dType = pd ? pd.type : 'percent';
                this.dValue = pd ? String(pd.value) : '';
                this.keypadTarget = 'cartDiscount';
                this.modal = 'discount';
            },

            keyTapDiscount: function (key) {
                this.dValue = this.applyKey(this.dValue, key);
            },

            applyCartDiscount: function () {
                var self = this;
                var value = Number(this.dValue);
                if (!isFinite(value) || value <= 0) {
                    notify(t('discount_invalid', 'Enter a discount value.'), 'error');
                    return;
                }
                if (this.dType === 'percent' && value > this.maxDiscountPercent()) {
                    notify(t('discount_over_cap', 'Discount exceeds your allowed maximum of')
                        + ' ' + this.maxDiscountPercent() + '%', 'error');
                    return;
                }
                var type = this.dType;
                this.queue(function () {
                    return window.PosApi.post('cart/discount', {
                        quote_id: self.activeId,
                        scope: 'cart',
                        type: type,
                        value: value
                    });
                });
                this.modal = null;
            },

            removeCartDiscount: function () {
                var self = this;
                this.queue(function () {
                    return window.PosApi.post('cart/discount', {
                        quote_id: self.activeId,
                        scope: 'cart',
                        remove: true
                    });
                });
                this.modal = null;
            },

            /* ----------------------------------------------- coupon modal */

            openCoupon: function () {
                this.couponCode = (this.cart && this.cart.coupon_code) || '';
                this.modal = 'coupon';
            },

            applyCoupon: function () {
                var self = this;
                var code = (this.couponCode || '').trim();
                if (!code) {
                    notify(t('coupon_required', 'Enter a coupon code.'), 'error');
                    return;
                }
                this.queue(function () {
                    return window.PosApi.post('cart/coupon', { quote_id: self.activeId, code: code });
                });
                this.modal = null;
            },

            removeCoupon: function () {
                var self = this;
                this.queue(function () {
                    return window.PosApi.post('cart/coupon', { quote_id: self.activeId, remove: true });
                });
                this.modal = null;
            },

            /* ------------------------------------------ custom item modal */

            openCustom: function () {
                if (!this.can('can_custom_product')) {
                    notify(t('no_permission', 'You do not have permission for this action.'), 'error');
                    return;
                }
                this.cName = '';
                this.cPrice = '';
                this.cQty = '1';
                this.keypadTarget = 'customPrice';
                this.modal = 'custom';
            },

            keyTapCustom: function (key) {
                if (this.keypadTarget === 'customQty') {
                    this.cQty = this.applyKey(this.cQty, key);
                } else {
                    this.cPrice = this.applyKey(this.cPrice, key);
                }
            },

            addCustomItem: function () {
                var self = this;
                var name = (this.cName || '').trim();
                var price = Number(this.cPrice);
                var qty = Number(this.cQty);
                if (!name) {
                    notify(t('custom_name_required', 'Enter a name for the custom item.'), 'error');
                    return;
                }
                if (!isFinite(price) || price < 0) {
                    notify(t('price_invalid', 'Enter a valid price.'), 'error');
                    return;
                }
                if (!isFinite(qty) || qty <= 0) {
                    qty = 1;
                }
                this.queue(function () {
                    return window.PosApi.post('cart/custom', {
                        quote_id: self.activeId,
                        name: name,
                        price: price,
                        qty: qty
                    });
                });
                this.modal = null;
            },

            /* ------------------------------------------------- note modal */

            openNote: function () {
                this.noteDraft = (this.cart && this.cart.note) || '';
                this.modal = 'note';
            },

            saveNote: function () {
                var self = this;
                var note = this.noteDraft;
                this.queue(function () {
                    return window.PosApi.post('cart/note', { quote_id: self.activeId, note: note });
                });
                this.modal = null;
            },

            /* --------------------------------------------------- customer */

            openCustomer: function () {
                // customer.js (Alpine.store('posCustomer')) listens for this.
                emit('pos:open-customer-modal', { quote_id: this.activeId });
            },

            attachCustomer: function (detail) {
                var self = this;
                var id = detail.customer_id != null ? detail.customer_id : detail.id;
                if (id == null || !this.activeId) {
                    return;
                }
                this.queue(function () {
                    return window.PosApi.post('cart/customer', {
                        quote_id: self.activeId,
                        customer_id: Number(id)
                    });
                });
            },

            detachCustomer: function () {
                var self = this;
                this.queue(function () {
                    return window.PosApi.post('cart/customer', {
                        quote_id: self.activeId,
                        customer_id: ''
                    });
                });
            },

            /* ------------------------------------------------------- hold */

            openHold: function () {
                if (!this.cart || !(this.cart.items || []).length) {
                    notify(t('cart_empty', 'The cart is empty.'), 'error');
                    return;
                }
                var customer = this.cart.customer;
                this.holdLabel = customer && customer.name
                    ? customer.name
                    : new Date().toLocaleTimeString();
                this.modal = 'hold';
            },

            confirmHold: function () {
                var self = this;
                var label = (this.holdLabel || '').trim() || new Date().toLocaleTimeString();
                var cart = this.cart;
                this.queue(function () {
                    return window.PosApi.post('hold/save', {
                        label: label,
                        cart_json: JSON.stringify(cart),
                        customer_id: cart.customer ? cart.customer.id : null
                    }).then(function () {
                        emit('pos:holds-changed', {});
                        notify(t('cart_held', 'Cart placed on hold.'), 'success');
                        return window.PosApi.post('cart/clear', { quote_id: self.activeId });
                    });
                });
                this.modal = null;
            },

            /**
             * Fallback hold-restore listener: rebuild the held cart on a new
             * tab by re-adding every line server-side (spec §5 HoldService).
             */
            restoreHold: function (detail) {
                var self = this;
                var cart = detail.cart || null;
                if (!cart && detail.cart_json) {
                    try {
                        cart = typeof detail.cart_json === 'string'
                            ? JSON.parse(detail.cart_json)
                            : detail.cart_json;
                    } catch (e) {
                        cart = null;
                    }
                }
                if (!cart || !(cart.items || []).length) {
                    return;
                }
                this.queue(function () {
                    return self.newTab().then(function (fresh) {
                        var quoteId = fresh.quote_id;
                        var chain = Promise.resolve(null);
                        (cart.items || []).forEach(function (item) {
                            chain = chain.then(function () {
                                if (item.is_custom) {
                                    return window.PosApi.post('cart/custom', {
                                        quote_id: quoteId,
                                        name: item.name,
                                        price: item.price,
                                        qty: item.qty
                                    });
                                }
                                var body = { quote_id: quoteId, qty: item.qty };
                                if (item.product_id) {
                                    body.product_id = item.product_id;
                                } else {
                                    body.sku = item.sku;
                                }
                                return window.PosApi.post('cart/add', body);
                            });
                        });
                        var customerId = (cart.customer && cart.customer.id) || detail.customer_id;
                        if (customerId) {
                            chain = chain.then(function () {
                                return window.PosApi.post('cart/customer', {
                                    quote_id: quoteId,
                                    customer_id: Number(customerId)
                                });
                            });
                        }
                        if (cart.coupon_code) {
                            chain = chain.then(function () {
                                return window.PosApi.post('cart/coupon', {
                                    quote_id: quoteId,
                                    code: cart.coupon_code
                                }).catch(function () { return null; });
                            });
                        }
                        if (cart.pos_discount && cart.pos_discount.type) {
                            chain = chain.then(function () {
                                return window.PosApi.post('cart/discount', {
                                    quote_id: quoteId,
                                    scope: 'cart',
                                    type: cart.pos_discount.type,
                                    value: cart.pos_discount.value
                                }).catch(function () { return null; });
                            });
                        }
                        if (cart.note) {
                            chain = chain.then(function () {
                                return window.PosApi.post('cart/note', {
                                    quote_id: quoteId,
                                    note: cart.note
                                });
                            });
                        }
                        return chain;
                    });
                });
            },

            /* ----------------------------------------------------- charge */

            charge: function () {
                var self = this;
                if (!this.cart || !(this.cart.items || []).length) {
                    notify(t('cart_empty', 'The cart is empty.'), 'error');
                    return;
                }
                // Wait for any in-flight optimistic ops to settle first so the
                // checkout total matches the server.
                this._chain.then(function () {
                    emit('pos:checkout-open', { cart: self.cart });
                });
            }
        };
    };
})();
