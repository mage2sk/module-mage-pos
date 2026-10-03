/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * window.PosApi - fetch wrapper for the terminal HTTP API (spec §9/§7).
 *
 * - get(path, params) / post(path, body) against base URL from pos-config
 * - injects form_key into every POST body
 * - unwraps the pinned JSON envelope {"success","data","message"[,"code"]}:
 *   resolves with `data`, rejects (throws) {message, code, status}
 * - on network failure flags offline mode and dispatches
 *   'pos:network-offline' / 'pos:network-online' window events
 *   (consumed by app.js and offline.js).
 */
(function () {
    'use strict';

    var PosApi = {
        baseUrl: '',
        formKey: '',
        offline: false,

        /**
         * Called by app.js after parsing <script id="pos-config">.
         * @param {{baseUrl?: string, formKey?: string}} options
         */
        configure: function (options) {
            options = options || {};
            if (options.baseUrl) {
                this.baseUrl = String(options.baseUrl).replace(/\/*$/, '/');
            }
            if (options.formKey) {
                this.formKey = String(options.formKey);
            }
        },

        /**
         * Build an absolute endpoint URL for a relative path like 'auth/state'.
         * @param {string} path
         * @param {Object|null} [params] query string parameters
         * @returns {string}
         */
        url: function (path, params) {
            var endpoint = this.baseUrl + String(path).replace(/^\/+/, ''),
                query;

            if (params) {
                query = new URLSearchParams();
                Object.keys(params).forEach(function (key) {
                    if (params[key] !== undefined && params[key] !== null) {
                        query.append(key, params[key]);
                    }
                });
                if (query.toString() !== '') {
                    endpoint += (endpoint.indexOf('?') === -1 ? '?' : '&') + query.toString();
                }
            }

            return endpoint;
        },

        /**
         * GET request. Resolves with the envelope `data` field.
         * @param {string} path
         * @param {Object|null} [params]
         * @returns {Promise<*>}
         */
        get: function (path, params) {
            return this._request(this.url(path, params), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
        },

        /**
         * POST request with a JSON body; the form key is injected
         * automatically (controllers validate it manually per spec §7).
         * @param {string} path
         * @param {Object|null} [body]
         * @returns {Promise<*>}
         */
        post: function (path, body) {
            var payload = Object.assign({}, body || {}, { form_key: this.formKey });

            return this._request(this.url(path), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });
        },

        /**
         * @returns {boolean} true while the last request failed at network level
         */
        isOffline: function () {
            return this.offline;
        },

        /**
         * Shared fetch + envelope unwrap.
         * @param {string} endpoint
         * @param {Object} options
         * @returns {Promise<*>}
         * @private
         */
        _request: function (endpoint, options) {
            var self = this;

            return fetch(endpoint, options).then(function (response) {
                self._setOffline(false);

                return response.json().catch(function () {
                    throw {
                        message: self._t('invalid_response', 'Invalid server response.'),
                        code: 'invalid_response',
                        status: response.status
                    };
                }).then(function (json) {
                    if (json && json.success === true) {
                        return json.data;
                    }
                    throw {
                        message: (json && json.message) ? json.message : ('HTTP ' + response.status),
                        code: (json && json.code) ? json.code : null,
                        status: response.status
                    };
                });
            }, function () {
                // fetch() itself rejected -> network-level failure
                self._setOffline(true);
                throw {
                    message: self._t('network_error', 'Network error - check your connection.'),
                    code: 'network',
                    status: 0
                };
            });
        },

        /**
         * Flip the offline flag and notify the app via window events.
         * @param {boolean} flag
         * @private
         */
        _setOffline: function (flag) {
            if (this.offline === flag) {
                return;
            }
            this.offline = flag;
            window.dispatchEvent(new CustomEvent(flag ? 'pos:network-offline' : 'pos:network-online'));
        },

        /**
         * Translate via window.Pos when app.js has loaded, else fallback.
         * @param {string} key
         * @param {string} fallback
         * @returns {string}
         * @private
         */
        _t: function (key, fallback) {
            if (window.Pos && typeof window.Pos.t === 'function') {
                var translated = window.Pos.t(key);
                if (translated && translated !== key) {
                    return translated;
                }
            }
            return fallback;
        }
    };

    window.PosApi = PosApi;
})();
