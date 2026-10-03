/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * POS terminal application root (spec §9):
 * - window.Pos - config from <script id="pos-config"> + Pos.t(key) i18n
 *   helper + Pos.money(value) formatter
 * - Alpine.store('pos') - auth state, current view ('loading' | 'login' |
 *   'locked' | 'sale'), panels registry, modal registry, multi-cart tabs,
 *   toasts via notify(message, type[, timeout]) (max 3 stacked, oldest
 *   evicted; each toast carries {icon, timeout} so the template can render
 *   a sprite icon + drive the auto-dismiss progress animation; manual
 *   dismissal via dismissToast(id)), session payload
 * - window.posApp() - root x-data component on <body>: boot (GET
 *   pos/auth/state; when authenticated AND state.locked the terminal
 *   boots straight into the PIN lock screen - contract item 42), idle-lock
 *   timer (config minutes -> PIN lock, persisted server-side via POST
 *   pos/auth/lock), network status wiring
 * - window.posLogin() - login screen component (terminal/login.phtml)
 *
 * Cross-component contracts (window events):
 *   pos:booted            initial auth/state loaded
 *   pos:authenticated     {user} after EVERY auth entry path: login, PIN
 *                         unlock and boot with a restored session - all
 *                         data-loading stores (re)load on this event
 *   pos:unlocked          after successful PIN unlock
 *   pos:logged-out        after logout
 *   pos:auth-changed      {authenticated, user?} mirrors all of the above
 *                         (consumed by register.js / holds.js / offline.js)
 *   pos:cart-switched     {quote_id} active cart tab changed
 *   pos:toggle-panel      {panel} request to toggle a panel (dispatched by
 *                         the header Holds / Session buttons; handled here
 *                         via togglePanel and by layout.js for persistence)
 *   pos:panels-changed    panel visibility toggled
 *   pos:layout-edit       {enabled} layout edit mode toggled
 *   pos:modal-open/close  {name}
 *   pos:network-offline / pos:network-online (dispatched by api.js)
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* window.Pos - config + i18n                                          */
    /* ------------------------------------------------------------------ */

    var Pos = {
        config: {
            api_base_url: '',
            form_key: '',
            currency_symbol: '',
            currency_code: '',
            store_name: '',
            store_id: null,
            idle_lock_minutes: 5,
            offline_enabled: true,
            version: '1.0.0',
            i18n: {}
        },

        /**
         * Translate a JS string key (i18n map from Block\Terminal
         * getConfigJson). Optional vars replace %1, %2, ... placeholders.
         * @param {string} key
         * @param {...*} vars
         * @returns {string}
         */
        t: function (key) {
            var translated = (this.config.i18n && this.config.i18n[key]) ? this.config.i18n[key] : key,
                vars = Array.prototype.slice.call(arguments, 1);

            vars.forEach(function (value, index) {
                translated = translated.split('%' + (index + 1)).join(String(value));
            });

            return translated;
        },

        /**
         * Display-format a money value (server does all money math).
         * @param {number|string} value
         * @returns {string}
         */
        money: function (value) {
            var amount = Number(value);
            if (isNaN(amount)) {
                amount = 0;
            }
            return this.config.currency_symbol + amount.toFixed(2);
        }
    };

    window.Pos = Pos;

    // Bootstrap config: deferred scripts run after the document is parsed,
    // so #pos-config is always available here.
    (function readConfig() {
        var element = document.getElementById('pos-config');
        if (!element) {
            return;
        }
        try {
            Object.assign(Pos.config, JSON.parse(element.textContent));
        } catch (e) {
            // leave defaults; boot will surface API errors
        }
    })();

    if (window.PosApi) {
        window.PosApi.configure({
            baseUrl: Pos.config.api_base_url,
            formKey: Pos.config.form_key
        });
    }

    /* ------------------------------------------------------------------ */
    /* localStorage helpers                                                */
    /* ------------------------------------------------------------------ */

    function lsGet(key, fallback) {
        try {
            var raw = window.localStorage.getItem(key);
            return raw === null ? fallback : raw;
        } catch (e) {
            return fallback;
        }
    }

    function lsSet(key, value) {
        try {
            if (value === null || value === undefined) {
                window.localStorage.removeItem(key);
            } else {
                window.localStorage.setItem(key, String(value));
            }
        } catch (e) {
            // storage unavailable (private mode) - state stays in memory
        }
    }

    function dispatch(name, detail) {
        window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
    }

    /* ------------------------------------------------------------------ */
    /* Alpine store                                                        */
    /* ------------------------------------------------------------------ */

    document.addEventListener('alpine:init', function () {
        window.Alpine.store('pos', {
            /* ----- state ----- */
            view: 'loading', // loading | login | locked | sale
            authenticated: false,
            // server-persisted lock flag (contract item 42): mirrors the
            // session 'locked' boolean returned by GET pos/auth/state -
            // while true the terminal must show the PIN lock screen and
            // data endpoints reject, so no data loads may fire.
            locked: false,
            user: null,
            session: null,
            registers: [],
            paymentMethods: [],
            online: window.navigator.onLine !== false,
            toasts: [],
            _toastSeq: 0,
            _toastTimers: {},
            lockedUsername: lsGet('panth_pos_last_user', ''),
            selectedRegisterId: parseInt(lsGet('panth_pos_register_id', '0'), 10) || 0,

            // multi-cart tabs: [{quote_id, label}], persisted in localStorage
            carts: [],
            activeCart: null,

            // panel visibility registry (geometry is owned by layout.js)
            panels: {
                catalog: true,
                cart: true,
                customer: true,
                quickkeys: true,
                holds: false,
                session: false
            },

            // modal visibility registry (modal partials bind x-show on these)
            modals: {
                checkout: false,
                customer: false,
                refund: false,
                session: false,
                settings: false,
                layout: false,
                receipt: false
            },

            layoutEdit: false,
            theme: { mode: 'light' }, // layout.js extends/persists this

            get config() {
                return Pos.config;
            },

            /* ----- helpers ----- */

            hasPermission: function (key) {
                return !!(this.user && this.user.permissions && this.user.permissions[key]);
            },

            money: function (value) {
                return Pos.money(value);
            },

            /**
             * Push a toast. Rendered by terminal.phtml inside .pos-toasts
             * (fixed BELOW the header per the UI-fix contract); the type
             * maps to the pos-toast--{type} accent-edge modifier. At most
             * 3 toasts stack - the oldest is evicted. Each toast exposes
             * a sprite icon id and its lifetime so the template can show
             * an icon and animate dismissal progress (animation-duration
             * bound to toast.timeout).
             *
             * @param {string} message
             * @param {string} [type] success | error | danger | warning | info
             * @param {number} [timeout] ms before auto-dismiss (default 3500)
             */
            notify: function (message, type, timeout) {
                var self = this,
                    id = ++this._toastSeq,
                    duration = Number(timeout) > 0 ? Number(timeout) : 3500,
                    icons = {
                        success: 'pi-check',
                        error: 'pi-x',
                        danger: 'pi-x',
                        warning: 'pi-sparkle',
                        info: 'pi-note'
                    };

                type = type || 'info';

                // max 3 stacked - evict the oldest first
                while (this.toasts.length >= 3) {
                    this.dismissToast(this.toasts[0].id);
                }

                this.toasts.push({
                    id: id,
                    message: String(message),
                    type: type,
                    icon: icons[type] || icons.info,
                    timeout: duration
                });
                this._toastTimers[id] = window.setTimeout(function () {
                    self.dismissToast(id);
                }, duration);
            },

            /**
             * Remove a toast (auto-dismiss timer or manual tap).
             * @param {number} id
             */
            dismissToast: function (id) {
                if (this._toastTimers[id]) {
                    window.clearTimeout(this._toastTimers[id]);
                    delete this._toastTimers[id];
                }
                this.toasts = this.toasts.filter(function (toast) {
                    return toast.id !== id;
                });
            },

            /**
             * Central API error handler for all components.
             * @param {{message?: string, code?: string}|*} error
             */
            error: function (error) {
                var message = (error && error.message) ? error.message : Pos.t('error');

                if (error && error.code === 'unauthorized') {
                    this.authenticated = false;
                    this.view = this.lockedUsername !== '' ? 'locked' : 'login';
                    message = Pos.t('unauthorized');
                }
                this.notify(message, 'error');
            },

            /* ----- boot / auth flow ----- */

            boot: function () {
                var self = this;

                this.restoreCarts();

                return window.PosApi.get('auth/state').then(function (data) {
                    self.applyState(data);
                    if (self.authenticated && self.locked) {
                        // Contract item 42: a hard refresh while locked must
                        // land on the PIN lock screen, never in 'sale'. Data
                        // endpoints reject while locked, so pos:authenticated
                        // (which triggers all data loads) is deferred until
                        // pinUnlock / login succeeds.
                        self.view = 'locked';
                    } else {
                        self.view = self.authenticated ? 'sale' : 'login';
                        if (self.authenticated) {
                            dispatch('pos:authenticated', { user: self.user });
                            dispatch('pos:auth-changed', { authenticated: true, user: self.user });
                        }
                    }
                    dispatch('pos:booted');
                }).catch(function (error) {
                    self.view = 'login';
                    self.notify((error && error.message) ? error.message : Pos.t('network_error'), 'error');
                    dispatch('pos:booted');
                });
            },

            /**
             * Apply the GET pos/auth/state payload (spec §7).
             * @param {Object} data
             */
            applyState: function (data) {
                if (!data) {
                    return;
                }
                this.authenticated = !!data.authenticated;
                this.locked = !!data.locked;
                this.user = data.user || null;
                this.session = data.session || null;
                this.registers = data.registers || [];
                this.paymentMethods = data.payment_methods || [];
                if (data.config) {
                    Object.assign(Pos.config, data.config);
                }
                if (this.user && this.user.username) {
                    this.lockedUsername = this.user.username;
                    lsSet('panth_pos_last_user', this.lockedUsername);
                }
            },

            refreshState: function () {
                var self = this;

                return window.PosApi.get('auth/state').then(function (data) {
                    self.applyState(data);
                }).catch(function () {
                    // non-fatal - keep current client state
                });
            },

            login: function (username, password) {
                var self = this;

                return window.PosApi.post('auth/login', {
                    username: username,
                    password: password
                }).then(function (user) {
                    self.user = user || null;
                    self.authenticated = true;
                    self.locked = false; // server clears the session lock on login
                    self.lockedUsername = (user && user.username) ? user.username : username;
                    lsSet('panth_pos_last_user', self.lockedUsername);

                    return self.refreshState();
                }).then(function () {
                    self.locked = false;
                    self.view = 'sale';
                    self.notify(Pos.t('signed_in', self.user ? self.user.name : self.lockedUsername), 'success');
                    dispatch('pos:authenticated', { user: self.user });
                    dispatch('pos:auth-changed', { authenticated: true, user: self.user });

                    return self.user;
                });
            },

            pinUnlock: function (username, pin) {
                var self = this;

                return window.PosApi.post('auth/pin', {
                    username: username,
                    pin: pin
                }).then(function (user) {
                    self.user = user || null;
                    self.authenticated = true;
                    self.locked = false; // server clears the session lock on PIN unlock

                    return self.refreshState();
                }).then(function () {
                    self.locked = false;
                    self.view = 'sale';
                    // PIN unlock is a full auth entry path: components that
                    // (re)load data on pos:authenticated must fire here too.
                    dispatch('pos:authenticated', { user: self.user });
                    dispatch('pos:unlocked', { user: self.user });
                    dispatch('pos:auth-changed', { authenticated: true, user: self.user });

                    return self.user;
                });
            },

            logout: function () {
                var self = this;

                return window.PosApi.post('auth/logout', {}).catch(function () {
                    // even if the request fails, drop client-side auth state
                }).then(function () {
                    self.user = null;
                    self.authenticated = false;
                    self.locked = false;
                    self.session = null;
                    self.view = 'login';
                    self.notify(Pos.t('signed_out'), 'info');
                    dispatch('pos:logged-out');
                    dispatch('pos:auth-changed', { authenticated: false });
                });
            },

            /**
             * Lock the terminal (header Lock button + idle-lock timer).
             * The local view flips immediately (fail-secure UI), then the
             * lock is persisted server-side via POST pos/auth/lock so a
             * hard refresh boots back into the PIN screen and data
             * endpoints reject until unlock (contract item 42).
             */
            lock: function () {
                var self = this;

                if (!this.authenticated) {
                    return;
                }
                if (this.user && this.user.username) {
                    this.lockedUsername = this.user.username;
                    lsSet('panth_pos_last_user', this.lockedUsername);
                }
                this.locked = true;
                this.view = 'locked';

                return window.PosApi.post('auth/lock', {}).catch(function (error) {
                    // UI stays locked either way; warn that the server-side
                    // lock could not be persisted (a refresh would re-check
                    // auth/state, so surface it rather than fail silently).
                    self.notify((error && error.message) ? error.message : Pos.t('error'), 'warning');
                });
            },

            /* ----- view / panels / modals ----- */

            setView: function (view) {
                this.view = view;
            },

            openModal: function (name) {
                if (Object.prototype.hasOwnProperty.call(this.modals, name)) {
                    this.modals[name] = true;
                    dispatch('pos:modal-open', { name: name });
                }
            },

            closeModal: function (name) {
                if (Object.prototype.hasOwnProperty.call(this.modals, name)) {
                    this.modals[name] = false;
                    dispatch('pos:modal-close', { name: name });
                }
            },

            togglePanel: function (name) {
                if (Object.prototype.hasOwnProperty.call(this.panels, name)) {
                    this.panels[name] = !this.panels[name];
                    dispatch('pos:panels-changed', { name: name, visible: this.panels[name] });
                }
            },

            toggleLayoutEdit: function () {
                if (!this.hasPermission('can_edit_layout')) {
                    this.notify(Pos.t('no_permission'), 'error');
                    return;
                }
                this.layoutEdit = !this.layoutEdit;
                this.notify(Pos.t(this.layoutEdit ? 'layout_edit_on' : 'layout_edit_off'), 'info');
                dispatch('pos:layout-edit', { enabled: this.layoutEdit });
            },

            /* ----- register selection (login screen) ----- */

            selectRegister: function (registerId) {
                this.selectedRegisterId = parseInt(registerId, 10) || 0;
                lsSet('panth_pos_register_id', this.selectedRegisterId || null);
            },

            setSession: function (session) {
                this.session = session || null;
            },

            /* ----- multi-cart tabs (cart.js reacts to pos:cart-switched) ----- */

            restoreCarts: function () {
                var raw = lsGet('panth_pos_carts', '[]'),
                    active = parseInt(lsGet('panth_pos_active_cart', '0'), 10) || 0;

                try {
                    var parsed = JSON.parse(raw);
                    this.carts = Array.isArray(parsed) ? parsed.filter(function (cart) {
                        return cart && cart.quote_id;
                    }) : [];
                } catch (e) {
                    this.carts = [];
                }

                if (active && this.carts.some(function (cart) { return cart.quote_id === active; })) {
                    this.activeCart = active;
                } else {
                    this.activeCart = this.carts.length ? this.carts[0].quote_id : null;
                }
            },

            persistCarts: function () {
                lsSet('panth_pos_carts', JSON.stringify(this.carts));
                lsSet('panth_pos_active_cart', this.activeCart || null);
            },

            newCart: function () {
                var self = this;

                return window.PosApi.post('cart/create', {}).then(function (data) {
                    var quoteId = (data && typeof data === 'object') ? parseInt(data.quote_id, 10) : parseInt(data, 10);

                    if (!quoteId) {
                        throw { message: Pos.t('error'), code: 'invalid_response' };
                    }
                    self.carts.push({
                        quote_id: quoteId,
                        label: Pos.t('cart') + ' ' + (self.carts.length + 1)
                    });
                    self.switchCart(quoteId);

                    return quoteId;
                }).catch(function (error) {
                    self.error(error);
                });
            },

            switchCart: function (quoteId) {
                this.activeCart = quoteId;
                this.persistCarts();
                dispatch('pos:cart-switched', { quote_id: quoteId });
            },

            removeCartTab: function (quoteId) {
                this.carts = this.carts.filter(function (cart) {
                    return cart.quote_id !== quoteId;
                });
                if (this.activeCart === quoteId) {
                    this.activeCart = this.carts.length ? this.carts[0].quote_id : null;
                    dispatch('pos:cart-switched', { quote_id: this.activeCart });
                }
                this.persistCarts();
            }
        });
    });

    /* ------------------------------------------------------------------ */
    /* Root component - <body x-data="posApp()">                           */
    /* ------------------------------------------------------------------ */

    window.posApp = function () {
        return {
            _idleTimer: null,

            init: function () {
                var store = this.$store.pos;

                // network status wiring
                window.addEventListener('pos:network-offline', function () {
                    store.online = false;
                });
                window.addEventListener('pos:network-online', function () {
                    store.online = true;
                });
                window.addEventListener('offline', function () {
                    store.online = false;
                });
                window.addEventListener('online', function () {
                    store.online = true;
                });

                // Header Holds / Session buttons (and any other component)
                // request panel toggles via this window event. togglePanel
                // updates the boolean registry (header active states) and
                // re-dispatches pos:panels-changed; layout.js mirrors the
                // booleans into panel visibility and persists the layout.
                window.addEventListener('pos:toggle-panel', function (event) {
                    var panel = event && event.detail ? event.detail.panel : null;

                    if (panel) {
                        store.togglePanel(panel);
                    }
                });

                this.setupIdleLock();
                store.boot();
            },

            /**
             * Idle auto-lock (spec §2): after idle_lock_minutes without any
             * pointer/key activity the terminal switches to the PIN lock
             * overlay (view 'locked' -> login.phtml renders the .pos-login
             * lock hero with __locked-title / __locked-user) via
             * store.lock(), which also persists the lock server-side
             * (POST pos/auth/lock, contract item 42). The settings
             * toggle (posLayout theme.idle_lock_enabled) is honoured at
             * fire time, so flipping it live takes effect without reload.
             * PIN unlock only re-authenticates the same username within
             * the same browser session (spec §12).
             */
            setupIdleLock: function () {
                var self = this,
                    minutes = Number(Pos.config.idle_lock_minutes) || 0,
                    reset;

                if (minutes <= 0) {
                    return;
                }

                reset = function () {
                    if (self._idleTimer) {
                        window.clearTimeout(self._idleTimer);
                    }
                    self._idleTimer = window.setTimeout(function () {
                        var store = window.Alpine.store('pos'),
                            layout = window.Alpine.store('posLayout'),
                            enabled = !layout
                                || typeof layout.isIdleLockEnabled !== 'function'
                                || layout.isIdleLockEnabled();

                        if (enabled && store.authenticated && store.view === 'sale') {
                            store.lock();
                        } else {
                            // lock disabled in settings / not in sale view -
                            // keep the timer armed for when it re-enables
                            reset();
                        }
                    }, minutes * 60000);
                };

                ['pointerdown', 'keydown', 'touchstart', 'mousemove'].forEach(function (eventName) {
                    document.addEventListener(eventName, reset, { passive: true });
                });
                reset();
            }
        };
    };

    /* ------------------------------------------------------------------ */
    /* Login screen component - terminal/login.phtml                       */
    /* ------------------------------------------------------------------ */

    window.posLogin = function () {
        return {
            username: '',
            password: '',
            error: '',
            busy: false,
            registerId: 0,

            init: function () {
                var store = this.$store.pos;

                this.registerId = store.selectedRegisterId || 0;
                this.username = store.lockedUsername || '';
            },

            saveRegister: function () {
                this.$store.pos.selectRegister(this.registerId);
            },

            submit: function () {
                var self = this;

                if (this.busy) {
                    return;
                }
                this.error = '';
                if (this.username.trim() === '' || this.password === '') {
                    this.error = Pos.t('enter_credentials');
                    return;
                }
                this.busy = true;
                this.$store.pos.login(this.username.trim(), this.password).then(function () {
                    self.password = '';
                }).catch(function (error) {
                    self.error = (error && error.message) ? error.message : Pos.t('login_failed');
                }).finally(function () {
                    self.busy = false;
                });
            },

            unlock: function (pin) {
                var self = this,
                    store = this.$store.pos,
                    username = store.lockedUsername || this.username.trim();

                if (this.busy || !pin) {
                    return;
                }
                this.busy = true;
                this.error = '';
                store.pinUnlock(username, String(pin)).catch(function (error) {
                    self.error = (error && error.message) ? error.message : Pos.t('pin_failed');
                }).finally(function () {
                    self.busy = false;
                });
            },

            usePassword: function () {
                this.error = '';
                this.$store.pos.view = 'login';
            },

            /**
             * Fully sign out from the PIN lock screen (contract item 42):
             * ends the server session via POST pos/auth/logout and returns
             * to the full login form.
             */
            signOut: function () {
                var self = this;

                if (this.busy) {
                    return;
                }
                this.busy = true;
                this.error = '';
                this.$store.pos.logout().finally(function () {
                    self.busy = false;
                });
            }
        };
    };
})();
