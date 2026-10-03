/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth_MagePos terminal - customer search / create / select (spec §9).
 *
 * Registers the reactive Alpine.store('posCustomer') shared by
 * panel-customer.phtml and modal-customer.phtml: debounced search against
 * pos/customer/search, quick-create via pos/customer/create, attach/detach
 * (guest) via pos/cart/customer.
 *
 * Cross-component contract: every cart mutation dispatches the
 * `pos:cart-updated` window CustomEvent with the §7 cart payload as detail;
 * this store also listens for it to track the active quote id and the
 * attached customer.
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

    /**
     * pos/customer/search contract: data is a flat ARRAY of
     * {id, name, email, group, group_id, phone}. Tolerate a wrapped
     * {items: [...]} shape defensively but never require it.
     */
    function normalizeRows(rows) {
        if (Array.isArray(rows)) {
            return rows;
        }
        if (rows && Array.isArray(rows.items)) {
            return rows.items;
        }
        return [];
    }

    document.addEventListener('alpine:init', function () {
        window.Alpine.store('posCustomer', {
            modalOpen: false,
            query: '',
            results: [],
            searching: false,
            searched: false,
            attaching: false,
            attached: null,
            quoteId: null,
            showCreate: false,
            creating: false,
            form: { firstname: '', lastname: '', email: '', phone: '' },
            _searchTimer: null,

            init: function () {
                var self = this;
                window.addEventListener('pos:cart-updated', function (event) {
                    var cart = extractCart(event.detail);
                    if (cart) {
                        self.quoteId = cart.quote_id || self.quoteId;
                        self.attached = cart.customer || null;
                    }
                });
                window.addEventListener('pos:cart-created', function (event) {
                    if (event.detail && event.detail.quote_id) {
                        self.quoteId = event.detail.quote_id;
                    }
                });
                window.addEventListener('pos:open-customer-modal', function () {
                    self.openModal();
                });
                // After login / PIN unlock / boot: drop any stale pre-auth
                // state. cart.js reboots on the same event and re-broadcasts
                // the active cart via pos:cart-updated, which re-attaches.
                window.addEventListener('pos:authenticated', function () {
                    self.reset();
                });
                window.addEventListener('pos:auth-changed', function (event) {
                    if (event.detail && event.detail.authenticated === false) {
                        self.reset();
                        self.modalOpen = false;
                    }
                });
            },

            /**
             * Clear transient state (search, attach target, create form).
             */
            reset: function () {
                if (this._searchTimer) {
                    window.clearTimeout(this._searchTimer);
                    this._searchTimer = null;
                }
                this.query = '';
                this.results = [];
                this.searching = false;
                this.searched = false;
                this.attaching = false;
                this.attached = null;
                this.quoteId = null;
                this.showCreate = false;
                this.creating = false;
                this.form = { firstname: '', lastname: '', email: '', phone: '' };
            },

            openModal: function () {
                this.modalOpen = true;
                this.showCreate = false;
            },

            /**
             * Open the customer manager modal directly on the create form
             * (panel "New Customer" button).
             */
            openCreate: function () {
                this.modalOpen = true;
                this.showCreate = true;
            },

            closeModal: function () {
                this.modalOpen = false;
            },

            /**
             * Initial-letter avatar text for a customer row (or a raw name
             * string) - templates render it inside the avatar circle.
             */
            initial: function (customer) {
                var name = typeof customer === 'string'
                    ? customer
                    : ((customer && (customer.name || customer.firstname || customer.email)) || '');
                var letter = String(name).trim().charAt(0);
                return letter ? letter.toUpperCase() : '?';
            },

            toggleCreate: function () {
                this.showCreate = !this.showCreate;
            },

            onQueryInput: function () {
                var self = this;
                if (this._searchTimer) {
                    window.clearTimeout(this._searchTimer);
                }
                this._searchTimer = window.setTimeout(function () {
                    self.runSearch();
                }, 300);
            },

            runSearch: function () {
                var self = this;
                var query = this.query.trim();
                if (query.length < 2) {
                    this.results = [];
                    this.searched = false;
                    return Promise.resolve([]);
                }
                this.searching = true;
                return Promise.resolve().then(function () {
                    return api().get('customer/search', { q: query });
                }).then(function (rows) {
                    self.results = normalizeRows(rows);
                    self.searched = true;
                    return self.results;
                }).catch(function (error) {
                    notify((error && error.message) || t('Customer search failed.'), 'error');
                    return [];
                }).finally(function () {
                    self.searching = false;
                });
            },

            clearSearch: function () {
                this.query = '';
                this.results = [];
                this.searched = false;
            },

            /**
             * Active quote id: last seen via pos:cart-updated, then the
             * shared store, then a freshly created cart as a last resort.
             */
            resolveQuoteId: function () {
                var self = this;
                if (this.quoteId) {
                    return Promise.resolve(this.quoteId);
                }
                var shared = posStore();
                var candidate = shared && (shared.activeQuoteId || shared.quoteId);
                if (candidate) {
                    this.quoteId = candidate;
                    return Promise.resolve(candidate);
                }
                return Promise.resolve().then(function () {
                    return api().post('cart/create', {});
                }).then(function (data) {
                    var quoteId = typeof data === 'number'
                        ? data
                        : (data && (data.quote_id || data.quoteId || data.id));
                    if (!quoteId) {
                        throw { message: t('Could not start a cart.') };
                    }
                    self.quoteId = quoteId;
                    window.dispatchEvent(new CustomEvent('pos:cart-created', {
                        detail: { quote_id: quoteId }
                    }));
                    return quoteId;
                });
            },

            /**
             * Attach a customer to the active cart (null = guest).
             */
            attach: function (customerId) {
                var self = this;
                if (this.attaching) {
                    return Promise.resolve(null);
                }
                this.attaching = true;
                return this.resolveQuoteId().then(function (quoteId) {
                    return api().post('cart/customer', {
                        quote_id: quoteId,
                        customer_id: customerId !== null && customerId !== undefined ? customerId : null
                    });
                }).then(function (cart) {
                    if (cart && typeof cart === 'object') {
                        self.quoteId = cart.quote_id || self.quoteId;
                        self.attached = cart.customer || null;
                        window.dispatchEvent(new CustomEvent('pos:cart-updated', { detail: cart }));
                    }
                    return cart;
                }).catch(function (error) {
                    notify((error && error.message) || t('Could not update the cart customer.'), 'error');
                    return null;
                }).finally(function () {
                    self.attaching = false;
                });
            },

            select: function (customer) {
                var self = this;
                return this.attach(customer.id).then(function (cart) {
                    if (cart) {
                        notify(t('Customer attached: %1').replace('%1', customer.name || ''), 'success');
                        self.clearSearch();
                        self.closeModal();
                    }
                    return cart;
                });
            },

            guest: function () {
                var self = this;
                return this.attach(null).then(function (cart) {
                    if (cart) {
                        notify(t('Checking out as guest.'), 'info');
                        self.closeModal();
                    }
                    return cart;
                });
            },

            create: function () {
                var self = this;
                var form = this.form;
                if (!form.firstname.trim() || !form.lastname.trim()) {
                    notify(t('First name and last name are required.'), 'error');
                    return Promise.resolve(null);
                }
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
                    notify(t('Please enter a valid email address.'), 'error');
                    return Promise.resolve(null);
                }
                if (this.creating) {
                    return Promise.resolve(null);
                }
                this.creating = true;
                return Promise.resolve().then(function () {
                    return api().post('customer/create', {
                        firstname: form.firstname.trim(),
                        lastname: form.lastname.trim(),
                        email: form.email.trim(),
                        phone: form.phone.trim()
                    });
                }).then(function (customer) {
                    notify(t('Customer created.'), 'success');
                    self.form = { firstname: '', lastname: '', email: '', phone: '' };
                    self.showCreate = false;
                    return self.select(customer);
                }).catch(function (error) {
                    notify((error && error.message) || t('Could not create the customer.'), 'error');
                    return null;
                }).finally(function () {
                    self.creating = false;
                });
            }
        });
    });
})();
