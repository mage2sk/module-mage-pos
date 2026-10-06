/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth_MagePos terminal - register session UI (spec §9).
 *
 * Registers the reactive Alpine.store('posRegister') shared by
 * panel-session.phtml (status, live expected cash, cash in/out, inline
 * X report, close-session flow with counted-cash keypad and over/short
 * result) and modal-session.phtml (open-session: register confirm +
 * opening float keypad).
 *
 * Endpoints (spec §7): pos/register/current, /open, /close, /movement,
 * /xreport, /all. Session payloads come from PosSessionService; the
 * Z-report close result is the report structure with cash.expected /
 * cash.counted / cash.over_short.
 */
(function () {
    'use strict';

    var BUFFER_FIELDS = {
        float: 'floatBuffer',
        movement: 'movementBuffer',
        counted: 'countedBuffer'
    };

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

    function dispatch(name, detail) {
        window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
    }

    document.addEventListener('alpine:init', function () {
        window.Alpine.store('posRegister', {
            session: null,
            expectedCash: null,
            loading: false,

            // Open-session modal (modal-session.phtml)
            showOpenModal: false,
            registers: [],
            selectedRegisterId: null,
            floatBuffer: '0',
            openNote: '',
            opening: false,

            // Cash in/out
            movementType: null,
            movementBuffer: '0',
            movementReason: '',
            movementBusy: false,

            // X report
            xreport: null,
            xreportOpen: false,
            xreportLoading: false,

            // Close flow
            closing: false,
            countedBuffer: '0',
            closeNote: '',
            closeBusy: false,
            closeResult: null,

            init: function () {
                var self = this;
                // Reload once authentication is available (login / boot with
                // an active server session) - never fetch pre-auth.
                window.addEventListener('pos:authenticated', function () {
                    self.refresh();
                });
                // PIN unlock dispatches only pos:auth-changed; logout resets.
                window.addEventListener('pos:auth-changed', function (event) {
                    var authenticated = !(event && event.detail) || event.detail.authenticated !== false;
                    if (authenticated) {
                        self.refresh();
                    } else {
                        self.resetTransient();
                        self.adopt(null);
                    }
                });
                // Live expected cash: refresh after every completed sale/refund.
                window.addEventListener('pos:order-placed', function () {
                    self.refresh();
                });
                window.addEventListener('pos:order-refunded', function () {
                    self.refresh();
                });
                // Poll when the session panel is toggled open from the header.
                window.addEventListener('pos:toggle-panel', function (event) {
                    if (event && event.detail && event.detail.panel === 'session') {
                        self.refresh();
                    }
                });
                window.addEventListener('pos:open-session-modal', function () {
                    self.openModal();
                });
            },

            /**
             * Drop transient in-panel UI state (movement form, X report,
             * close flow). Used on logout so a fresh login starts clean.
             */
            resetTransient: function () {
                this.movementType = null;
                this.movementBuffer = '0';
                this.movementReason = '';
                this.xreport = null;
                this.xreportOpen = false;
                this.closing = false;
                this.countedBuffer = '0';
                this.closeNote = '';
                this.closeResult = null;
            },

            can: function (key) {
                var store = posStore();
                return !!(store && store.user && store.user.permissions && store.user.permissions[key]);
            },

            money: function (value) {
                var store = posStore();
                var symbol = (store && store.config && store.config.currency_symbol) || '';
                var amount = Number(value || 0);
                var sign = amount < 0 ? '-' : '';
                return sign + symbol + Math.abs(amount).toFixed(2);
            },

            adopt: function (session) {
                this.session = session || null;
                this.expectedCash = session ? session.expected_cash : null;
                var store = posStore();
                if (store) {
                    store.session = this.session;
                }
            },

            refresh: function () {
                var self = this;
                if (this.loading || !window.PosApi) {
                    return Promise.resolve(null);
                }
                this.loading = true;
                return Promise.resolve().then(function () {
                    return api().get('register/current');
                }).then(function (session) {
                    self.adopt(session || null);
                    if (!session) {
                        // Session was closed elsewhere - stale report views.
                        self.xreport = null;
                        self.xreportOpen = false;
                        self.closing = false;
                    }
                    return session;
                }).catch(function () {
                    // Unauthorized / network - keep the current state.
                    return null;
                }).finally(function () {
                    self.loading = false;
                });
            },

            /* ---- Open session (modal-session) ---- */

            openModal: function () {
                var self = this;
                this.showOpenModal = true;
                this.floatBuffer = '0';
                this.openNote = '';
                var shared = posStore();
                var registers = (shared && Array.isArray(shared.registers)) ? shared.registers : [];
                if (registers.length) {
                    this.applyRegisters(registers);
                    return Promise.resolve(this.registers);
                }
                return Promise.resolve().then(function () {
                    return api().get('register/all');
                }).then(function (rows) {
                    self.applyRegisters(Array.isArray(rows) ? rows : []);
                    return self.registers;
                }).catch(function (error) {
                    notify((error && error.message) || t('Unable to load registers.'), 'error');
                    return [];
                });
            },

            applyRegisters: function (rows) {
                // auth/state rows use register_id, register/all rows use id.
                this.registers = rows.map(function (row) {
                    return {
                        id: row.id !== undefined ? row.id : row.register_id,
                        name: row.name,
                        code: row.code,
                        store_id: row.store_id
                    };
                });
                if (this.registers.length && !this.isSelectedRegisterValid()) {
                    this.selectedRegisterId = this.registers[0].id;
                }
            },

            isSelectedRegisterValid: function () {
                var selected = this.selectedRegisterId;
                return this.registers.some(function (register) {
                    return register.id === selected;
                });
            },

            selectRegister: function (registerId) {
                this.selectedRegisterId = registerId;
            },

            registerTabIndex: function (register, index) {
                if (this.isSelectedRegisterValid()) {
                    return register.id === this.selectedRegisterId ? 0 : -1;
                }
                return index === 0 ? 0 : -1;
            },

            registerKey: function (event, index) {
                var count = this.registers.length;
                var next = index;
                var items;

                if (!count) {
                    return;
                }
                switch (event.key) {
                    case 'ArrowDown':
                    case 'ArrowRight':
                        next = (index + 1) % count;
                        break;
                    case 'ArrowUp':
                    case 'ArrowLeft':
                        next = (index - 1 + count) % count;
                        break;
                    case 'Home':
                        next = 0;
                        break;
                    case 'End':
                        next = count - 1;
                        break;
                    case ' ':
                    case 'Spacebar':
                        break;
                    case 'Enter':
                        this.selectRegister(this.registers[index].id);
                        return;
                    default:
                        return;
                }
                event.preventDefault();
                event.stopPropagation();
                this.selectRegister(this.registers[next].id);
                if (next !== index && event.currentTarget && event.currentTarget.parentNode) {
                    items = event.currentTarget.parentNode.querySelectorAll('[role="radio"]');
                    if (items[next]) {
                        items[next].focus();
                    }
                }
            },

            panelKey: function (event, panel) {
                var field = this.closing ? 'counted' : (this.movementType ? 'movement' : null);
                var key;
                if (!field || this.showOpenModal || !window.posPadKey || !panel || !panel.getClientRects().length) {
                    return;
                }
                key = window.posPadKey(event);
                if (key === null || key === 'enter') {
                    return;
                }
                event.preventDefault();
                this.keypad(field, key);
            },

            openKey: function (event) {
                var target = event.target;
                var key = event.key;

                if (!this.showOpenModal || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
                    return;
                }
                if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA'
                    || target.tagName === 'SELECT' || target.isContentEditable)) {
                    return;
                }
                if (/^[0-9]$/.test(key)) {
                    this.keypad('float', key);
                } else if (key === '.' || key === ',' || key === 'Decimal') {
                    this.keypad('float', '.');
                } else if (key === 'Backspace') {
                    this.keypad('float', 'back');
                } else if (key === 'Enter') {
                    if (target && (target.tagName === 'BUTTON' || target.tagName === 'A')) {
                        return;
                    }
                    if (!this.opening && this.selectedRegisterId) {
                        this.confirmOpen();
                    }
                } else {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
            },

            closeOpenModal: function () {
                if (!this.opening) {
                    this.showOpenModal = false;
                }
            },

            confirmOpen: function () {
                var self = this;
                if (!this.selectedRegisterId) {
                    notify(t('Please select a register.'), 'error');
                    return Promise.resolve(null);
                }
                if (this.opening) {
                    return Promise.resolve(null);
                }
                this.opening = true;
                return Promise.resolve().then(function () {
                    return api().post('register/open', {
                        register_id: self.selectedRegisterId,
                        opening_float: parseFloat(self.floatBuffer) || 0,
                        note: self.openNote.trim() || null
                    });
                }).then(function (session) {
                    self.adopt(session);
                    self.showOpenModal = false;
                    self.closeResult = null;
                    self.closing = false;
                    dispatch('pos:session-changed', { session: session });
                    notify(t('Register session opened.'), 'success');
                    return session;
                }).catch(function (error) {
                    notify((error && error.message) || t('Unable to open the register session.'), 'error');
                    return null;
                }).finally(function () {
                    self.opening = false;
                });
            },

            /* ---- Cash in / out ---- */

            startMovement: function (type) {
                this.movementType = type === 'out' ? 'out' : 'in';
                this.movementBuffer = '0';
                this.movementReason = '';
            },

            cancelMovement: function () {
                this.movementType = null;
            },

            confirmMovement: function () {
                var self = this;
                var amount = parseFloat(this.movementBuffer) || 0;
                if (amount <= 0) {
                    notify(t('Enter an amount greater than zero.'), 'error');
                    return Promise.resolve(null);
                }
                if (this.movementBusy) {
                    return Promise.resolve(null);
                }
                this.movementBusy = true;
                return Promise.resolve().then(function () {
                    return api().post('register/movement', {
                        type: self.movementType,
                        amount: amount,
                        reason: self.movementReason.trim()
                    });
                }).then(function (data) {
                    if (data && data.expected_cash !== undefined) {
                        self.expectedCash = data.expected_cash;
                        if (self.session) {
                            self.session.expected_cash = data.expected_cash;
                        }
                    } else {
                        // Movement response without the live figure -
                        // refetch register/current as the source of truth.
                        self.refresh();
                    }
                    notify(
                        self.movementType === 'out' ? t('Cash out recorded.') : t('Cash in recorded.'),
                        'success'
                    );
                    self.movementType = null;
                    dispatch('pos:cash-movement', data || {});
                    return data;
                }).catch(function (error) {
                    notify((error && error.message) || t('Unable to record the cash movement.'), 'error');
                    return null;
                }).finally(function () {
                    self.movementBusy = false;
                });
            },

            /* ---- X report ---- */

            toggleXreport: function () {
                var self = this;
                if (this.xreportOpen) {
                    this.xreportOpen = false;
                    return Promise.resolve(null);
                }
                if (this.xreportLoading) {
                    return Promise.resolve(null);
                }
                this.xreportLoading = true;
                return Promise.resolve().then(function () {
                    return api().get('register/xreport');
                }).then(function (report) {
                    self.xreport = report;
                    self.xreportOpen = true;
                    return report;
                }).catch(function (error) {
                    notify((error && error.message) || t('Unable to build the X report.'), 'error');
                    return null;
                }).finally(function () {
                    self.xreportLoading = false;
                });
            },

            /* ---- Close (Z report) flow ---- */

            startClose: function () {
                this.closing = true;
                this.countedBuffer = '0';
                this.closeNote = '';
                this.closeResult = null;
                this.refresh();
            },

            cancelClose: function () {
                if (!this.closeBusy) {
                    this.closing = false;
                }
            },

            confirmClose: function () {
                var self = this;
                if (this.closeBusy) {
                    return Promise.resolve(null);
                }
                this.closeBusy = true;
                return Promise.resolve().then(function () {
                    return api().post('register/close', {
                        counted_cash: parseFloat(self.countedBuffer) || 0,
                        note: self.closeNote.trim() || null
                    });
                }).then(function (report) {
                    self.closeResult = report;
                    self.adopt(null);
                    self.xreport = null;
                    self.xreportOpen = false;
                    dispatch('pos:session-changed', { session: null, zreport: report });
                    notify(t('Register session closed.'), 'success');
                    return report;
                }).catch(function (error) {
                    notify((error && error.message) || t('Unable to close the register session.'), 'error');
                    return null;
                }).finally(function () {
                    self.closeBusy = false;
                });
            },

            finishClose: function () {
                this.closing = false;
                this.closeResult = null;
            },

            overShort: function () {
                return this.closeResult && this.closeResult.cash
                    ? Number(this.closeResult.cash.over_short || 0)
                    : 0;
            },

            /* ---- Shared numeric keypad buffers ---- */

            keypad: function (field, key) {
                var prop = BUFFER_FIELDS[field];
                if (!prop) {
                    return;
                }
                var value = String(this[prop] !== undefined && this[prop] !== null ? this[prop] : '0');
                if (key === 'back') {
                    value = value.length > 1 ? value.slice(0, -1) : '0';
                } else if (key === 'C') {
                    value = '0';
                } else if (key === '.') {
                    if (value.indexOf('.') === -1) {
                        value += '.';
                    }
                } else if (/^[0-9]$/.test(key)) {
                    var next = value === '0' ? key : value + key;
                    var parts = next.split('.');
                    if (next.length > 10 || (parts[1] && parts[1].length > 2)) {
                        return;
                    }
                    value = next;
                } else {
                    return;
                }
                this[prop] = value;
            },

            bufferAmount: function (field) {
                var prop = BUFFER_FIELDS[field];
                return prop ? (parseFloat(this[prop]) || 0) : 0;
            }
        });
    });
})();
