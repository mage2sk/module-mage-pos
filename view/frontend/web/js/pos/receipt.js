/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth_MagePos terminal - receipt helpers + receipt modal component.
 *
 * window.PosReceipt = {
 *   receiptUrl(orderId, token, autoPrint) - pos/checkout/receipt URL; `token`
 *                                   (the order's receipt_token, item 16) is
 *                                   appended as ?token= so the link works for
 *                                   recipients without a POS session; ?print=1
 *                                   when autoPrint is truthy,
 *   printOrder(orderId, token)    - hidden-iframe print of the receipt page,
 *   printUrl(url)                 - generic hidden-iframe print helper,
 *   makeQr(el, text)              - QR via bundled vendor qrcode.min.js
 *                                   (davidshimjs QRCode), link-text fallback.
 * }
 *
 * `posReceiptModal()` powers modal-receipt.phtml: post-sale receipt actions
 * and reprint-last-receipt (last order remembered in localStorage).
 *
 * Cross-component contract (window CustomEvents):
 *   listens    `pos:order-placed` {result, cart} -> remember last order
 *              (grand_total/customer_email taken from the cart when the
 *              place-order response does not carry them)
 *   listens    `pos:receipt-show` {order} | {order_id,...} -> open modal
 *   listens    `pos:receipt-open`                        -> open with last order
 *   dispatches `pos:new-sale`
 */
(function () {
    'use strict';

    var LAST_ORDER_KEY = 'panth_pos_last_order';

    function ui() { return window.PosUi || {}; }
    function money(v) { return ui().money ? ui().money(v) : String(v); }
    function notify(m, type) { if (ui().notify) { ui().notify(m, type); } }
    function t(k, f) { return ui().t ? ui().t(k, f) : (f || k); }
    function emit(n, d) { window.dispatchEvent(new CustomEvent(n, { detail: d || {} })); }

    /**
     * Base terminal URL ({store base}/pos): boot-config keys first, then
     * derived from the current location (the SPA lives under /pos).
     */
    function basePosUrl() {
        var cfg = ui().bootConfig ? ui().bootConfig() : {};
        var candidates = [
            cfg.api_base_url, cfg.apiBaseUrl, cfg.base_url, cfg.baseUrl,
            cfg.config && cfg.config.api_base_url
        ];
        for (var i = 0; i < candidates.length; i++) {
            if (typeof candidates[i] === 'string' && candidates[i] !== '') {
                return candidates[i].replace(/\/+$/, '');
            }
        }
        var match = window.location.pathname.match(/^(.*?\/pos)(\/|$)/);
        if (match) {
            return window.location.origin + match[1];
        }
        return window.location.origin + '/pos';
    }

    function receiptUrl(orderId, token, autoPrint) {
        var url = basePosUrl() + '/checkout/receipt?order_id=' + encodeURIComponent(String(orderId));
        if (token) {
            url += '&token=' + encodeURIComponent(String(token));
        }
        if (autoPrint) {
            url += '&print=1';
        }
        return url;
    }

    /**
     * Print a URL through a hidden iframe so the terminal never navigates
     * away mid-sale. The receipt page auto-prints itself when ?print=1, the
     * explicit print() call is a belt-and-braces fallback.
     */
    function printUrl(url) {
        var iframe = document.createElement('iframe');
        iframe.setAttribute('aria-hidden', 'true');
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        iframe.style.visibility = 'hidden';
        iframe.onload = function () {
            setTimeout(function () {
                try {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                } catch (e) { /* cross-origin or already printed via ?print=1 */ }
            }, 250);
        };
        iframe.src = url;
        document.body.appendChild(iframe);
        setTimeout(function () {
            if (iframe.parentNode) {
                iframe.parentNode.removeChild(iframe);
            }
        }, 60000);
    }

    function printOrder(orderId, token) {
        printUrl(receiptUrl(orderId, token, true));
    }

    /**
     * Render a QR code for `text` into `el` using the bundled
     * js/vendor/qrcode.min.js (davidshimjs). Falls back to a plain link
     * when the generator is unavailable.
     */
    function makeQr(el, text) {
        if (!el || !text) {
            return;
        }
        el.innerHTML = '';
        if (window.QRCode) {
            try {
                // davidshimjs CorrectLevel.M === 0, so test with `in`, never
                // truthiness (a plain `CorrectLevel.M ? ... : ...` would skip it).
                var levels = window.QRCode.CorrectLevel || {};
                /* eslint-disable no-new */
                new window.QRCode(el, {
                    text: text,
                    width: 168,
                    height: 168,
                    correctLevel: ('M' in levels) ? levels.M : 0
                });
                /* eslint-enable no-new */
                // Belt and braces (item 38): only trust the generator when it
                // actually mounted an output node (canvas/img/table/svg).
                if (el.querySelector('canvas, img, table, svg')) {
                    return;
                }
                el.innerHTML = '';
            } catch (e) {
                el.innerHTML = ''; /* fall through to link fallback */
            }
        }
        var link = document.createElement('a');
        link.href = text;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = text;
        link.className = 'pos-qr-fallback';
        el.appendChild(link);
    }

    window.PosReceipt = window.PosReceipt || {};
    window.PosReceipt.receiptUrl = receiptUrl;
    window.PosReceipt.printUrl = printUrl;
    window.PosReceipt.printOrder = printOrder;
    window.PosReceipt.makeQr = makeQr;

    /* ------------------------------------------------------ receipt modal */

    function readLastOrder() {
        try {
            return JSON.parse(localStorage.getItem(LAST_ORDER_KEY) || 'null');
        } catch (e) {
            return null;
        }
    }

    /**
     * Persist the just-placed order for the receipt modal. The place-order
     * response carries no grand_total, so the hero figure comes from the
     * cart totals that rode along on `pos:order-placed`; customer_email
     * (used by the modal's Email action) likewise falls back to the cart's
     * customer.
     */
    function rememberOrder(result, cart) {
        if (!result || !result.order_id) {
            return null;
        }
        var totals = (cart && cart.totals) || {};
        var grandTotal = null;
        if (result.grand_total !== undefined && result.grand_total !== null && result.grand_total !== '') {
            grandTotal = Number(result.grand_total);
        } else if (totals.grand_total !== undefined && totals.grand_total !== null && totals.grand_total !== '') {
            grandTotal = Number(totals.grand_total);
        }
        var order = {
            order_id: result.order_id,
            increment_id: result.increment_id || '',
            receipt_number: result.receipt_number || '',
            // Item 16: remember the random access token so the receipt link
            // (print / view / emailed mailto) works without a POS session.
            receipt_token: result.receipt_token || '',
            grand_total: grandTotal,
            change_due: Number(result.change_due || 0),
            customer_email: result.customer_email
                || (cart && cart.customer && cart.customer.email)
                || '',
            placed_at: new Date().toISOString()
        };
        try {
            localStorage.setItem(LAST_ORDER_KEY, JSON.stringify(order));
        } catch (e) { /* storage unavailable */ }
        return order;
    }

    window.posReceiptModal = function () {
        return {
            open: false,
            order: null,

            init: function () {
                var self = this;
                this.order = readLastOrder();
                window.addEventListener('pos:order-placed', function (e) {
                    var detail = e.detail || {};
                    var order = rememberOrder(detail.result, detail.cart);
                    if (order) {
                        self.order = order;
                    }
                });
                window.addEventListener('pos:receipt-show', function (e) {
                    var detail = e.detail || {};
                    var order = detail.order || detail;
                    if (order && order.order_id) {
                        self.order = order;
                        self.open = true;
                    }
                });
                window.addEventListener('pos:receipt-open', function () {
                    self.show();
                });
                window.addEventListener('keydown', function (e) {
                    if (self.open && e.key === 'Escape') {
                        self.open = false;
                    }
                });
            },

            money: function (v) { return money(v); },
            t: function (k, f) { return t(k, f); },

            show: function () {
                this.order = readLastOrder();
                if (!this.order) {
                    notify(t('no_last_receipt', 'No receipt to reprint yet.'), 'error');
                    return;
                }
                this.open = true;
            },

            placedAtLabel: function () {
                if (!this.order || !this.order.placed_at) {
                    return '';
                }
                try {
                    return new Date(this.order.placed_at).toLocaleString();
                } catch (e) {
                    return this.order.placed_at;
                }
            },

            print: function () {
                if (this.order && this.order.order_id) {
                    printOrder(this.order.order_id, this.order.receipt_token);
                }
            },

            view: function () {
                if (this.order && this.order.order_id) {
                    window.open(
                        receiptUrl(this.order.order_id, this.order.receipt_token),
                        '_blank',
                        'noopener'
                    );
                }
            },

            newSale: function () {
                this.open = false;
                emit('pos:new-sale', {});
            },

            close: function () {
                this.open = false;
            }
        };
    };
})();
