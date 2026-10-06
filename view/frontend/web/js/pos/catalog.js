/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth_MagePos terminal - catalog panel.
 *
 * Exposes the Alpine components `posCatalog()` (search bar + barcode wedge
 * buffer + camera scan + category chips + infinite-scroll product grid) and
 * `posQuickKeys()` (paged colored favourite tiles), plus the small shared UI
 * helper bag `window.PosUi` (money/notify/t/emit) reused by cart.js,
 * checkout.js and receipt.js (this file loads first of the four).
 *
 * Pagination (UI-fix contract item 28): search / category / top sellers all
 * consume the paged envelope {items, total_count, page, page_size} (bare
 * arrays still accepted), page via the panel body's scroll + a "Load more"
 * fallback, dedupe by product id, stop at the server-reported end, and reset
 * to page 1 on every new query/chip. Superseded fetches are aborted
 * (AbortController) and the search input is debounced in the template
 * (item 29).
 *
 * Cross-component contract (window CustomEvents):
 *   dispatches `pos:add-product`      {product_id, sku, qty, name, price, image}
 *   dispatches `pos:quickkeys-changed` after a terminal pin/unpin/reorder so
 *                                     every quick-key consumer reloads
 *   dispatches `pos:quickkeys-data`   {map: {productId: quickKeyId}} after the
 *                                     quick keys load, so product cards can
 *                                     show their pinned state
 *   listens    `pos:authenticated`    reload categories + top sellers + quick
 *                                     keys (pre-auth fetches are unauthorized
 *                                     and come back empty)
 *   listens    `pos:quickkeys-changed` reload quick key tiles
 *   listens    `pos:cart-updated`     adopt the attached customer's group so
 *                                     catalog prices refresh group-aware
 *
 * Also registers Alpine.store('posProduct') - the product quick-view modal
 * (UI-fix contract item 23) backed by GET pos/catalog/product, rendered by
 * terminal/modal-product.phtml.
 *
 * Item 34 (all product types + options): Alpine.store('posOptions') backs
 * terminal/modal-options.phtml. Tapping a configurable/grouped/bundle card
 * (or any product flagged has_options) fetches GET pos/catalog/options?id=
 * and opens the options modal; the cashier's selections are mapped onto the
 * pinned buyRequest contract and POSTed straight to pos/cart/add:
 *   { quote_id, product_id, qty, options: {
 *       super_attribute?:   { "<attribute_id>": <option_value_id> },
 *       super_group?:       { "<associated_product_id>": <qty> },
 *       bundle_option?:     { "<option_id>": <selection_id>|[<selection_id>] },
 *       bundle_option_qty?: { "<option_id>": <qty> },
 *       custom_options?:    { "<option_id>": <value|[values]|{date...}> } } }
 * The modal's running price is a client-side DISPLAY ONLY - the server
 * builds the real buyRequest, lets Magento resolve children / validate
 * required options / apply tier+special+catalog/cart rules+group+tax, and
 * the returned §7 cart payload (dispatched as `pos:cart-updated`) is the
 * single pricing authority.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------ helpers */

    function store() {
        return (window.Alpine && typeof window.Alpine.store === 'function')
            ? window.Alpine.store('pos')
            : null;
    }

    function bootConfig() {
        if (bootConfig._cache) {
            return bootConfig._cache;
        }
        var cfg = {};
        try {
            var el = document.getElementById('pos-config');
            if (el) {
                cfg = JSON.parse(el.textContent || '{}') || {};
            }
        } catch (e) {
            cfg = {};
        }
        bootConfig._cache = cfg;
        return cfg;
    }

    function currencySymbol() {
        var s = store() || {};
        var cfg = bootConfig();
        return (s.config && s.config.currency_symbol)
            || (s.state && s.state.config && s.state.config.currency_symbol)
            || cfg.currency_symbol
            || (cfg.config && cfg.config.currency_symbol)
            || '$';
    }

    function money(value) {
        var n = Number(value || 0);
        if (!isFinite(n)) {
            n = 0;
        }
        var sign = n < 0 ? '-' : '';
        return sign + currencySymbol() + Math.abs(n).toFixed(2);
    }

    function notify(message, type) {
        var s = store();
        if (s && typeof s.notify === 'function') {
            s.notify(message, type || 'info');
            return;
        }
        if (type === 'error') {
            // eslint-disable-next-line no-console
            console.error('[POS] ' + message);
        }
    }

    function t(key, fallback) {
        if (window.Pos && typeof window.Pos.t === 'function') {
            var v = window.Pos.t(key);
            if (v && v !== key) {
                return v;
            }
        }
        return fallback || key;
    }

    function emit(name, detail) {
        window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
    }

    function errMessage(e, fallback) {
        return (e && e.message) ? e.message : (fallback || t('pos_error', 'Something went wrong.'));
    }

    window.PosUi = window.PosUi || {};
    if (!window.PosUi.money) { window.PosUi.money = money; }
    if (!window.PosUi.notify) { window.PosUi.notify = notify; }
    if (!window.PosUi.t) { window.PosUi.t = t; }
    if (!window.PosUi.emit) { window.PosUi.emit = emit; }
    if (!window.PosUi.bootConfig) { window.PosUi.bootConfig = bootConfig; }
    if (!window.PosUi.currencySymbol) { window.PosUi.currencySymbol = currencySymbol; }

    function dialogOpen() {
        var s = store();
        var nodes;
        var i;
        var key;

        if (s && s.view && s.view !== 'sale') {
            return true;
        }
        if (s && s.modals) {
            for (key in s.modals) {
                if (Object.prototype.hasOwnProperty.call(s.modals, key) && s.modals[key]) {
                    return true;
                }
            }
        }
        nodes = document.querySelectorAll('[role="dialog"], [role="alertdialog"], [aria-modal="true"], dialog[open]');
        for (i = 0; i < nodes.length; i++) {
            if (nodes[i].getClientRects().length > 0) {
                return true;
            }
        }
        return false;
    }

    function offlineActive() {
        return !!(window.PosOffline
            && typeof window.PosOffline.isOffline === 'function'
            && window.PosOffline.isOffline());
    }

    /**
     * Abortable GET for the paged grid endpoints (search / products /
     * bestsellers) - same base URL, headers and envelope unwrapping as
     * window.PosApi.get, plus an AbortSignal so a superseded fetch (new
     * keystroke / chip tap) is cancelled instead of racing the fresh one
     * (contract item 29). Aborts reject with {code:'aborted'}.
     */
    function pagedGet(path, params, signal) {
        if (!window.PosApi || typeof window.PosApi.url !== 'function' || typeof window.fetch !== 'function') {
            return window.PosApi.get(path, params);
        }
        return fetch(window.PosApi.url(path, params), {
            method: 'GET',
            credentials: 'same-origin',
            signal: signal || undefined,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.json().catch(function () {
                throw { message: t('invalid_response', 'Invalid server response.'), code: 'invalid_response', status: response.status };
            }).then(function (json) {
                if (json && json.success === true) {
                    return json.data;
                }
                if (json && json.code === 'unauthorized' && typeof window.PosApi._authLost === 'function') {
                    throw window.PosApi._authLost(json, response.status);
                }
                throw {
                    message: (json && json.message) ? json.message : t('error', 'Something went wrong. Please try again.'),
                    code: (json && json.code) ? json.code : null,
                    status: response.status
                };
            });
        }, function (err) {
            if (err && err.name === 'AbortError') {
                throw { message: 'aborted', code: 'aborted', status: 0 };
            }
            throw { message: t('network_error', 'Network error - check your connection.'), code: 'network', status: 0 };
        });
    }

    /**
     * Normalize a paged catalog response (contract item 28). The backend now
     * returns {items, total_count, page, page_size}; a bare array (older
     * backend / offline snapshot rows) still works - items stay reachable.
     */
    function normalizePage(data, fallbackPage) {
        if (Array.isArray(data)) {
            return { items: data, total_count: null, page: fallbackPage, page_size: data.length };
        }
        data = data || {};
        var items = Array.isArray(data.items) ? data.items : [];
        var total = Number(data.total_count);
        return {
            items: items,
            total_count: (data.total_count != null && isFinite(total)) ? total : null,
            page: Number(data.page) || fallbackPage,
            page_size: Number(data.page_size) || items.length
        };
    }

    /**
     * Product types the terminal can add with just sku + qty. The backend
     * (CatalogService) already excludes composite parents (configurable/
     * bundle/grouped - they only yield "You need to choose options for your
     * item." on pos/cart/add), but a stale IndexedDB offline snapshot may
     * still contain such rows, so offline results and add-to-cart are
     * guarded here too.
     */
    var SELLABLE_TYPES = ['simple', 'virtual', 'downloadable'];

    function isSellableType(type) {
        return !type || SELLABLE_TYPES.indexOf(String(type)) !== -1;
    }

    function sellableOnly(rows) {
        return (rows || []).filter(function (r) {
            return r && isSellableType(r.type);
        });
    }

    /** Composite parents always need a buyRequest with options (item 34). */
    var COMPOSITE_TYPES = ['configurable', 'grouped', 'bundle'];

    function isCompositeType(type) {
        return COMPOSITE_TYPES.indexOf(String(type || '')) !== -1;
    }

    /** Loose boolean flag: true/1/"1" are set, false/0/"0"/null are not. */
    function isFlagSet(v) {
        if (v === true) {
            return true;
        }
        if (v === false || v === undefined || v === null) {
            return false;
        }
        var n = Number(v);
        return isFinite(n) ? n > 0 : String(v).toLowerCase() === 'true';
    }

    /**
     * Item 34: products that cannot be added with a bare product_id+qty -
     * composite parents and any row the backend flags as carrying custom
     * options. These route through the options modal instead of one-tap add.
     */
    function needsOptions(p) {
        if (!p) {
            return false;
        }
        if (isCompositeType(p.type)) {
            return true;
        }
        return isFlagSet(p.has_options) || isFlagSet(p.required_options);
    }

    /** Open the item-34 options modal for a product id. */
    function openOptionsModal(productId) {
        var st = (window.Alpine && typeof window.Alpine.store === 'function')
            ? window.Alpine.store('posOptions')
            : null;
        if (productId && st && typeof st.show === 'function') {
            st.show(productId);
        }
    }

    /**
     * Deterministic 8-color ramp for category chips and quick-key tiles:
     * the entity id modulo 8 picks the hue, so a chip/tile keeps the same
     * color across reloads and pagination (pos.css pastel-mixes the value
     * onto the surface via --tile-color).
     */
    var TILE_PALETTE = ['#4f6df5', '#0ea5a4', '#e0793c', '#9657d6', '#d6526f', '#3c8f4e', '#b08f26', '#5577a0'];

    function tileColorById(id) {
        var n = Math.abs(Number(id) || 0);
        return TILE_PALETTE[n % TILE_PALETTE.length];
    }

    /**
     * Module-shared, non-reactive state: the attached customer's group id
     * and the active quote id (both kept in sync by posCatalog.onCartUpdated
     * from the `pos:cart-updated` payloads cart.js/customer.js dispatch) so
     * the quick-view and options modals can fetch group-aware pricing and
     * POST option adds against the active cart without owning listeners.
     */
    var shared = { groupId: 0, quoteId: null };

    /**
     * Active quote id for the direct options add (item 34): last seen via
     * pos:cart-updated, then cart.js's persisted active-tab key, then a
     * freshly created cart as a last resort (same fallback order customer.js
     * uses when attaching a customer).
     */
    function resolveQuoteId() {
        if (shared.quoteId) {
            return Promise.resolve(shared.quoteId);
        }
        var stored = 0;
        try {
            stored = Number(localStorage.getItem('panth_pos_cart_active') || 0);
        } catch (e) {
            stored = 0;
        }
        if (stored) {
            shared.quoteId = stored;
            return Promise.resolve(stored);
        }
        return window.PosApi.post('cart/create', {}).then(function (data) {
            var quoteId = (typeof data === 'number')
                ? data
                : (data && (data.quote_id || data.id));
            if (!quoteId) {
                throw { message: t('cart_start_failed', 'Could not start a cart.') };
            }
            shared.quoteId = Number(quoteId);
            emit('pos:cart-created', { quote_id: shared.quoteId });
            return shared.quoteId;
        });
    }

    /** Whether the signed-in cashier may pin/unpin quick keys (item 20). */
    function canEditLayout() {
        var s = store();
        return !!(s && typeof s.hasPermission === 'function' && s.hasPermission('can_edit_layout'));
    }

    /* ---------------------------------------------------------- posCatalog */

    window.posCatalog = function () {
        return {
            query: '',
            products: [],
            categories: [],
            categoryId: null,
            categoryName: '',
            parentId: null,
            subChips: [],
            page: 1,
            busy: false,
            done: false,
            mode: 'idle', // idle | top | search | category
            seq: 0,
            // Pagination bookkeeping (contract item 28): server-reported total
            // for the current list, pages appended so far, and the ids already
            // rendered so no duplicate ever slips in across pages.
            totalCount: null,
            pagesLoaded: 0,
            _seenIds: {},
            _abort: null,
            // Customer group for group-aware catalog pricing. Adopted from the
            // attached customer via pos:cart-updated; 0 = NOT-LOGGED-IN / guest
            // default group (matches storefront browse pricing).
            customerGroupId: 0,
            // Set true once the genuinely-empty category empty-state is shown,
            // so the template can offer "Show top sellers instead".
            emptyCategory: false,
            // productId -> quick_key_id map (from 'pos:quickkeys-data') so each
            // card's pin affordance reflects its current pinned state.
            pinnedIds: {},
            pinBusy: false,
            // Per contract: show Scan only when camera capture OR a barcode
            // detector exists; failures degrade to a friendly info toast.
            cameraSupported: !!((navigator.mediaDevices && navigator.mediaDevices.getUserMedia)
                || typeof window.BarcodeDetector === 'function'),
            cameraOpen: false,
            _cameraStream: null,
            _scanTimer: null,
            _detector: null,
            _bufTimer: null,
            _wedgeHandler: null,
            _authHandler: null,
            _cartHandler: null,
            _quickkeysHandler: null,

            init: function () {
                var self = this;
                this.loadCategories();
                // Default view is never an empty void: show top sellers up
                // front. The pre-auth fetch is unauthorized (empty) and is
                // re-run on pos:authenticated below.
                this.showTopSellers();
                this._wedgeHandler = function (e) { self.captureWedge(e); };
                document.addEventListener('keydown', this._wedgeHandler);
                // The component initialises before login, so the first
                // fetches are unauthorized and come back empty - reload once
                // the operator is signed in (and on PIN unlock).
                this._authHandler = function () { self.onAuthenticated(); };
                window.addEventListener('pos:authenticated', this._authHandler);
                // Adopt the attached customer's group so prices refresh
                // group-aware when a customer is attached / switched.
                this._cartHandler = function (e) { self.onCartUpdated(e.detail || {}); };
                window.addEventListener('pos:cart-updated', this._cartHandler);
                // Pin state for product cards: posQuickKeys broadcasts the
                // productId->quickKeyId map after every tiles load.
                this._quickkeysHandler = function (e) {
                    self.pinnedIds = (e.detail && e.detail.map) ? e.detail.map : {};
                };
                window.addEventListener('pos:quickkeys-data', this._quickkeysHandler);
            },

            destroy: function () {
                if (this._wedgeHandler) {
                    document.removeEventListener('keydown', this._wedgeHandler);
                }
                if (this._authHandler) {
                    window.removeEventListener('pos:authenticated', this._authHandler);
                }
                if (this._cartHandler) {
                    window.removeEventListener('pos:cart-updated', this._cartHandler);
                }
                if (this._quickkeysHandler) {
                    window.removeEventListener('pos:quickkeys-data', this._quickkeysHandler);
                }
                this._abortInflight();
                this.closeCamera();
            },

            onAuthenticated: function () {
                this.loadCategories();
                this.reloadCurrent();
            },

            /**
             * Re-run whatever grid is currently shown (after auth, or after the
             * customer group changed) so prices/salability refresh.
             */
            reloadCurrent: function () {
                if (this.mode === 'search' && (this.query || '').trim() !== '') {
                    this.searchNow();
                } else if (this.mode === 'category' && this.categoryId) {
                    this.resetList('category');
                    this.fetchMore();
                } else {
                    this.showTopSellers();
                }
            },

            /**
             * Pick up the attached customer's group id from the cart payload
             * and, when it changes, re-price the visible grid.
             */
            onCartUpdated: function (detail) {
                var cart = detail && detail.cart ? detail.cart : detail;
                if (cart && cart.quote_id) {
                    // Track the active quote so the options modal (item 34)
                    // can POST cart/add against the cart actually on screen.
                    shared.quoteId = Number(cart.quote_id) || shared.quoteId;
                }
                var group = cart && cart.customer && cart.customer.group != null
                    ? Number(cart.customer.group)
                    : 0;
                if (!isFinite(group) || group < 0) {
                    group = 0;
                }
                if (group !== this.customerGroupId) {
                    this.customerGroupId = group;
                    shared.groupId = group;
                    this.reloadCurrent();
                }
            },

            money: function (v) { return money(v); },
            t: function (k, f) { return t(k, f); },

            /** Item 34: card needs the options modal ("From <price>" + hint). */
            needsOptions: function (p) { return needsOptions(p); },

            /** Deterministic chip color (id modulo 8) for --tile-color. */
            chipColor: function (id) { return tileColorById(id); },

            /**
             * Section label shown above the product grid so the panel always
             * announces what it is showing (never a bare void): Top sellers /
             * the category name / Search results.
             */
            gridLabel: function () {
                var label = '';
                if (this.mode === 'top') {
                    return t('top_sellers', 'Top sellers');
                }
                if (this.mode === 'category') {
                    label = this.categoryName || t('category', 'Category');
                } else if (this.mode === 'search') {
                    label = t('search_results', 'Search results');
                }
                // Announce the server total next to the label (item 28) so the
                // cashier knows how deep a search/category runs.
                if (label !== '' && this.totalCount != null && this.totalCount > 0) {
                    return label + ' · ' + this.totalCount;
                }
                return label;
            },

            /* ------------------------------------------------- pagination */

            /** Cancel the in-flight grid fetch (superseded by a newer one). */
            _abortInflight: function () {
                if (this._abort) {
                    try { this._abort.abort(); } catch (e) { /* noop */ }
                    this._abort = null;
                }
            },

            /** "Load more" fallback button visibility (contract item 28). */
            canLoadMore: function () {
                return !this.busy && !this.done && this.mode !== 'idle' && this.products.length > 0;
            },

            /** How many more rows the server still holds (null = unknown). */
            remainingCount: function () {
                if (this.totalCount == null) {
                    return null;
                }
                return Math.max(0, this.totalCount - this.products.length);
            },

            loadMoreLabel: function () {
                var label = t('load_more', 'Load more');
                var remaining = this.remainingCount();
                return (remaining != null && remaining > 0) ? (label + ' · ' + remaining) : label;
            },

            /**
             * End-of-results marker: only after the list actually paged (a
             * single short page visibly ends on its own).
             */
            showEnd: function () {
                return this.done && this.products.length > 0 && this.pagesLoaded > 1;
            },

            endLabel: function () {
                return t('end_of_results', 'That is everything') + ' · '
                    + this.products.length + ' ' + t('products', 'products');
            },

            /* -------------------------------------------------- categories */

            loadCategories: function () {
                var self = this;
                window.PosApi.get('catalog/categories')
                    .then(function (tree) { self.categories = tree || []; })
                    .catch(function () { self.categories = []; });
            },

            /**
             * Top-level chip: select the parent, reveal its sub-chips and load
             * the parent's products (descendants included server-side).
             * Sub chip (isSub=true): keep the sibling sub-chip row visible.
             */
            selectCategory: function (cat, isSub) {
                this.query = '';
                if (!cat) {
                    this.categoryId = null;
                    this.categoryName = '';
                    this.parentId = null;
                    this.subChips = [];
                    // "All" with no query is the default Top Sellers view -
                    // never an empty idle void (contract item 10).
                    this.showTopSellers();
                    return;
                }
                this.categoryId = Number(cat.id);
                this.categoryName = cat.name || '';
                if (!isSub) {
                    this.parentId = this.categoryId;
                    this.subChips = (cat.children && cat.children.length) ? cat.children : [];
                }
                this.resetList('category');
                this.fetchMore();
            },

            /** "All" chip - back to the default Top Sellers view. */
            clearCategory: function () {
                this.selectCategory(null);
            },

            /* --------------------------------------------------- top sellers */

            /**
             * Show the store's top sellers as the default grid - paged like
             * search/category (contract item 28). Used on init, on the "All"
             * chip with an empty query, and from the "Show top sellers
             * instead" action on an empty category.
             */
            showTopSellers: function () {
                this.query = '';
                this.categoryId = null;
                this.categoryName = '';
                this.parentId = null;
                this.subChips = [];
                this.resetList('top');
                this.fetchMore();
            },

            /* ---------------------------------------------- barcode wedge */

            captureWedge: function (e) {
                if (this.cameraOpen || e.defaultPrevented || dialogOpen()) {
                    return;
                }
                var tgt = e.target;
                var typing = tgt && (
                    tgt.tagName === 'INPUT' || tgt.tagName === 'TEXTAREA'
                    || tgt.tagName === 'SELECT' || tgt.isContentEditable
                );
                if (typing) {
                    return;
                }
                if (e.key === '/' && this.$refs.searchInput) {
                    e.preventDefault();
                    this.$refs.searchInput.focus();
                    return;
                }
                // Route stray printable keys (keyboard-wedge scanners type into
                // "nothing") onto the hidden buffer so the scan is not lost.
                if (e.key && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    if (this.$refs.barcodeBuffer) {
                        this.$refs.barcodeBuffer.focus();
                    }
                }
            },

            onBufferInput: function () {
                var self = this;
                clearTimeout(this._bufTimer);
                if (dialogOpen()) {
                    if (this.$refs.barcodeBuffer) {
                        this.$refs.barcodeBuffer.value = '';
                    }
                    return;
                }
                // Wedge scanners finish with Enter almost instantly; a human
                // typing into the hidden field falls through to normal search.
                this._bufTimer = setTimeout(function () {
                    var el = self.$refs.barcodeBuffer;
                    var v = el ? (el.value || '').trim() : '';
                    if (el) {
                        el.value = '';
                    }
                    if (v) {
                        self.query = v;
                        self.searchNow();
                        if (self.$refs.searchInput) {
                            self.$refs.searchInput.focus();
                        }
                    }
                }, 400);
            },

            submitBuffer: function () {
                clearTimeout(this._bufTimer);
                var el = this.$refs.barcodeBuffer;
                if (!el) {
                    return;
                }
                if (dialogOpen()) {
                    el.value = '';
                    return;
                }
                var code = (el.value || '').trim();
                el.value = '';
                if (code) {
                    this.scan(code);
                }
            },

            scan: function (code) {
                var self = this;
                var lookup;
                if (offlineActive() && window.PosOffline.searchLocal) {
                    lookup = Promise.resolve(window.PosOffline.searchLocal(code)).then(function (rows) {
                        rows = sellableOnly(rows);
                        var exact = rows.filter(function (r) {
                            return r && (r.barcode === code || r.sku === code);
                        });
                        return exact.length ? exact[0] : (rows.length === 1 ? rows[0] : null);
                    });
                } else {
                    lookup = window.PosApi.get('catalog/barcode', { code: code });
                }
                lookup.then(function (product) {
                    if (product && (product.id || product.product_id)) {
                        self.addToCart(product);
                    } else {
                        self.query = code;
                        self.searchNow();
                        notify(t('barcode_not_found', 'No product found for barcode') + ' "' + code + '"', 'error');
                    }
                }).catch(function (e) {
                    notify(errMessage(e, t('scan_failed', 'Barcode lookup failed.')), 'error');
                });
            },

            /* --------------------------------------------------- searching */

            searchEnter: function () {
                var self = this;
                var code = (this.query || '').trim();
                var lookup;
                if (code === '' || /\s/.test(code)) {
                    this.searchNow();
                    return;
                }
                if (offlineActive() && window.PosOffline.searchLocal) {
                    lookup = Promise.resolve(window.PosOffline.searchLocal(code)).then(function (rows) {
                        var exact = sellableOnly(rows).filter(function (r) {
                            return r && (r.barcode === code || r.sku === code);
                        });
                        return exact.length ? exact[0] : null;
                    });
                } else {
                    lookup = window.PosApi.get('catalog/barcode', { code: code });
                }
                lookup.then(function (product) {
                    if (product && (product.id || product.product_id)) {
                        self.addToCart(product);
                        self.query = '';
                        self.searchNow();
                    } else {
                        self.searchNow();
                    }
                }).catch(function () {
                    self.searchNow();
                });
            },

            searchNow: function () {
                var q = (this.query || '').trim();
                if (q === '') {
                    // Clearing the query falls back to the active category, or
                    // to the default Top Sellers view (never an empty void).
                    if (this.categoryId) {
                        this.resetList('category');
                        this.fetchMore();
                    } else {
                        this.showTopSellers();
                    }
                    return;
                }
                this.resetList('search');
                this.fetchMore();
            },

            clearSearch: function () {
                this.query = '';
                this.searchNow();
            },

            resetList: function (mode) {
                this._abortInflight();
                this.mode = mode;
                this.products = [];
                this.page = 1;
                this.busy = false;
                this.done = (mode === 'idle');
                this.emptyCategory = false;
                this.totalCount = null;
                this.pagesLoaded = 0;
                this._seenIds = {};
                this.seq++;
            },

            /**
             * Load the next page for the active mode - search / category /
             * top sellers all page identically (contract item 28): the panel
             * body's infinite scroll and the "Load more" fallback both land
             * here, duplicates are dropped by id, and the list stops at the
             * server-reported true end. 'idle' is inert.
             */
            fetchMore: function () {
                if (this.busy || this.done || this.mode === 'idle') {
                    return;
                }
                var self = this;
                var seq = this.seq;
                this.busy = true;

                var controller = (typeof window.AbortController === 'function') ? new window.AbortController() : null;
                this._abort = controller;
                var signal = controller ? controller.signal : null;
                var requestPage = this.page;

                var req;
                if (offlineActive() && window.PosOffline.searchLocal && this.mode !== 'category') {
                    // Offline: the local snapshot is a single page (empty
                    // query = browse rows for the Top Sellers slot).
                    req = Promise.resolve(window.PosOffline.searchLocal(this.mode === 'search' ? this.query : ''))
                        .then(function (rows) {
                            rows = sellableOnly(rows);
                            return { items: rows, total_count: null, page: 1, page_size: 0, single: true };
                        });
                } else if (this.mode === 'search') {
                    req = pagedGet('catalog/search', {
                        q: this.query,
                        page: requestPage,
                        group_id: this.customerGroupId
                    }, signal).then(function (data) { return normalizePage(data, requestPage); });
                } else if (this.mode === 'top') {
                    req = pagedGet('catalog/bestsellers', {
                        page: requestPage,
                        group_id: this.customerGroupId
                    }, signal).then(function (data) { return normalizePage(data, requestPage); });
                } else {
                    req = pagedGet('catalog/products', {
                        category_id: this.categoryId,
                        page: requestPage,
                        group_id: this.customerGroupId
                    }, signal).then(function (data) { return normalizePage(data, requestPage); });
                }

                req.then(function (data) {
                    if (seq !== self.seq) {
                        return;
                    }
                    if (data.total_count != null) {
                        self.totalCount = data.total_count;
                    }

                    // Drop anything already rendered - no duplicates across
                    // pages, and a pure-duplicate page can never loop forever.
                    var fresh = [];
                    (data.items || []).forEach(function (p) {
                        var id = Number(p && p.id) || 0;
                        if (id && self._seenIds[id]) {
                            return;
                        }
                        if (id) {
                            self._seenIds[id] = true;
                        }
                        fresh.push(p);
                    });
                    self.pagesLoaded++;
                    if (fresh.length) {
                        self.products = self.products.concat(fresh);
                    }

                    var pageSize = data.page_size || fresh.length;
                    var atEnd = !!data.single
                        || data.items.length === 0
                        || fresh.length === 0
                        || (self.totalCount != null && self.products.length >= self.totalCount)
                        || data.items.length < pageSize;
                    if (atEnd) {
                        self.done = true;
                        // First page of a category came back empty -> it is
                        // genuinely empty; offer the top-sellers escape hatch.
                        if (self.mode === 'category' && self.products.length === 0) {
                            self.emptyCategory = true;
                        }
                    } else {
                        self.page = requestPage + 1;
                    }
                }).catch(function (e) {
                    if (e && e.code === 'aborted') {
                        return; // superseded by a newer fetch - say nothing
                    }
                    if (seq === self.seq) {
                        self.done = true;
                        // The default Top Sellers fetch also runs pre-auth
                        // (unauthorized) - never toast for it.
                        if (self.mode !== 'top') {
                            notify(errMessage(e, t('search_failed', 'Product search failed.')), 'error');
                        }
                    }
                }).finally(function () {
                    if (seq === self.seq) {
                        self.busy = false;
                    }
                    if (self._abort === controller) {
                        self._abort = null;
                    }
                });
            },

            onScroll: function (ev) {
                var el = ev.target;
                if (!el || this.busy || this.done) {
                    return;
                }
                if (el.scrollHeight - el.scrollTop - el.clientHeight < 240) {
                    this.fetchMore();
                }
            },

            addToCart: function (p) {
                // Item 34: configurable/grouped/bundle parents and rows with
                // custom options open the options modal - the server resolves
                // children, validates required options and prices the line.
                // Checked BEFORE the stock guard: a grouped/bundle parent has
                // no own stock and availability lives on its children.
                if (needsOptions(p)) {
                    if (offlineActive()) {
                        notify(t('options_need_connection', 'Products with options need a connection.'), 'info');
                        return;
                    }
                    openOptionsModal(Number(p.id || p.product_id) || 0);
                    return;
                }
                if (p && !isSellableType(p.type)) {
                    // Unknown non-simple type from a stale offline row only -
                    // live results never include these.
                    notify(t('product_needs_options', 'This product has options - add one of its variants instead.'), 'info');
                    return;
                }
                // Salability comes from MSI (or legacy stock) for the register;
                // an explicit false blocks the add with a friendly toast.
                if (p && p.in_stock === false) {
                    notify(t('product_out_of_stock', 'This product is out of stock.'), 'error');
                    return;
                }
                emit('pos:add-product', {
                    product_id: Number(p.id || p.product_id) || null,
                    sku: p.sku || null,
                    qty: 1,
                    name: p.name || '',
                    price: Number(p.price || 0),
                    image: p.image || null
                });
            },

            /* ------------------------- quick keys pin + quick view (20/23) */

            /** Pin affordance visibility - mirrors the server-side gate. */
            canPin: function () {
                return canEditLayout();
            },

            isPinned: function (p) {
                var id = Number(p && (p.id || p.product_id)) || 0;
                return !!(id && this.pinnedIds[id]);
            },

            /**
             * Star button on a card: pin the product as a quick key for the
             * current register, or unpin when already pinned. The quick keys
             * panel reloads via 'pos:quickkeys-changed' and re-broadcasts the
             * pin map, which refreshes the card state.
             */
            togglePin: function (p) {
                var self = this;
                var id = Number(p && (p.id || p.product_id)) || 0;
                if (!id || this.pinBusy) {
                    return;
                }
                this.pinBusy = true;
                var quickKeyId = this.pinnedIds[id];
                var req = quickKeyId
                    ? window.PosApi.post('catalog/quickkeyRemove', { quick_key_id: quickKeyId })
                    : window.PosApi.post('catalog/quickkeySave', { product_id: id });
                req.then(function () {
                    notify(
                        quickKeyId
                            ? t('quickkey_removed', 'Removed from Quick Keys.')
                            : t('quickkey_pinned', 'Pinned to Quick Keys.'),
                        'success'
                    );
                    emit('pos:quickkeys-changed');
                }).catch(function (e) {
                    notify(errMessage(e, t('quickkey_failed', 'Could not update Quick Keys.')), 'error');
                }).finally(function () {
                    self.pinBusy = false;
                });
            },

            /** Info affordance on a card: open the product quick-view modal. */
            openDetail: function (p) {
                var id = Number(p && (p.id || p.product_id)) || 0;
                var detail = (window.Alpine && typeof window.Alpine.store === 'function')
                    ? window.Alpine.store('posProduct')
                    : null;
                if (id && detail && typeof detail.show === 'function') {
                    detail.show(id);
                }
            },

            /* ------------------------------------------------ camera scan */

            openCamera: function () {
                if (!this.cameraSupported || this.cameraOpen) {
                    return;
                }
                var self = this;
                if (typeof window.BarcodeDetector !== 'function') {
                    this.cameraSupported = false;
                    notify(t('camera_unsupported', 'Camera scanning is not supported in this browser - use the hardware scanner.'), 'info');
                    return;
                }
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    this.cameraSupported = false;
                    notify(t('camera_unavailable', 'Camera unavailable - connect a camera or use the hardware scanner.'), 'info');
                    return;
                }
                try {
                    this._detector = this._detector || new window.BarcodeDetector();
                } catch (e) {
                    this.cameraSupported = false;
                    notify(t('camera_unsupported', 'Camera scanning is not supported in this browser - use the hardware scanner.'), 'info');
                    return;
                }
                this.cameraOpen = true;
                navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' },
                    audio: false
                }).then(function (mediaStream) {
                    if (!self.cameraOpen) {
                        mediaStream.getTracks().forEach(function (tr) { tr.stop(); });
                        return;
                    }
                    self._cameraStream = mediaStream;
                    var video = self.$refs.video;
                    if (video) {
                        video.srcObject = mediaStream;
                        video.play().catch(function () {});
                    }
                    self._scanLoop();
                }).catch(function () {
                    self.closeCamera();
                    notify(t('camera_unavailable', 'Camera unavailable - connect a camera or use the hardware scanner.'), 'info');
                });
            },

            _scanLoop: function () {
                if (!this.cameraOpen || !this._detector) {
                    return;
                }
                var self = this;
                var video = this.$refs.video;
                if (video && video.readyState >= 2) {
                    this._detector.detect(video).then(function (codes) {
                        if (codes && codes.length && codes[0].rawValue) {
                            var value = codes[0].rawValue;
                            self.closeCamera();
                            self.scan(value);
                            return;
                        }
                        self._scanTimer = setTimeout(function () { self._scanLoop(); }, 200);
                    }).catch(function () {
                        self._scanTimer = setTimeout(function () { self._scanLoop(); }, 400);
                    });
                } else {
                    this._scanTimer = setTimeout(function () { self._scanLoop(); }, 200);
                }
            },

            closeCamera: function () {
                this.cameraOpen = false;
                clearTimeout(this._scanTimer);
                if (this._cameraStream) {
                    this._cameraStream.getTracks().forEach(function (tr) { tr.stop(); });
                    this._cameraStream = null;
                }
                var video = this.$refs ? this.$refs.video : null;
                if (video) {
                    video.srcObject = null;
                }
            }
        };
    };

    /* -------------------------------------------------------- posQuickKeys */

    window.posQuickKeys = function () {
        return {
            tiles: [],
            page: 1,
            busy: false,
            // Edit mode (item 20): reveals remove + reorder chrome on tiles.
            editing: false,
            _saving: false,
            palette: TILE_PALETTE,

            init: function () {
                var self = this;
                this.load();
                window.addEventListener('pos:quickkeys-changed', function () { self.load(); });
                // First load happens pre-auth (unauthorized -> empty);
                // refetch once the operator signs in.
                window.addEventListener('pos:authenticated', function () { self.load(); });
            },

            money: function (v) { return money(v); },
            t: function (k, f) { return t(k, f); },

            load: function () {
                var self = this;
                this.busy = true;
                window.PosApi.get('catalog/quickkeys')
                    .then(function (tiles) { self.tiles = tiles || []; })
                    .catch(function () { self.tiles = []; })
                    .finally(function () {
                        self.busy = false;
                        if (self.page > self.pageCount()) {
                            self.page = 1;
                        }
                        if (self.tiles.length === 0) {
                            self.editing = false;
                        }
                        self.broadcastPinned();
                    });
            },

            /**
             * Broadcast the productId->quickKeyId map so product cards (and the
             * search grid) can render their pin state (item 20).
             */
            broadcastPinned: function () {
                var map = {};
                this.tiles.forEach(function (tile) {
                    var productId = Number(tile.product_id) || 0;
                    if (productId) {
                        map[productId] = Number(tile.quick_key_id) || 0;
                    }
                });
                emit('pos:quickkeys-data', { map: map });
            },

            /** Edit chrome is gated like the save endpoint: can_edit_layout. */
            canEdit: function () {
                return canEditLayout();
            },

            toggleEdit: function () {
                this.editing = !this.editing;
            },

            /** Unpin a tile (pi-trash in edit mode). Optimistic removal. */
            removeTile: function (tile) {
                var self = this;
                if (this._saving || !tile || !tile.quick_key_id) {
                    return;
                }
                this._saving = true;
                window.PosApi.post('catalog/quickkeyRemove', { quick_key_id: tile.quick_key_id })
                    .then(function () {
                        self.tiles = self.tiles.filter(function (row) {
                            return row.quick_key_id !== tile.quick_key_id;
                        });
                        if (self.page > self.pageCount()) {
                            self.page = self.pageCount();
                        }
                        notify(t('quickkey_removed', 'Removed from Quick Keys.'), 'success');
                        self.broadcastPinned();
                    })
                    .catch(function (e) {
                        notify(errMessage(e, t('quickkey_failed', 'Could not update Quick Keys.')), 'error');
                    })
                    .finally(function () {
                        self._saving = false;
                    });
            },

            /**
             * Reorder within the current page (◂ ▸ in edit mode): move the
             * tile one slot, renumber the page densely, persist the changed
             * positions and reload on failure so the UI never lies.
             */
            moveTile: function (tile, dir) {
                var self = this;
                if (this._saving) {
                    return;
                }
                var list = this.pageTiles();
                var idx = list.indexOf(tile);
                var to = idx + (dir < 0 ? -1 : 1);
                if (idx < 0 || to < 0 || to >= list.length) {
                    return;
                }
                list.splice(idx, 1);
                list.splice(to, 0, tile);

                var updates = [];
                list.forEach(function (row, i) {
                    if (Number(row.position) !== i) {
                        row.position = i; // optimistic - pageTiles() re-sorts
                        updates.push(window.PosApi.post('catalog/quickkeySave', {
                            quick_key_id: row.quick_key_id,
                            position: i
                        }));
                    }
                });
                if (!updates.length) {
                    return;
                }
                this._saving = true;
                Promise.all(updates)
                    .catch(function (e) {
                        notify(errMessage(e, t('quickkey_failed', 'Could not update Quick Keys.')), 'error');
                        self.load();
                    })
                    .finally(function () {
                        self._saving = false;
                    });
            },

            pageCount: function () {
                var max = 1;
                this.tiles.forEach(function (tile) {
                    max = Math.max(max, Number(tile.page || 1));
                });
                return max;
            },

            pages: function () {
                var list = [];
                for (var i = 1; i <= this.pageCount(); i++) {
                    list.push(i);
                }
                return list;
            },

            pageTiles: function () {
                var page = this.page;
                return this.tiles
                    .filter(function (tile) { return Number(tile.page || 1) === page; })
                    .sort(function (a, b) { return Number(a.position || 0) - Number(b.position || 0); });
            },

            /**
             * Merchant-set color wins; otherwise deterministic ramp by id
             * (id modulo 8) so a tile keeps its color across pages/reloads.
             * The idx argument is accepted for template back-compat only.
             */
            tileColor: function (tile, idx) { // eslint-disable-line no-unused-vars
                if (tile && tile.color) {
                    return tile.color;
                }
                var id = tile ? (tile.product_id || tile.quick_key_id || 0) : 0;
                return tileColorById(id);
            },

            prevPage: function () {
                this.page = this.page > 1 ? this.page - 1 : this.pageCount();
            },

            nextPage: function () {
                this.page = this.page < this.pageCount() ? this.page + 1 : 1;
            },

            tap: function (tile) {
                if (this.editing) {
                    // Edit mode taps are for the remove/reorder chrome only.
                    return;
                }
                // Item 34: a pinned composite / custom-option product routes
                // through the options modal (tiles without a type stay on the
                // one-tap path - exactly the current behaviour).
                if (needsOptions(tile)) {
                    if (offlineActive()) {
                        notify(t('options_need_connection', 'Products with options need a connection.'), 'info');
                        return;
                    }
                    openOptionsModal(Number(tile.product_id) || 0);
                    return;
                }
                emit('pos:add-product', {
                    product_id: Number(tile.product_id) || null,
                    sku: tile.sku || null,
                    qty: 1,
                    name: tile.name || tile.label || '',
                    price: Number(tile.price || 0),
                    image: tile.image || null
                });
            }
        };
    };

    /* ----------------------------------------- posProduct (quick view, 23) */

    /**
     * Alpine store backing terminal/modal-product.phtml: fetches the full
     * detail payload (gallery, sanitized descriptions, attributes, salable
     * qty) from GET pos/catalog/product and adds to the active cart with a
     * chosen qty via the same 'pos:add-product' contract as the grid.
     */
    document.addEventListener('alpine:init', function () {
        window.Alpine.store('posProduct', {
            open: false,
            busy: false,
            product: null,
            qty: 1,
            galleryIndex: 0,
            error: '',

            money: function (v) { return money(v); },
            t: function (k, f) { return t(k, f); },

            show: function (productId) {
                var self = this;
                var id = Number(productId) || 0;
                if (!id) {
                    return;
                }
                this.open = true;
                this.busy = true;
                this.product = null;
                this.qty = 1;
                this.galleryIndex = 0;
                this.error = '';
                window.PosApi.get('catalog/product', { id: id, group_id: shared.groupId })
                    .then(function (product) {
                        if (!self.open) {
                            return;
                        }
                        self.product = product || null;
                        if (!self.product) {
                            self.error = t('product_not_found', 'Product not found.');
                        }
                    })
                    .catch(function (e) {
                        if (!self.open) {
                            return;
                        }
                        self.error = errMessage(e, t('product_not_found', 'Product not found.'));
                    })
                    .finally(function () {
                        self.busy = false;
                    });
            },

            close: function () {
                this.open = false;
                this.busy = false;
                this.product = null;
                this.error = '';
            },

            /** All gallery URLs (server caps + falls back to the base image). */
            galleryImages: function () {
                var p = this.product;
                if (!p) {
                    return [];
                }
                if (p.gallery && p.gallery.length) {
                    return p.gallery;
                }
                return p.image ? [p.image] : [];
            },

            /** Currently selected gallery image. */
            image: function () {
                var images = this.galleryImages();
                if (!images.length) {
                    return '';
                }
                return images[Math.min(Math.max(this.galleryIndex, 0), images.length - 1)];
            },

            decQty: function () {
                this.qty = Math.max(1, Math.floor(Number(this.qty) || 1) - 1);
            },

            incQty: function () {
                this.qty = Math.min(999, Math.floor(Number(this.qty) || 1) + 1);
            },

            normalizeQty: function () {
                this.qty = Math.min(999, Math.max(1, Math.floor(Number(this.qty) || 1)));
            },

            /**
             * Item 34: quick view of a composite / custom-option product
             * offers "Add with options" - hand over to the options modal.
             */
            needsOptions: function () {
                return needsOptions(this.product);
            },

            addWithOptions: function () {
                var p = this.product;
                if (!p) {
                    return;
                }
                var id = Number(p.id) || 0;
                this.close();
                if (offlineActive()) {
                    notify(t('options_need_connection', 'Products with options need a connection.'), 'info');
                    return;
                }
                openOptionsModal(id);
            },

            addToCart: function () {
                var p = this.product;
                if (!p || this.busy) {
                    return;
                }
                if (this.needsOptions()) {
                    // Defensive: the footer swaps to "Add with options" for
                    // these, but route correctly even if called directly.
                    this.addWithOptions();
                    return;
                }
                if (p.in_stock === false) {
                    notify(t('product_out_of_stock', 'This product is out of stock.'), 'error');
                    return;
                }
                this.normalizeQty();
                emit('pos:add-product', {
                    product_id: Number(p.id) || null,
                    sku: p.sku || null,
                    qty: this.qty,
                    name: p.name || '',
                    price: Number(p.price || 0),
                    image: p.image || null
                });
                this.close();
            }
        });

        registerOptionsStore();
    });

    /* --------------------------------------- posOptions (item 34) helpers */

    function toNum(v, fallback) {
        var n = Number(v);
        return isFinite(n) ? n : (fallback || 0);
    }

    /** First defined of the listed keys on a row (backend alias tolerance). */
    function pick(row) {
        for (var i = 1; i < arguments.length; i++) {
            var key = arguments[i];
            if (row && row[key] !== undefined && row[key] !== null) {
                return row[key];
            }
        }
        return null;
    }

    /**
     * Normalize GET pos/catalog/options into one defensive shape so the
     * modal renders the same regardless of minor backend key spelling:
     * super_attributes[{attribute_id,label,options[{id,label,color}]}] +
     * variants[{id,sku,price,in_stock,attributes{attrId:valueId}}] /
     * associated[{id,name,sku,price,qty,in_stock}] /
     * bundle_options[{option_id,title,type,required,selections[...]}] /
     * custom_options[{option_id,title,type,required,price,price_type,
     * max_characters,values[{id,title,price,price_type}]}].
     */
    function normalizeOptionsPayload(raw) {
        raw = raw || {};
        // GET pos/catalog/options nests the product row under `product`
        // ({product:{id,sku,name,price,image,in_stock,...}, type,
        // super_attributes, children, associated, bundle_options,
        // custom_options}); the option groups live at the ROOT. Read the
        // product fields from that nested row, falling back to the root so
        // a flat payload still normalises.
        var prod = (raw.product && typeof raw.product === 'object') ? raw.product : raw;
        var p = {
            id: toNum(pick(prod, 'id', 'product_id')),
            sku: prod.sku || '',
            name: prod.name || '',
            type: String(pick(raw, 'type', 'type_id') || pick(prod, 'type', 'type_id') || 'simple'),
            image: prod.image || null,
            price: toNum(prod.price),
            price_type: String(pick(raw, 'price_type', 'sku_type') || pick(prod, 'price_type', 'sku_type') || ''),
            in_stock: prod.in_stock,
            super_attributes: [],
            variants: [],
            associated: [],
            bundle_options: [],
            custom_options: []
        };

        (pick(raw, 'super_attributes', 'configurable_attributes') || []).forEach(function (attr) {
            var options = (pick(attr, 'options', 'values') || []).map(function (opt) {
                var swatch = opt.swatch || null;
                return {
                    id: toNum(pick(opt, 'value_id', 'id', 'value_index', 'value')),
                    label: String(pick(opt, 'label', 'title') || ''),
                    color: pick(opt, 'color', 'swatch_color')
                        || ((swatch && (String(swatch.type) === '1' || swatch.type === 'color')) ? swatch.value : null)
                };
            }).filter(function (opt) { return opt.id > 0; });
            p.super_attributes.push({
                attribute_id: toNum(pick(attr, 'attribute_id', 'id')),
                code: String(pick(attr, 'code', 'attribute_code') || ''),
                label: String(pick(attr, 'label', 'title') || ''),
                options: options
            });
        });

        (pick(raw, 'variants', 'children') || []).forEach(function (child) {
            p.variants.push({
                id: toNum(pick(child, 'id', 'product_id')),
                sku: child.sku || '',
                price: toNum(child.price),
                in_stock: child.in_stock,
                // CatalogService children rows carry the combination map
                // under `super_attribute` ({attribute_id: value_id}).
                attributes: pick(child, 'attributes', 'super_attribute') || {}
            });
        });

        (pick(raw, 'associated', 'associated_products', 'grouped') || []).forEach(function (row) {
            p.associated.push({
                id: toNum(pick(row, 'id', 'product_id')),
                name: row.name || '',
                sku: row.sku || '',
                price: toNum(row.price),
                qty: Math.max(0, Math.floor(toNum(pick(row, 'qty_default', 'qty', 'default_qty')))),
                in_stock: row.in_stock
            });
        });

        (raw.bundle_options || []).forEach(function (opt) {
            var selections = (pick(opt, 'selections', 'products', 'values') || []).map(function (sel) {
                return {
                    selection_id: toNum(pick(sel, 'selection_id', 'id')),
                    product_id: toNum(sel.product_id),
                    name: String(pick(sel, 'name', 'title', 'label') || ''),
                    price: toNum(sel.price),
                    price_type: String(pick(sel, 'price_type') || ''),
                    qty: Math.max(1, Math.floor(toNum(pick(sel, 'qty', 'default_qty'), 1)) || 1),
                    can_change_qty: !!pick(sel, 'can_change_qty', 'can_change_quantity'),
                    is_default: !!pick(sel, 'is_default', 'default'),
                    in_stock: sel.in_stock
                };
            }).filter(function (sel) { return sel.selection_id > 0; });
            p.bundle_options.push({
                option_id: toNum(pick(opt, 'option_id', 'id')),
                title: String(pick(opt, 'title', 'label') || ''),
                type: String(opt.type || 'select'),
                required: !!pick(opt, 'required', 'is_required'),
                selections: selections
            });
        });

        (pick(raw, 'custom_options', 'options') || []).forEach(function (opt) {
            var values = (pick(opt, 'values', 'data') || []).map(function (val) {
                return {
                    id: toNum(pick(val, 'value_id', 'id', 'option_type_id')),
                    title: String(pick(val, 'title', 'label') || ''),
                    price: toNum(val.price),
                    price_type: String(pick(val, 'price_type') || 'fixed')
                };
            }).filter(function (val) { return val.id > 0; });
            p.custom_options.push({
                option_id: toNum(pick(opt, 'option_id', 'id')),
                title: String(pick(opt, 'title', 'label') || ''),
                type: String(opt.type || 'field'),
                required: !!pick(opt, 'required', 'is_require', 'is_required'),
                price: toNum(opt.price),
                price_type: String(pick(opt, 'price_type') || 'fixed'),
                max_characters: toNum(opt.max_characters),
                values: values
            });
        });

        return p;
    }

    /** Custom-option types whose chosen value is an array of value ids. */
    function isMultiCustom(type) {
        return type === 'checkbox' || type === 'multiple' || type === 'multi';
    }

    /** Bundle option types where several selections may be checked at once. */
    function isMultiBundle(type) {
        return type === 'checkbox' || type === 'multi' || type === 'multiple';
    }

    /* ------------------------------------------------ posOptions (item 34) */

    /**
     * Alpine store backing terminal/modal-options.phtml: loads the option
     * payload, tracks the cashier's selections per product type, validates
     * required choices, shows a running DISPLAY price from the option/child
     * data, and POSTs the contract-shaped buyRequest to pos/cart/add. The
     * server's returned cart payload (re-dispatched as pos:cart-updated) is
     * the pricing authority - never the client figure.
     */
    function registerOptionsStore() {
        window.Alpine.store('posOptions', {
            open: false,
            busy: false,
            adding: false,
            error: '',
            product: null,
            qty: 1,
            // Selections by type (object maps keep Alpine reactivity simple):
            superAttr: {},   // attribute_id  -> option_value_id
            groupQty: {},    // associated product_id -> qty
            bundleSel: {},   // option_id -> selection_id (single) | [ids] (multi)
            bundleQty: {},   // option_id -> qty (single-select, can_change_qty)
            custom: {},      // option_id -> string | value_id | [value_ids]

            money: function (v) { return money(v); },
            t: function (k, f) { return t(k, f); },

            /* ---------------------------------------------------- lifecycle */

            show: function (productId) {
                var self = this;
                var id = Number(productId) || 0;
                if (!id) {
                    return;
                }
                this.open = true;
                this.busy = true;
                this.adding = false;
                this.error = '';
                this.product = null;
                this.qty = 1;
                this.superAttr = {};
                this.groupQty = {};
                this.bundleSel = {};
                this.bundleQty = {};
                this.custom = {};
                window.PosApi.get('catalog/options', { id: id, group_id: shared.groupId })
                    .then(function (raw) {
                        if (!self.open) {
                            return;
                        }
                        self.product = normalizeOptionsPayload(raw);
                        self.applyDefaults();
                    })
                    .catch(function (e) {
                        if (!self.open) {
                            return;
                        }
                        self.error = errMessage(e, t('options_load_failed', 'Could not load product options.'));
                    })
                    .finally(function () {
                        self.busy = false;
                    });
            },

            close: function () {
                this.open = false;
                this.busy = false;
                this.adding = false;
                this.product = null;
                this.error = '';
            },

            /**
             * Sensible starting state: grouped rows adopt their default qtys,
             * bundle options pre-check their default selections (or the only
             * selection of a required single-select option) with default qty.
             */
            applyDefaults: function () {
                var self = this;
                var p = this.product;
                if (!p) {
                    return;
                }
                p.associated.forEach(function (row) {
                    self.groupQty[row.id] = row.in_stock === false ? 0 : row.qty;
                });
                p.bundle_options.forEach(function (opt) {
                    var defaults = opt.selections.filter(function (sel) { return sel.is_default; });
                    if (isMultiBundle(opt.type)) {
                        self.bundleSel[opt.option_id] = defaults.map(function (sel) { return sel.selection_id; });
                        return;
                    }
                    var chosen = defaults.length ? defaults[0]
                        : ((opt.required && opt.selections.length === 1) ? opt.selections[0] : null);
                    if (chosen) {
                        self.bundleSel[opt.option_id] = chosen.selection_id;
                        self.bundleQty[opt.option_id] = chosen.qty;
                    }
                });
            },

            /* ------------------------------------------------- configurable */

            isConfigurable: function () {
                return !!(this.product && this.product.type === 'configurable');
            },

            selectAttr: function (attributeId, valueId) {
                if (this.superAttr[attributeId] === valueId) {
                    // Tap again to clear - frees combos disabled by this pick.
                    delete this.superAttr[attributeId];
                    this.superAttr = Object.assign({}, this.superAttr);
                    return;
                }
                this.superAttr = Object.assign({}, this.superAttr);
                this.superAttr[attributeId] = valueId;
            },

            isAttrSelected: function (attributeId, valueId) {
                return Number(this.superAttr[attributeId]) === Number(valueId);
            },

            /** Variants matching the current picks, optionally overriding one. */
            _matchingVariants: function (overrideAttrId, overrideValueId) {
                var p = this.product;
                if (!p) {
                    return [];
                }
                var picks = {};
                var self = this;
                p.super_attributes.forEach(function (attr) {
                    var id = attr.attribute_id;
                    if (Number(overrideAttrId) === Number(id)) {
                        picks[id] = Number(overrideValueId);
                    } else if (self.superAttr[id] !== undefined) {
                        picks[id] = Number(self.superAttr[id]);
                    }
                });
                return p.variants.filter(function (variant) {
                    return Object.keys(picks).every(function (attrId) {
                        return Number(variant.attributes[attrId]) === picks[attrId];
                    });
                });
            },

            /**
             * A value combo is offered only when at least one IN-STOCK child
             * matches the other current picks plus this value (item 34: OOS /
             * unavailable combinations are disabled). Without child data we
             * cannot verify - offer everything and let the server validate.
             */
            attrValueAvailable: function (attributeId, valueId) {
                if (!this.product || !this.product.variants.length) {
                    return true;
                }
                return this._matchingVariants(attributeId, valueId).some(function (variant) {
                    return variant.in_stock !== false;
                });
            },

            /** The child simple resolved by a complete selection (or null). */
            resolvedVariant: function () {
                var p = this.product;
                if (!p || p.type !== 'configurable' || !p.super_attributes.length) {
                    return null;
                }
                var self = this;
                var complete = p.super_attributes.every(function (attr) {
                    return self.superAttr[attr.attribute_id] !== undefined;
                });
                if (!complete) {
                    return null;
                }
                var matches = this._matchingVariants();
                return matches.length ? matches[0] : null;
            },

            /** Cheapest in-stock child - the "From" display anchor. */
            minVariantPrice: function () {
                var p = this.product;
                var min = null;
                ((p && p.variants) || []).forEach(function (variant) {
                    if (variant.in_stock === false) {
                        return;
                    }
                    if (min === null || variant.price < min) {
                        min = variant.price;
                    }
                });
                return min !== null ? min : toNum(p && p.price);
            },

            /* ------------------------------------------------------ grouped */

            isGrouped: function () {
                return !!(this.product && this.product.type === 'grouped');
            },

            groupStep: function (row, delta) {
                if (row.in_stock === false) {
                    return;
                }
                var next = Math.floor(toNum(this.groupQty[row.id])) + delta;
                this.groupQty = Object.assign({}, this.groupQty);
                this.groupQty[row.id] = Math.min(999, Math.max(0, next));
            },

            normalizeGroupQty: function (row) {
                var v = Math.floor(toNum(this.groupQty[row.id]));
                this.groupQty[row.id] = Math.min(999, Math.max(0, v));
            },

            groupTotalQty: function () {
                var self = this;
                return Object.keys(this.groupQty).reduce(function (sum, id) {
                    return sum + Math.max(0, Math.floor(toNum(self.groupQty[id])));
                }, 0);
            },

            /* ------------------------------------------------------- bundle */

            isBundle: function () {
                return !!(this.product && this.product.type === 'bundle');
            },

            isMultiBundle: function (opt) {
                return isMultiBundle(opt.type);
            },

            toggleBundleSelection: function (opt, sel) {
                if (sel.in_stock === false) {
                    return;
                }
                var current;
                this.bundleSel = Object.assign({}, this.bundleSel);
                if (isMultiBundle(opt.type)) {
                    current = (this.bundleSel[opt.option_id] || []).slice();
                    var at = current.indexOf(sel.selection_id);
                    if (at === -1) {
                        current.push(sel.selection_id);
                    } else {
                        current.splice(at, 1);
                    }
                    this.bundleSel[opt.option_id] = current;
                    return;
                }
                if (Number(this.bundleSel[opt.option_id]) === sel.selection_id && !opt.required) {
                    delete this.bundleSel[opt.option_id];
                    delete this.bundleQty[opt.option_id];
                    return;
                }
                this.bundleSel[opt.option_id] = sel.selection_id;
                this.bundleQty = Object.assign({}, this.bundleQty);
                this.bundleQty[opt.option_id] = sel.qty;
            },

            /** Dropdown change handler (select-type bundle options). */
            pickBundleSelection: function (opt, rawValue) {
                var id = Number(rawValue) || 0;
                this.bundleSel = Object.assign({}, this.bundleSel);
                if (!id) {
                    delete this.bundleSel[opt.option_id];
                    delete this.bundleQty[opt.option_id];
                    return;
                }
                this.bundleSel[opt.option_id] = id;
                var sel = opt.selections.find(function (row) { return row.selection_id === id; });
                this.bundleQty = Object.assign({}, this.bundleQty);
                this.bundleQty[opt.option_id] = sel ? sel.qty : 1;
            },

            isBundleSelected: function (opt, sel) {
                if (isMultiBundle(opt.type)) {
                    return (this.bundleSel[opt.option_id] || []).indexOf(sel.selection_id) !== -1;
                }
                return Number(this.bundleSel[opt.option_id]) === sel.selection_id;
            },

            /** Selected selection row of a single-select option (or null). */
            bundleChosen: function (opt) {
                if (isMultiBundle(opt.type)) {
                    return null;
                }
                var id = Number(this.bundleSel[opt.option_id]) || 0;
                return id ? (opt.selections.find(function (sel) { return sel.selection_id === id; }) || null) : null;
            },

            /** Qty stepper visible: single-select, chosen, can_change_qty. */
            bundleQtyEditable: function (opt) {
                var chosen = this.bundleChosen(opt);
                return !!(chosen && chosen.can_change_qty);
            },

            bundleStepQty: function (opt, delta) {
                var next = Math.floor(toNum(this.bundleQty[opt.option_id], 1)) + delta;
                this.bundleQty = Object.assign({}, this.bundleQty);
                this.bundleQty[opt.option_id] = Math.min(999, Math.max(1, next));
            },

            /** Display price of one bundle selection (percent = % of base). */
            bundleSelectionPrice: function (sel) {
                if (sel.price_type === 'percent') {
                    return toNum(this.product && this.product.price) * sel.price / 100;
                }
                return sel.price;
            },

            /* ------------------------------------------------ custom options */

            customOptions: function () {
                return (this.product && this.product.custom_options) || [];
            },

            isMultiCustom: function (opt) {
                return isMultiCustom(opt.type);
            },

            toggleCustomValue: function (opt, valueId) {
                this.custom = Object.assign({}, this.custom);
                if (isMultiCustom(opt.type)) {
                    var current = (this.custom[opt.option_id] || []).slice();
                    var at = current.indexOf(valueId);
                    if (at === -1) {
                        current.push(valueId);
                    } else {
                        current.splice(at, 1);
                    }
                    this.custom[opt.option_id] = current;
                    return;
                }
                if (Number(this.custom[opt.option_id]) === Number(valueId)) {
                    delete this.custom[opt.option_id];
                    return;
                }
                this.custom[opt.option_id] = valueId;
            },

            customHas: function (opt, valueId) {
                if (isMultiCustom(opt.type)) {
                    return (this.custom[opt.option_id] || []).indexOf(valueId) !== -1;
                }
                return Number(this.custom[opt.option_id]) === Number(valueId);
            },

            /** Value entered/picked for an option, in any shape? */
            customFilled: function (opt) {
                var v = this.custom[opt.option_id];
                if (isMultiCustom(opt.type)) {
                    return Array.isArray(v) && v.length > 0;
                }
                return v !== undefined && v !== null && String(v).trim() !== '';
            },

            /** Display delta a custom option currently adds (percent of base). */
            customOptionDelta: function (opt, base) {
                var self = this;
                var deltaOf = function (price, priceType) {
                    return priceType === 'percent' ? base * price / 100 : price;
                };
                if (!this.customFilled(opt)) {
                    return 0;
                }
                if (opt.values.length === 0) {
                    return deltaOf(opt.price, opt.price_type);
                }
                var picked = isMultiCustom(opt.type)
                    ? (this.custom[opt.option_id] || [])
                    : [this.custom[opt.option_id]];
                return picked.reduce(function (sum, valueId) {
                    var val = opt.values.find(function (row) { return Number(row.id) === Number(valueId); });
                    return sum + (val ? deltaOf(val.price, val.price_type) : 0);
                }, 0);
            },

            /** Price tag for a pickable custom value ("+ $x" suffix). */
            customValueDelta: function (val) {
                var base = this.basePrice();
                return val.price_type === 'percent' ? base * val.price / 100 : val.price;
            },

            /* -------------------------------------------------- validation */

            /**
             * Labels of every group still blocking the add - the Add button
             * stays disabled and the footer hint lists them (item 34:
             * required-option validation blocks add client-side too; the
             * server re-validates via Magento's own option validation).
             */
            missingGroups: function () {
                var p = this.product;
                var missing = [];
                var self = this;
                if (!p) {
                    return missing;
                }
                if (p.type === 'configurable') {
                    p.super_attributes.forEach(function (attr) {
                        if (self.superAttr[attr.attribute_id] === undefined) {
                            missing.push(attr.label || attr.code);
                        }
                    });
                    // Only veto a complete selection when child data exists -
                    // without it the server-side validation is the gate.
                    if (!missing.length && p.variants.length) {
                        var variant = this.resolvedVariant();
                        if (!variant) {
                            missing.push(t('combo_unavailable', 'This combination is unavailable'));
                        } else if (variant.in_stock === false) {
                            missing.push(t('combo_out_of_stock', 'Selected combination is out of stock'));
                        }
                    }
                }
                if (p.type === 'grouped' && this.groupTotalQty() < 1) {
                    missing.push(t('grouped_pick_items', 'Pick at least one item'));
                }
                p.bundle_options.forEach(function (opt) {
                    if (!opt.required) {
                        return;
                    }
                    var v = self.bundleSel[opt.option_id];
                    var ok = isMultiBundle(opt.type)
                        ? (Array.isArray(v) && v.length > 0)
                        : !!Number(v);
                    if (!ok) {
                        missing.push(opt.title);
                    }
                });
                this.customOptions().forEach(function (opt) {
                    if (!opt.required) {
                        return;
                    }
                    if (opt.type === 'file') {
                        missing.push(opt.title + ' (' + t('file_unsupported', 'file options are not supported at the POS') + ')');
                        return;
                    }
                    if (!self.customFilled(opt)) {
                        missing.push(opt.title);
                    }
                });
                return missing;
            },

            isValid: function () {
                return !!this.product && !this.busy
                    && Math.floor(toNum(this.qty, 1)) >= 1
                    && this.missingGroups().length === 0;
            },

            missingHint: function () {
                var missing = this.missingGroups();
                if (!missing.length) {
                    return '';
                }
                return t('choose_options', 'Choose') + ': ' + missing.slice(0, 3).join(', ')
                    + (missing.length > 3 ? '...' : '');
            },

            /* ------------------------------------------------------- price */

            /** Pre-custom-options base for percent deltas (display only). */
            basePrice: function () {
                var p = this.product;
                if (!p) {
                    return 0;
                }
                if (p.type === 'configurable') {
                    var variant = this.resolvedVariant();
                    return variant ? variant.price : this.minVariantPrice();
                }
                if (p.type === 'bundle') {
                    // Dynamic-priced bundles start at 0 and sum selections;
                    // fixed-priced bundles start at the parent price.
                    return p.price_type === 'fixed' ? p.price : 0;
                }
                return p.price;
            },

            /**
             * Running per-unit DISPLAY price from the option payload. The
             * server response after add is the only pricing authority.
             */
            unitPrice: function () {
                var p = this.product;
                var self = this;
                if (!p) {
                    return 0;
                }
                var unit = this.basePrice();
                if (p.type === 'grouped') {
                    unit = p.associated.reduce(function (sum, row) {
                        return sum + Math.max(0, Math.floor(toNum(self.groupQty[row.id]))) * row.price;
                    }, 0);
                }
                if (p.type === 'bundle') {
                    p.bundle_options.forEach(function (opt) {
                        if (isMultiBundle(opt.type)) {
                            (self.bundleSel[opt.option_id] || []).forEach(function (selectionId) {
                                var sel = opt.selections.find(function (row) {
                                    return row.selection_id === Number(selectionId);
                                });
                                if (sel) {
                                    unit += self.bundleSelectionPrice(sel) * sel.qty;
                                }
                            });
                            return;
                        }
                        var chosen = self.bundleChosen(opt);
                        if (chosen) {
                            var qty = Math.max(1, Math.floor(toNum(self.bundleQty[opt.option_id], chosen.qty)));
                            unit += self.bundleSelectionPrice(chosen) * qty;
                        }
                    });
                }
                var base = unit;
                this.customOptions().forEach(function (opt) {
                    unit += self.customOptionDelta(opt, base);
                });
                return unit;
            },

            /** "From $x" until a configurable resolves; "$y" once priceable. */
            totalText: function () {
                if (!this.product) {
                    return '';
                }
                var qty = Math.max(1, Math.floor(toNum(this.qty, 1)));
                var amount = this.unitPrice() * qty;
                if (this.product.type === 'configurable' && !this.resolvedVariant()) {
                    return t('from', 'From') + ' ' + money(amount);
                }
                return money(amount);
            },

            /* --------------------------------------------------------- qty */

            decQty: function () {
                this.qty = Math.max(1, Math.floor(toNum(this.qty, 1)) - 1);
            },

            incQty: function () {
                this.qty = Math.min(999, Math.floor(toNum(this.qty, 1)) + 1);
            },

            normalizeQty: function () {
                this.qty = Math.min(999, Math.max(1, Math.floor(toNum(this.qty, 1))));
            },

            /* ----------------------------------------------------- add */

            /**
             * Map the selections onto the pinned buyRequest contract - key
             * names and shapes EXACTLY as the backend expects them.
             */
            buildOptions: function () {
                var p = this.product;
                var self = this;
                var options = {};
                if (!p) {
                    return options;
                }
                if (p.type === 'configurable') {
                    var superAttribute = {};
                    p.super_attributes.forEach(function (attr) {
                        var picked = self.superAttr[attr.attribute_id];
                        if (picked !== undefined) {
                            superAttribute[String(attr.attribute_id)] = Number(picked);
                        }
                    });
                    options.super_attribute = superAttribute;
                }
                if (p.type === 'grouped') {
                    var superGroup = {};
                    // Magento's strict-mode Grouped::prepareForCart requires
                    // an entry for EVERY associated product - qty 0 is fine,
                    // but an OMITTED row rejects the whole buyRequest with
                    // "Please specify the quantity of product(s)."
                    p.associated.forEach(function (row) {
                        superGroup[String(row.id)] = Math.max(0, Math.floor(toNum(self.groupQty[row.id])));
                    });
                    options.super_group = superGroup;
                }
                if (p.type === 'bundle' && p.bundle_options.length) {
                    var bundleOption = {};
                    var bundleOptionQty = {};
                    p.bundle_options.forEach(function (opt) {
                        var key = String(opt.option_id);
                        if (isMultiBundle(opt.type)) {
                            var ids = (self.bundleSel[opt.option_id] || []).map(Number).filter(Boolean);
                            if (ids.length) {
                                bundleOption[key] = ids;
                            }
                            return;
                        }
                        var id = Number(self.bundleSel[opt.option_id]) || 0;
                        if (id) {
                            bundleOption[key] = id;
                            bundleOptionQty[key] = Math.max(1, Math.floor(toNum(self.bundleQty[opt.option_id], 1)));
                        }
                    });
                    options.bundle_option = bundleOption;
                    if (Object.keys(bundleOptionQty).length) {
                        options.bundle_option_qty = bundleOptionQty;
                    }
                }
                var customOptions = {};
                this.customOptions().forEach(function (opt) {
                    if (opt.type === 'file' || !self.customFilled(opt)) {
                        return;
                    }
                    var key = String(opt.option_id);
                    var v = self.custom[opt.option_id];
                    if (isMultiCustom(opt.type)) {
                        customOptions[key] = v.map(Number).filter(Boolean);
                    } else if (opt.type === 'drop_down' || opt.type === 'radio') {
                        customOptions[key] = Number(v);
                    } else if (opt.type === 'date') {
                        customOptions[key] = { date: String(v) };
                    } else if (opt.type === 'date_time') {
                        // datetime-local -> "YYYY-MM-DD HH:MM"
                        customOptions[key] = { date: String(v).replace('T', ' ') };
                    } else if (opt.type === 'time') {
                        var parts = String(v).split(':');
                        customOptions[key] = {
                            hour: Number(parts[0]) || 0,
                            minute: Number(parts[1]) || 0
                        };
                    } else {
                        customOptions[key] = String(v);
                    }
                });
                if (Object.keys(customOptions).length) {
                    options.custom_options = customOptions;
                }
                return options;
            },

            /**
             * POST the contract payload to pos/cart/add. The server adds via
             * quote->addProduct($product, buyRequest) - child resolution,
             * required-option validation and ALL pricing happen there - and
             * returns the canonical §7 cart payload, which is re-dispatched
             * as `pos:cart-updated` so cart.js adopts the resolved line(s)
             * (same adoption path customer.js uses).
             */
            add: function () {
                var self = this;
                var p = this.product;
                if (!p || this.adding || !this.isValid()) {
                    return;
                }
                this.normalizeQty();
                this.adding = true;
                resolveQuoteId().then(function (quoteId) {
                    return window.PosApi.post('cart/add', {
                        quote_id: quoteId,
                        product_id: p.id,
                        qty: self.qty,
                        options: self.buildOptions()
                    });
                }).then(function (cart) {
                    if (cart && cart.quote_id) {
                        shared.quoteId = Number(cart.quote_id) || shared.quoteId;
                        window.dispatchEvent(new CustomEvent('pos:cart-updated', { detail: cart }));
                    }
                    notify(t('added_to_cart', 'Added to cart.'), 'success');
                    self.close();
                }).catch(function (e) {
                    // Server-side validation (required options, stock, ...) is
                    // authoritative - surface its message and keep the modal
                    // open so the cashier can fix the selection.
                    notify(errMessage(e, t('add_failed', 'Could not add the product.')), 'error');
                }).finally(function () {
                    self.adding = false;
                });
            }
        });
    }
})();
