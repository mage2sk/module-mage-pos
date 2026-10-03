/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * window.posKeypad - reusable numeric keypad Alpine component (spec §9).
 *
 * Usage:
 *   <div x-data="posKeypad({ mode: 'pin', maxLength: 8 })"
 *        @keypad-submit="doSomething($event.detail.value)">
 *       <div x-text="display"></div>
 *       <template x-for="key in keypadKeys" :key="key">
 *           <button @click="press(key)"
 *                   :class="keyClass(key)"
 *                   @pointerdown="keyDown(key)"
 *                   @pointerup="keyUp()"
 *                   @pointercancel="keyCancel()"
 *                   @pointerleave="keyCancel()"
 *                   x-text="keyLabel(key)"></button>
 *       </template>
 *       <button @click="submit()">Enter</button>
 *   </div>
 *
 * Tactile press states (optional, pointer bindings above):
 *   keyDown/keyUp/keyCancel track `pressedKey`; keyClass(key) returns the
 *   'is-pressed' state class so the CSS press treatment can also be driven
 *   programmatically (e.g. hardware-keyboard echo). Holding BACKSPACE for
 *   600ms clears the whole value (long-press clear); the synthetic click
 *   that follows the long-press is swallowed.
 *
 * Options:
 *   value      initial value (string|number)
 *   mode       'amount' (default, decimal money), 'qty' (decimal),
 *              'integer' (whole numbers), 'pin' (masked digits)
 *   maxLength  max characters, 0 = unlimited
 *   decimals   max decimal places for amount/qty (default 2)
 *   onChange   function (value, number) - also dispatched as 'keypad-change'
 *   onSubmit   function (value, number) - also dispatched as 'keypad-submit'
 *
 * Events bubble, so parents listen with @keypad-change / @keypad-submit;
 * event detail is {value: string, number: number}.
 */
(function () {
    'use strict';

    /** Hold backspace this long (ms) to clear the whole value. */
    var LONG_PRESS_MS = 600;

    window.posKeypad = function (options) {
        options = options || {};

        return {
            value: options.value !== undefined && options.value !== null ? String(options.value) : '',
            mode: options.mode || 'amount',
            maxLength: typeof options.maxLength === 'number' ? options.maxLength : 0,
            decimals: typeof options.decimals === 'number' ? options.decimals : 2,

            /** Key id currently held down (press-state class hook). */
            pressedKey: null,
            _pressTimer: null,
            _longPressFired: false,

            /** @returns {boolean} whether the current mode accepts a decimal point */
            get allowsDecimal() {
                return this.mode === 'amount' || this.mode === 'qty';
            },

            /**
             * Key grid (3 columns × 4 rows). Decimal modes swap 'clear' for
             * 'dot'; partials may render extra clear/enter buttons that call
             * clear() / submit() directly.
             * @returns {string[]}
             */
            get keypadKeys() {
                return this.allowsDecimal
                    ? ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'dot', '0', 'back']
                    : ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'back'];
            },

            /** @returns {number} numeric value (0 when empty/invalid) */
            get number() {
                var parsed = parseFloat(this.value);
                return isNaN(parsed) ? 0 : parsed;
            },

            /** @returns {string} masked value for PIN display */
            get masked() {
                return '•'.repeat(this.value.length);
            },

            /** @returns {string} what to show in the keypad display */
            get display() {
                if (this.mode === 'pin') {
                    return this.masked;
                }
                return this.value === '' ? '0' : this.value;
            },

            /**
             * Label for a key id.
             * @param {string} key
             * @returns {string}
             */
            keyLabel: function (key) {
                if (key === 'clear') {
                    return 'C';
                }
                if (key === 'back') {
                    return '⌫';
                }
                if (key === 'dot') {
                    return '.';
                }
                return key;
            },

            /**
             * Replace the current value programmatically.
             * @param {string|number|null} value
             */
            setValue: function (value) {
                this.value = value === undefined || value === null ? '' : String(value);
                this.changed();
            },

            clear: function () {
                this.value = '';
                this.changed();
            },

            backspace: function () {
                this.value = this.value.slice(0, -1);
                this.changed();
            },

            /* ------------------------------------------------ press states */

            /**
             * Whether a key is currently held (tactile animation hook).
             * @param {string} key
             * @returns {boolean}
             */
            isPressed: function (key) {
                return this.pressedKey === key;
            },

            /**
             * State class for :class bindings on keypad keys.
             * @param {string} key
             * @returns {string} 'is-pressed' while the key is held, else ''
             */
            keyClass: function (key) {
                return this.isPressed(key) ? 'is-pressed' : '';
            },

            /**
             * Pointer down on a key: arm the press state; holding 'back'
             * for LONG_PRESS_MS clears the whole value.
             * @param {string} key
             */
            keyDown: function (key) {
                this.pressedKey = key;
                this._longPressFired = false;
                this._stopPressTimer();
                if (key === 'back') {
                    var self = this;
                    this._pressTimer = window.setTimeout(function () {
                        self._longPressFired = true;
                        self._pressTimer = null;
                        self.clear();
                    }, LONG_PRESS_MS);
                }
            },

            /** Pointer released over the key - keep the long-press flag so
             *  the click that follows a long-press clear can be swallowed. */
            keyUp: function () {
                this.pressedKey = null;
                this._stopPressTimer();
            },

            /** Pointer left / was cancelled - abort everything. */
            keyCancel: function () {
                this.pressedKey = null;
                this._longPressFired = false;
                this._stopPressTimer();
            },

            _stopPressTimer: function () {
                if (this._pressTimer !== null) {
                    window.clearTimeout(this._pressTimer);
                    this._pressTimer = null;
                }
            },

            /** Alpine lifecycle - never leave a long-press timer running. */
            destroy: function () {
                this._stopPressTimer();
            },

            /**
             * Handle a key press ('0'-'9', 'dot'/'.', 'clear', 'back', 'enter').
             * @param {string} key
             */
            press: function (key) {
                if (key === 'clear') {
                    this.clear();
                    return;
                }
                if (key === 'back') {
                    if (this._longPressFired) {
                        // Long-press already cleared the value; swallow the
                        // synthetic click that follows pointerup.
                        this._longPressFired = false;
                        return;
                    }
                    this.backspace();
                    return;
                }
                if (key === 'enter') {
                    this.submit();
                    return;
                }
                if (key === 'dot' || key === '.') {
                    if (!this.allowsDecimal || this.value.indexOf('.') !== -1) {
                        return;
                    }
                    this.value = (this.value === '' ? '0' : this.value) + '.';
                    this.changed();
                    return;
                }
                if (!/^\d$/.test(key)) {
                    return;
                }
                if (this.maxLength > 0 && this.value.length >= this.maxLength) {
                    return;
                }
                if (this.allowsDecimal && this.decimals >= 0) {
                    var dot = this.value.indexOf('.');
                    if (dot !== -1 && this.value.length - dot - 1 >= this.decimals) {
                        return;
                    }
                }
                if (this.mode !== 'pin' && this.value === '0') {
                    // Replace the lone leading zero ('0' + '5' -> '5', not '05').
                    this.value = key;
                } else {
                    this.value += key;
                }
                this.changed();
            },

            changed: function () {
                this.$dispatch('keypad-change', { value: this.value, number: this.number });
                if (typeof options.onChange === 'function') {
                    options.onChange(this.value, this.number);
                }
            },

            submit: function () {
                this.$dispatch('keypad-submit', { value: this.value, number: this.number });
                if (typeof options.onSubmit === 'function') {
                    options.onSubmit(this.value, this.number);
                }
            }
        };
    };
})();
