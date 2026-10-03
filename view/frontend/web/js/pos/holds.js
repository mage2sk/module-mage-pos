/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth_MagePos terminal - hold / retrieve parked carts (spec §9).
 *
 * Registers the reactive Alpine.store('posHolds') backing panel-holds.phtml:
 * list (pos/hold/all), save (pos/hold/save - the §7 cart payload is stored
 * verbatim as cart_json), restore (pos/hold/restore returns cart_json; the
 * lines are re-added server-side via the cart endpoints, then the hold is
 * removed) and delete (pos/hold/remove, two-tap confirm).
 *
 * Cross-component contract: listens for `pos:cart-updated` (detail = §7
 * cart payload) to know the current cart, accepts `pos:hold-cart`
 * ({label?, cart?}) from the cart panel, reloads on `pos:holds-changed`
 * (cart.js saves holds directly and announces success with it), on
 * `pos:authenticated` / `pos:session-changed`, and whenever the Held Carts
 * panel is opened (`pos:panels-changed` / `pos:toggle-panel`); dispatches
 * `pos:cart-updated` + `pos:cart-restored` after a successful restore.
 */
(function () {
    'use strict';

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

    function extractCart(detail) {
        if (!detail || typeof detail !== 'object') {
            return null;
        }
        var cart = detail.cart && typeof detail.cart === 'object' ? detail.cart : detail;
        return cart.quote_id !== undefined ? cart : null;
    }

    document.addEventListener('alpine:init', function () {
        window.Alpine.store('posHolds', {
            holds: [],
            loading: false,
            saving: false,
            restoringId: null,
            deletingId: null,
            confirmDeleteId: null,
            lastCart: null,
            reloadPending: false,

            init: function () {
                var self = this;
                window.addEventListener('pos:cart-updated', function (event) {
                    var cart = extractCart(event.detail);
                    if (cart) {
                        self.lastCart = cart;
                    }
                });
                // Authoritative (re)load trigger: app.js dispatches
                // 'pos:authenticated' after login / PIN unlock / boot with an
                // active session - never fetch before that (pre-auth requests
                // are rejected and would leave the panel empty forever).
                window.addEventListener('pos:authenticated', function () {
                    self.load();
                });
                window.addEventListener('pos:auth-changed', function (event) {
                    if (event.detail && event.detail.authenticated === false) {
                        self.holds = [];
                        self.confirmDeleteId = null;
                        self.lastCart = null;
                    }
                });
                window.addEventListener('pos:session-changed', function () {
                    self.load();
                });
                window.addEventListener('pos:hold-cart', function (event) {
                    var detail = event.detail || {};
                    self.save(detail.label || '', detail.cart || null);
                });
                // The cart panel's Hold modal (cart.js confirmHold) posts
                // pos/hold/save DIRECTLY and announces success via
                // 'pos:holds-changed' - without this listener a freshly held
                // cart never shows until the next login (root cause of the
                // "No held carts" report, UI-fix contract §33).
                window.addEventListener('pos:holds-changed', function () {
                    self.load();
                });
                // Refetch whenever the Held Carts panel is OPENED so the
                // list is never stale on open. app.js togglePanel dispatches
                // 'pos:panels-changed' {name, visible} after flipping the
                // $store.pos.panels boolean - the authoritative open signal.
                window.addEventListener('pos:panels-changed', function (event) {
                    var detail = event.detail || {};
                    if (detail.name === 'holds' && detail.visible) {
                        self.load();
                    }
                });
                // Fallback for layouts without the $store.pos boolean bridge
                // (layout.js toggles visibility directly and only the raw
                // 'pos:toggle-panel' request fires): check visibility after
                // the flip settles; a toggle that CLOSED the panel skips the
                // fetch. Skipped entirely when the bridge exists, since
                // 'pos:panels-changed' already handled it above.
                window.addEventListener('pos:toggle-panel', function (event) {
                    if (!event.detail || event.detail.panel !== 'holds') {
                        return;
                    }
                    var store = posStore();
                    if (store && store.panels && typeof store.panels.holds === 'boolean') {
                        return;
                    }
                    window.setTimeout(function () {
                        if (self.isPanelOpen()) {
                            self.load();
                        }
                    }, 50);
                });
            },

            /**
             * Is the Held Carts panel currently visible? Prefers the
             * $store.pos.panels boolean bridge, falls back to the DOM.
             */
            isPanelOpen: function () {
                var store = posStore();
                if (store && store.panels && typeof store.panels.holds === 'boolean') {
                    return store.panels.holds;
                }
                var el = document.getElementById('pos-panel-holds');
                return !!(el && el.getBoundingClientRect().height > 0);
            },

            money: function (value) {
                var store = posStore();
                var symbol = (store && store.config && store.config.currency_symbol) || '';
                return symbol + Number(value || 0).toFixed(2);
            },

            load: function () {
                var self = this;
                if (!window.PosApi) {
                    return Promise.resolve(this.holds);
                }
                if (this.loading) {
                    // Never DROP a reload request that arrives while a fetch
                    // is in flight (save-success racing the panel-open load):
                    // queue exactly one follow-up fetch so the freshest rows
                    // always land.
                    this.reloadPending = true;
                    return Promise.resolve(this.holds);
                }
                this.loading = true;
                this.reloadPending = false;
                return Promise.resolve().then(function () {
                    return api().get('hold/all');
                }).then(function (rows) {
                    // pos/hold/all contract: data is a flat ARRAY of hold rows
                    // - replace the array wholesale so Alpine re-renders.
                    self.holds = Array.isArray(rows) ? rows : [];
                    return self.holds;
                }).catch(function (error) {
                    // Keep the current list; only surface real failures
                    // (an unauthorized pre-boot race stays silent).
                    if (error && error.code !== 'unauthorized') {
                        notify((error && error.message) || t('Could not load held carts.'), 'error');
                    }
                    return self.holds;
                }).finally(function () {
                    self.loading = false;
                    if (self.reloadPending) {
                        self.reloadPending = false;
                        self.load();
                    }
                });
            },

            /**
             * Park a cart: the §7 cart payload is stored verbatim as
             * cart_json so restore() can replay it.
             */
            save: function (label, cart) {
                var self = this;
                cart = cart || this.lastCart;
                if (!cart || !Array.isArray(cart.items) || !cart.items.length) {
                    notify(t('Nothing to hold - the cart is empty.'), 'error');
                    return Promise.resolve(null);
                }
                if (this.saving) {
                    return Promise.resolve(null);
                }
                this.saving = true;
                return Promise.resolve().then(function () {
                    return api().post('hold/save', {
                        label: String(label || '').trim(),
                        cart_json: JSON.stringify(cart),
                        customer_id: cart.customer && cart.customer.id ? cart.customer.id : null
                    });
                }).then(function (hold) {
                    notify(t('Cart held.'), 'success');
                    window.dispatchEvent(new CustomEvent('pos:hold-saved', { detail: { hold: hold } }));
                    self.load();
                    return hold;
                }).catch(function (error) {
                    notify((error && error.message) || t('Could not hold the cart.'), 'error');
                    return null;
                }).finally(function () {
                    self.saving = false;
                });
            },

            itemsCount: function (hold) {
                return hold && hold.cart && Array.isArray(hold.cart.items) ? hold.cart.items.length : 0;
            },

            customerLabel: function (hold) {
                if (hold && hold.cart && hold.cart.customer && hold.cart.customer.name) {
                    return hold.cart.customer.name;
                }
                if (hold && hold.customer_id) {
                    return '#' + hold.customer_id;
                }
                return t('Guest');
            },

            totalLabel: function (hold) {
                var totals = hold && hold.cart && hold.cart.totals;
                return totals && totals.grand_total !== undefined ? this.money(totals.grand_total) : '';
            },

            /**
             * Relative age string for a hold's created_at ("YYYY-MM-DD
             * HH:MM:SS" UTC from the server): "Just now", "12m ago",
             * "3h ago", "Yesterday HH:MM", then "Mon D HH:MM".
             */
            ageLabel: function (hold) {
                var raw = hold && hold.created_at ? String(hold.created_at) : '';
                if (raw === '') {
                    return '';
                }
                var date = new Date(raw.replace(' ', 'T') + (raw.indexOf('Z') === -1 ? 'Z' : ''));
                if (isNaN(date.getTime())) {
                    return raw;
                }
                var minutes = Math.floor(Math.max(0, Date.now() - date.getTime()) / 60000);
                if (minutes < 1) {
                    return t('Just now');
                }
                if (minutes < 60) {
                    return t('%1m ago').replace('%1', minutes);
                }
                var hours = Math.floor(minutes / 60);
                if (hours < 24) {
                    return t('%1h ago').replace('%1', hours);
                }
                var time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                var yesterday = new Date();
                yesterday.setDate(yesterday.getDate() - 1);
                if (date.toDateString() === yesterday.toDateString()) {
                    return t('Yesterday %1').replace('%1', time);
                }
                return date.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + time;
            },

            /**
             * Back-compat alias - panel-holds.phtml binds timeLabel().
             */
            timeLabel: function (hold) {
                return this.ageLabel(hold);
            },

            /**
             * Fallback row label when the cashier saved without one.
             */
            holdLabel: function (hold) {
                var label = hold && hold.label ? String(hold.label).trim() : '';
                return label !== '' ? label : t('Hold #%1').replace('%1', hold && hold.hold_id ? hold.hold_id : '');
            },

            /**
             * Restore a hold into a fresh server cart, broadcast the rebuilt
             * cart payload, then remove the hold row.
             */
            restore: function (holdId) {
                var self = this;
                if (this.restoringId) {
                    return Promise.resolve(null);
                }
                this.restoringId = holdId;
                return Promise.resolve().then(function () {
                    return api().post('hold/restore', { hold_id: holdId });
                }).then(function (hold) {
                    var cart = hold && hold.cart;
                    if (!cart && hold && hold.cart_json) {
                        try {
                            cart = JSON.parse(hold.cart_json);
                        } catch (e) {
                            cart = null;
                        }
                    }
                    if (!cart || !Array.isArray(cart.items) || !cart.items.length) {
                        throw { message: t('This hold has no cart data.') };
                    }
                    return self.rebuild(cart, hold);
                }).then(function (rebuilt) {
                    window.dispatchEvent(new CustomEvent('pos:cart-updated', { detail: rebuilt }));
                    window.dispatchEvent(new CustomEvent('pos:cart-restored', {
                        detail: { cart: rebuilt, hold_id: holdId }
                    }));
                    return api().post('hold/remove', { hold_id: holdId }).catch(function () {
                        return null; // already restored - removal is best effort
                    }).then(function () {
                        notify(t('Hold restored to a new cart.'), 'success');
                        self.load();
                        return rebuilt;
                    });
                }).catch(function (error) {
                    notify((error && error.message) || t('Could not restore the hold.'), 'error');
                    return null;
                }).finally(function () {
                    self.restoringId = null;
                });
            },

            /**
             * Replay a held §7 cart payload through the cart endpoints:
             * custom lines via cart/custom, catalog lines via cart/add
             * (+ cart/update for price overrides and line notes), customer,
             * coupon, cart-level POS discount and cart note. Item-level POS
             * discounts cannot be replayed (the payload only carries the
             * resulting amounts) and are intentionally skipped.
             */
            rebuild: function (cart, hold) {
                var self = this;
                return Promise.resolve().then(function () {
                    return api().post('cart/create', {});
                }).then(function (created) {
                    var quoteId = typeof created === 'number'
                        ? created
                        : (created && (created.quote_id || created.quoteId || created.id));
                    if (!quoteId) {
                        throw { message: t('Could not start a cart.') };
                    }

                    var payload = null;
                    var chain = Promise.resolve();

                    cart.items.forEach(function (item) {
                        chain = chain.then(function () {
                            if (item.is_custom) {
                                return api().post('cart/custom', {
                                    quote_id: quoteId,
                                    name: item.name,
                                    price: item.price,
                                    qty: item.qty,
                                    tax_class_id: item.tax_class_id !== undefined ? item.tax_class_id : null
                                }).then(function (result) {
                                    payload = result || payload;
                                });
                            }
                            return api().post('cart/add', {
                                quote_id: quoteId,
                                product_id: item.product_id || null,
                                sku: item.sku || null,
                                qty: item.qty
                            }).then(function (result) {
                                payload = result || payload;
                                var overridden = item.original_price !== undefined
                                    && item.original_price !== null
                                    && Number(item.price) !== Number(item.original_price);
                                var note = item.note ? String(item.note) : '';
                                if (!overridden && note === '') {
                                    return null;
                                }
                                var line = self.matchLine(payload && payload.items, item);
                                if (!line) {
                                    return null;
                                }
                                return api().post('cart/update', {
                                    quote_id: quoteId,
                                    item_id: line.item_id,
                                    price: overridden ? item.price : null,
                                    note: note !== '' ? note : null
                                }).then(function (result) {
                                    payload = result || payload;
                                });
                            });
                        });
                    });

                    var customerId = (cart.customer && cart.customer.id) || (hold && hold.customer_id) || null;
                    if (customerId) {
                        chain = chain.then(function () {
                            return api().post('cart/customer', {
                                quote_id: quoteId,
                                customer_id: customerId
                            }).then(function (result) {
                                payload = result || payload;
                            });
                        });
                    }

                    if (cart.coupon_code) {
                        chain = chain.then(function () {
                            return api().post('cart/coupon', {
                                quote_id: quoteId,
                                code: cart.coupon_code
                            }).then(function (result) {
                                payload = result || payload;
                            }).catch(function (error) {
                                notify((error && error.message) || t('Coupon could not be re-applied.'), 'warning');
                            });
                        });
                    }

                    if (cart.pos_discount && cart.pos_discount.type) {
                        chain = chain.then(function () {
                            return api().post('cart/discount', {
                                quote_id: quoteId,
                                scope: 'cart',
                                type: cart.pos_discount.type,
                                value: cart.pos_discount.value
                            }).then(function (result) {
                                payload = result || payload;
                            }).catch(function (error) {
                                notify((error && error.message) || t('Discount could not be re-applied.'), 'warning');
                            });
                        });
                    }

                    if (cart.note) {
                        chain = chain.then(function () {
                            return api().post('cart/note', {
                                quote_id: quoteId,
                                note: cart.note
                            }).then(function (result) {
                                payload = result || payload;
                            }).catch(function () {
                                return null;
                            });
                        });
                    }

                    return chain.then(function () {
                        if (payload) {
                            return payload;
                        }
                        return api().get('cart/get', { quote_id: quoteId });
                    });
                });
            },

            /**
             * Find the freshly added cart line for a held item (reverse scan
             * by product_id, then sku, fallback: last line).
             */
            matchLine: function (items, source) {
                if (!Array.isArray(items) || !items.length) {
                    return null;
                }
                var productId = source.product_id ? Number(source.product_id) : 0;
                var sku = source.sku ? String(source.sku) : '';
                for (var i = items.length - 1; i >= 0; i--) {
                    var line = items[i];
                    if (productId > 0 && Number(line.product_id) === productId) {
                        return line;
                    }
                    if (sku !== '' && String(line.sku) === sku) {
                        return line;
                    }
                }
                return items[items.length - 1];
            },

            askDelete: function (holdId) {
                this.confirmDeleteId = holdId;
            },

            cancelDelete: function () {
                this.confirmDeleteId = null;
            },

            remove: function (holdId) {
                var self = this;
                if (this.deletingId) {
                    return Promise.resolve(null);
                }
                this.deletingId = holdId;
                return Promise.resolve().then(function () {
                    return api().post('hold/remove', { hold_id: holdId });
                }).then(function () {
                    notify(t('Hold removed.'), 'success');
                    self.confirmDeleteId = null;
                    self.load();
                    return true;
                }).catch(function (error) {
                    notify((error && error.message) || t('Could not remove the hold.'), 'error');
                    return null;
                }).finally(function () {
                    self.deletingId = null;
                });
            }
        });
    });
})();
