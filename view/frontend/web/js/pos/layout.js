/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * window.PosLayout + Alpine.store('posLayout') - the free-form layout
 * system (spec §9 / UI-fix contract §18, layout.js). The user can place
 * ANY block ANYWHERE:
 *
 * - block registry {x, y, w, h, visible} in a 48×32 grid (finer snapping
 *   than the original 24×16 - contract §18d), applied to the DOM as inline
 *   grid-column/grid-row on .pos-grid > [data-panel]; the .pos-grid grid
 *   template is set inline so the finer resolution needs no pos.css change
 * - EVERY major POS block is independently movable / resizable / hideable
 *   (contract §18): the original 6 panels PLUS toolbar (the header action
 *   cluster), and the cart decomposed into cart-items / cart-totals /
 *   cart-actions (the cart tabs strip stays with cart-items)
 * - edit mode (hardened per contract §24): pointer-event drag from the
 *   block header/drag-handle only (controls inside blocks stay clickable),
 *   setPointerCapture + rAF-throttled 1:1 px follow (transform while
 *   moving, live px size while resizing), snap to the nearest cell ON
 *   DROP (gap/padding-aware grid metrics - no off-by-one, no spring),
 *   SE resize with per-block min sizes and no inversion, bounds clamped
 *   to the grid content box (never under the header), z-order (tap brings
 *   to front), collisions allowed but highlighted live (.is-colliding)
 *   against the snapped candidate rect; touch + mouse via pointer events
 * - editor-toolbar eclipse fix (contract §24 round 2): the fixed
 *   bottom-center .pos-layout-toolbar (z 220) used to swallow every
 *   pointerdown aimed at blocks beneath it (at the pinned default it
 *   covered the customer header + SE handle on both target viewports, so
 *   drag/resize of that block silently did nothing). In edit mode the
 *   toolbar container is now pointer-transparent (inline
 *   pointer-events:none) while its interactive controls (buttons,
 *   checkboxes...) individually re-enable pointer-events, so its padding /
 *   hint row / gaps no longer block grabs - hit-testing falls through to
 *   the panel header / resize handle underneath. And the instant ANY
 *   drag/resize gesture starts the whole toolbar auto-dodges (fades out,
 *   controls included, fully click-through) so it never obstructs the
 *   gesture or the drop, restoring as soon as the gesture ends. Inline
 *   styles only - no pos.css dependency (see syncEditorToolbar)
 * - per-block MIN_SIZES (contract §25) stop any block being shrunk into
 *   uselessness; applyPanels stamps data-block-size="xs|sm|md|lg" on each
 *   block so pos.css can reflow content at small rects (the block itself
 *   stays a .pos-panel flex column whose .pos-panel__body scrolls)
 * - presets: classic / mirrored / catalog-max / compact - all collision-
 *   free and INCLUDE every block; toolbar/holds/session hidden by default
 *   (contract §27: Holds/Orders/Session/Settings/Layout/Lock/Exit live
 *   compactly in the header bar; the toolbar block is opt-in via Layout)
 * - per-block visibility toggles; window event 'pos:toggle-panel'
 *   {panel}: app.js owns the flip (its $store.pos.panels boolean map);
 *   the bool bridge mirrors it into visibility here + persists (debounced);
 *   this store only toggles directly when no bridge exists
 * - FLOATING panels (holds, session): outside edit mode a visible float
 *   renders as a true overlay (css-system's .is-floating panel + a dimmed
 *   .pos-floating-overlay backdrop) so it never occludes/squeezes the static
 *   grid; in edit mode it sits on the grid so it can be placed/resized
 * - FLOATING panel close affordances (contract §13): floats close via their
 *   header X button (.pos-panel__close), Escape (top-most first), or a click
 *   on the dimmed backdrop; closing persists visible:false
 * - hidden blocks never render outside edit mode; in edit mode they are
 *   shown ghosted (.is-ghost, dimmed) at their saved rect so they can
 *   still be placed/resized - ghosts always paint BENEATH visible blocks
 *   (low z band) so an overlapping ghost can never swallow a visible
 *   block's drag handle (hit-testing follows paint order)
 * - GUARANTEES so the UI can never be bricked (contract §18): resetLayout()
 *   always restores the pinned default; presets include every block; an
 *   always-present minimal launcher in the header (header.phtml) reaches
 *   Settings/Layout even when every block is hidden, and Layout/Settings
 *   are reachable via keyboard shortcuts (Shift+L / Shift+S)
 * - persistence: POST pos/preference/save, loaded on boot / after login
 *   (GET pos/preference/load); every persisted layout is stamped with a
 *   schema version (`_v` = LAYOUT_VERSION) and saves stamped BELOW the
 *   current version (unstamped pre-§27 blobs with the default-visible
 *   toolbar / old geometry, and v2 blobs persisted before the §50
 *   content-tight retune) are demoted to the pinned defaults at load
 *   instead of being honored verbatim (then re-persisted once, stamped,
 *   when the cashier has can_edit_layout); legacy 24×16 layouts / the old
 *   monolithic `cart` rect migrations remain as defence for stamped
 *   payloads
 * - theme settings (mode, accent, density, button scale, font scale,
 *   idle-lock display, sound-on-scan) applied live and stored in theme_json.
 *
 * Used by terminal/modal-settings.phtml and terminal/modal-layout.phtml.
 * Integrates defensively with Alpine.store('pos') from app.js.
 */
(function () {
    'use strict';

    /* Finer grid (contract §18d). Internally everything is stored in these
     * units; the .pos-grid template is written inline so pos.css (24×16)
     * is overridden without editing it. */
    var GRID_COLS = 48;
    var GRID_ROWS = 32;

    /* Legacy grid the first releases shipped - used to detect + migrate
     * older saved layouts. */
    var LEGACY_COLS = 24;
    var LEGACY_ROWS = 16;

    /**
     * Layout SCHEMA VERSION, stamped into every persisted layout as `_v`
     * (contract §27 stale-save demotion). Version 2 = the §27 arrangement
     * (toolbar block hidden - the header is the action launcher; catalog
     * enlarged; right column narrowed). Saves WITHOUT the stamp predate
     * §27: their explicit toolbar.visible:true and small-catalog / wide
     * right-column rects reflect the OLD defaults (often written
     * implicitly by toggle auto-persist), NOT a §27-era cashier choice -
     * honoring them verbatim kept the duplicate toolbar + cramped catalog
     * after upgrade until a manual Reset. migrateLayout() therefore
     * DEMOTES unstamped/older saves to the pinned defaults, and load()
     * re-persists the stamped result once (when the cashier may save
     * layouts) so the server copy is upgraded. Post-upgrade saves carry
     * the stamp, so a deliberate toolbar opt-in via Layout IS honored.
     * Bump this whenever the pinned default arrangement changes in a way
     * that must supersede implicitly-saved older layouts.
     *
     * Version 3 = the §50 CONTENT-TIGHT defaults (every default-visible
     * block's height ≈ its natural content - see DEFAULT_PANELS notes).
     * v2 saves were stamped while the §21/§26/§46/§47 right-column and
     * quickkeys/customer rects were still being re-balanced, so many
     * terminals carry auto-persisted v2 blobs (any visibility toggle
     * persists the whole registry) whose oversized rects keep the "big
     * empty gaps in Totals/QuickKeys/Customer/Actions" the §50 screenshot
     * shows even though Reset already produces the tight arrangement.
     * Demoting v2 -> pinned §50 defaults lands the tight layout on every
     * terminal without a manual Reset; deliberate post-§50 layouts are
     * stamped v3 and honored verbatim.
     */
    var LAYOUT_VERSION = 3;

    /** Schema version of a raw saved layout; unstamped = 1 (pre-§27). */
    function layoutVersion(raw) {
        var v = (raw && typeof raw === 'object') ? parseInt(raw._v, 10) : NaN;

        return isFinite(v) ? v : 1;
    }

    /* Global floor for any block (unknown/future keys). The header alone
     * is ~43px (~2 rows at 1366×768) so h:4 keeps header + a sliver of
     * scrollable body usable at the smallest size. */
    var MIN_W = 6;
    var MIN_H = 4;

    /**
     * Per-block minimum sizes in grid units (contract §25 "sensible min
     * block size"): small enough for real freedom, large enough that the
     * block stays tidy - header + a scrollable body (+ pinned footer for
     * cart-actions, whose CHARGE slab alone needs ~95px ≈ 5 rows).
     */
    var MIN_SIZES = {
        toolbar: { w: 6, h: 4 },
        catalog: { w: 10, h: 6 },
        quickkeys: { w: 8, h: 5 },
        'cart-items': { w: 10, h: 6 },
        'cart-totals': { w: 8, h: 5 },
        'cart-actions': { w: 10, h: 9 },
        customer: { w: 10, h: 6 },
        holds: { w: 10, h: 6 },
        session: { w: 12, h: 8 }
    };

    function minSize(key) {
        return MIN_SIZES[key] || { w: MIN_W, h: MIN_H };
    }

    /* Every independently-placeable block. Order also drives the staggered
     * reveal and the layout-modal visibility list. */
    var PANEL_KEYS = [
        'toolbar',
        'catalog',
        'quickkeys',
        'cart-items',
        'cart-totals',
        'cart-actions',
        'customer',
        'holds',
        'session'
    ];

    /* Blocks that compose the original monolithic cart panel. A legacy
     * saved `cart` rect is split across these on migration. */
    var CART_SUBKEYS = ['cart-items', 'cart-totals', 'cart-actions'];

    /* Toggleable overlay panels: when shown OUTSIDE edit mode they render as
     * true floating overlays (css-system's .is-floating + .pos-floating-overlay
     * backdrop) so they never occlude/squeeze the static grid; in edit mode
     * they sit on the grid so they can be placed/resized. These are also the
     * panels with floating close affordances (contract §13). */
    var FLOAT_KEYS = ['holds', 'session'];

    /**
     * PINNED default geometry (48 × 32, zero overlaps among visible blocks,
     * toolbar + holds + session hidden). Re-tuned per contract §27 (on top
     * of §21/§26) so every default-visible block fits its NATURAL content
     * height at 1280×800 and 1366×768 (workspace ≈ 706-738px below the 62px
     * header; one grid row ≈ 21.7-22.7px, a rect of h rows renders
     * h·rowPitch − gridGap px tall):
     *   toolbar      HIDDEN by default (§27): it duplicated the header -
     *                Holds/Orders/Session now live compactly in the header
     *                bar (header.phtml) next to Settings/Layout/Lock/Exit.
     *                The kept rect (w:34 h:7) is the measured single-row
     *                strip from the catalog-max preset (one row of the 7
     *                icon+label buttons needs ~752px; w:34 ≈ 886-947px) so
     *                re-enabling it in Layout yields a clean, unclipped row.
     *   catalog      x:0 y:0 w:32 h:19 - BIGGER: reclaims the toolbar's 9
     *                rows at top-left plus 6 cols from the narrowed right
     *                column; search + chips stay visible, the product grid
     *                is the scroll region by design
     *   quickkeys    y:19 h:13 w:16 - the short secondary band under the
     *                tall catalog (≈40% of its height); tile grid scrolls
     *                by design
     *   customer     x:16 y:19 w:16 h:13 ≈ 270-283px beside quickkeys -
     *                search (48) + "Guest sale..." helper (~50) + Guest/New
     *                Customer row (48) + paddings ≈ 257px, so h:13 is the
     *                proven minimum at 1366×768 (§26 - do not shrink)
     *   right column NARROWER per §27: x:32 w:16 (= 8 of the 24-col guide)
     *                stacking ONLY the sale flow, 9 + 9 + 14 = 32 rows:
     *   cart-items   h:9 - tabs + line list (the list scrolls by design)
     *   cart-totals  h:9 ≈ 183-192px - header (43) + Subtotal/Tax rows +
     *                the 34px-amount grand row + body paddings ≈ 173px
     *                natural; rows sit top-aligned with normal rhythm
     *                (§47 - pos.css), extra rows (discounts/coupon) grow
     *                into the remaining ~10-19px before scrolling
     *   cart-actions h:14 ≈ 292-306px - header (~42) + 6 icon buttons in a
     *                wrapped/grid flow (at the ~410px-wide column they wrap
     *                3-per-row -> 2 rows = 120 + body pads 26) + the CHARGE
     *                footer (~95) ≈ 285px natural -> all 6 buttons + CHARGE
     *                visible at BOTH target viewports. (§50 dropped the old
     *                pos.css min-height:288px backstop - the rect fits its
     *                content naturally, and the hard floor broke free
     *                resize §44 by overflowing the grid cell below h:14)
     * CONTENT-FIT VERIFICATION (§46/§47, re-measured live for §50): the
     * right-column trio is the MINIMAL integral row split at the smaller
     * target viewport - at 1366×768 (rowPitch 21.7) cart-actions h:13
     * renders 270px < its ~285px natural and cart-totals h:8 renders
     * 162px < its ~180px natural, so 9 + 9 + 14 is already "fits content,
     * no spare row to shave"; CHARGE following the buttons and the
     * top-aligned totals rows (pos.css/panel-cart.phtml, §46/§47) make
     * the residual slack read as normal bottom padding, and on taller
     * viewports - where fr tracks scale every block - quick resize
     * (§44) lets the cashier reclaim the growth in one gesture.
     * §50 LIVE MEASUREMENTS (headless, pinned default, panel px vs
     * natural content px = header + body content + footer):
     *   1366×768: cart-totals 182/180 · cart-actions 290/285 ·
     *             customer 269/267 · catalog 398 (tallest, scroll
     *             region) · quickkeys 269 (tile grid scrolls ≈2 rows)
     *   1280×800: every block's slack ≤ 19px (< one grid row), so no
     *             h can shrink without clipping at either viewport.
     * Every default-visible block is content-tight - no mostly-empty
     * box; catalog stays the tallest block (it is the main work area).
     * Saves stamped before this arrangement are demoted via
     * LAYOUT_VERSION (v3) so the tight default reaches every terminal
     * without a manual Reset. Reset restores exactly this arrangement
     * (§27/§50). Do not change without updating the UI-fix contract.
     */
    var DEFAULT_PANELS = {
        toolbar: { x: 0, y: 0, w: 34, h: 7, visible: false },
        catalog: { x: 0, y: 0, w: 32, h: 19, visible: true },
        quickkeys: { x: 0, y: 19, w: 16, h: 13, visible: true },
        customer: { x: 16, y: 19, w: 16, h: 13, visible: true },
        'cart-items': { x: 32, y: 0, w: 16, h: 9, visible: true },
        'cart-totals': { x: 32, y: 9, w: 16, h: 9, visible: true },
        'cart-actions': { x: 32, y: 18, w: 16, h: 14, visible: true },
        holds: { x: 10, y: 8, w: 18, h: 16, visible: false },
        session: { x: 24, y: 8, w: 18, h: 20, visible: false }
    };

    /* Every preset is collision-free among its visible blocks, includes
     * EVERY block (hidden blocks keep sane rects so re-enabling them never
     * lands off-grid), respects MIN_SIZES, and keeps toolbar/holds/session
     * hidden (§27: the header is the always-present action launcher; the
     * toolbar block is opt-in via Layout). Hidden toolbar rects everywhere
     * use the measured single-row strip (w:34 h:7 - one row of the 7
     * icon+label buttons ≈ 752px fits at w:34 ≈ 886-947px) so re-enabling
     * it never clips. Vertical budgets mirror the pinned defaults so no
     * preset re-clips cart-actions / customer (contract §26). */
    var PRESETS = {
        classic: clone(DEFAULT_PANELS),
        mirrored: {
            'cart-items': { x: 0, y: 0, w: 16, h: 9, visible: true },
            'cart-totals': { x: 0, y: 9, w: 16, h: 9, visible: true },
            'cart-actions': { x: 0, y: 18, w: 16, h: 14, visible: true },
            catalog: { x: 16, y: 0, w: 32, h: 19, visible: true },
            quickkeys: { x: 16, y: 19, w: 16, h: 13, visible: true },
            customer: { x: 32, y: 19, w: 16, h: 13, visible: true },
            toolbar: { x: 14, y: 0, w: 34, h: 7, visible: false },
            holds: { x: 10, y: 8, w: 18, h: 16, visible: false },
            session: { x: 24, y: 8, w: 18, h: 20, visible: false }
        },
        'catalog-max': {
            toolbar: { x: 0, y: 0, w: 34, h: 7, visible: false },
            catalog: { x: 0, y: 0, w: 34, h: 32, visible: true },
            'cart-items': { x: 34, y: 0, w: 14, h: 9, visible: true },
            'cart-totals': { x: 34, y: 9, w: 14, h: 9, visible: true },
            'cart-actions': { x: 34, y: 18, w: 14, h: 14, visible: true },
            quickkeys: { x: 0, y: 19, w: 16, h: 13, visible: false },
            customer: { x: 16, y: 19, w: 16, h: 13, visible: false },
            holds: { x: 10, y: 8, w: 18, h: 16, visible: false },
            session: { x: 24, y: 8, w: 18, h: 20, visible: false }
        },
        compact: {
            toolbar: { x: 0, y: 0, w: 34, h: 7, visible: false },
            catalog: { x: 0, y: 0, w: 26, h: 32, visible: true },
            'cart-items': { x: 26, y: 0, w: 22, h: 9, visible: true },
            'cart-totals': { x: 26, y: 9, w: 22, h: 9, visible: true },
            'cart-actions': { x: 26, y: 18, w: 22, h: 14, visible: true },
            quickkeys: { x: 0, y: 19, w: 16, h: 13, visible: false },
            customer: { x: 16, y: 19, w: 16, h: 13, visible: false },
            holds: { x: 10, y: 8, w: 18, h: 16, visible: false },
            session: { x: 24, y: 8, w: 18, h: 20, visible: false }
        }
    };

    var DEFAULT_PRESET = 'classic';

    var THEME_DEFAULTS = {
        mode: 'light',
        accent: '#ff6b4a', // signature coral-tangerine - matches pos.css --pos-accent
        density: 'comfortable',
        btn_scale: 1,
        font_scale: 1,
        idle_lock_enabled: true,
        sound_on_scan: true
    };

    /* Settings swatch ramp. The brand default MUST come first so the
     * signature accent is always reachable from Settings; the rest are
     * vivid, color-mix-friendly alternates (pos.css derives hover /
     * pressed / soft / glow shades from whichever accent is set). */
    var ACCENTS = [
        '#ff6b4a', '#ff8f3d', '#f7b32b', '#3ddc97', '#22b8b2',
        '#5aa9ff', '#7b6cff', '#ff5fa2', '#5c6675'
    ];

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function toNumber(value, fallback) {
        var n = parseFloat(value);
        return isFinite(n) ? n : fallback;
    }

    function t(key, fallback) {
        if (window.Pos && typeof window.Pos.t === 'function') {
            var translated = window.Pos.t(key);
            if (translated && translated !== key) {
                return translated;
            }
        }
        return fallback;
    }

    function posStore() {
        try {
            return window.Alpine ? window.Alpine.store('pos') : null;
        } catch (e) {
            return null;
        }
    }

    function readBootConfig() {
        try {
            var el = document.getElementById('pos-config');
            return el ? (JSON.parse(el.textContent) || {}) : {};
        } catch (e) {
            return {};
        }
    }

    /**
     * Normalise one raw panel entry to a valid {x,y,w,h,visible}. The
     * per-block minimum (MIN_SIZES) is enforced here too, so saved layouts
     * from older releases that allowed tinier rects are bumped up
     * additively on load (contract §25).
     */
    function normalizePanel(raw, fallback, key) {
        raw = (raw && typeof raw === 'object') ? raw : {};
        var min = minSize(key);
        var w = clamp(Math.round(toNumber(raw.w, fallback.w)), min.w, GRID_COLS);
        var h = clamp(Math.round(toNumber(raw.h, fallback.h)), min.h, GRID_ROWS);

        return {
            x: clamp(Math.round(toNumber(raw.x, fallback.x)), 0, GRID_COLS - w),
            y: clamp(Math.round(toNumber(raw.y, fallback.y)), 0, GRID_ROWS - h),
            w: w,
            h: h,
            visible: raw.visible === undefined ? !!fallback.visible : !!raw.visible
        };
    }

    function overlaps(a, b) {
        return a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
    }

    /**
     * Coarse size bucket for a rect (grid units) - stamped on the block as
     * data-block-size so pos.css can reflow content when a block is small
     * (contract §25). Buckets: xs ≲ header + one row, sm = tight, md =
     * default, lg = generous.
     */
    function blockSize(p) {
        if (p.h <= 5 || p.w <= 8) {
            return 'xs';
        }
        if (p.h <= 8 || p.w <= 12) {
            return 'sm';
        }
        if (p.h >= 18 && p.w >= 22) {
            return 'lg';
        }
        return 'md';
    }

    /**
     * Is any blocking modal/overlay actually on screen right now? Modals use
     * Alpine x-show, which writes display:none when closed (-> no client
     * rects), so a getClientRects() probe reliably tells open from closed
     * without coupling to any component's internal flag. Used so Escape lets
     * an open modal win over closing a floating panel (contract §13b).
     */
    function isOverlayVisible() {
        var nodes = document.querySelectorAll(
            '.pos-modal-backdrop, .pos-checkout, .pos-receipt-modal'
        );

        for (var i = 0; i < nodes.length; i += 1) {
            if (nodes[i].getClientRects().length) {
                return true;
            }
        }
        return false;
    }

    /**
     * Migrate a raw saved layout to the current registry. Handles two
     * legacy shapes additively (live customer sites have saved layouts):
     *   1. A 24×16 layout (no new blocks, rects fit the old grid) -> scale
     *      every rect ×2 into 48×32.
     *   2. A monolithic `cart` block (no cart-items) -> split its rect into
     *      cart-items (top ~60%) / cart-totals / cart-actions; the toolbar
     *      block stays hidden (§27 - its actions live in the header) unless
     *      the saved layout explicitly set it visible.
     * Returns a registry keyed by the current PANEL_KEYS.
     */
    function migrateLayout(raw) {
        raw = (raw && typeof raw === 'object') ? raw : {};

        // Stale-save demotion (contract §27): a save without the current
        // `_v` stamp was written when the toolbar block was default-visible
        // and the catalog/right-column used the old geometry - it carries
        // the OLD arrangement, not a current-era choice. Demote it to the
        // pinned §27 defaults (the same rects Reset restores); load()
        // upgrades the server copy once. The legacy 24×16 / monolithic-cart
        // shapes below are all unstamped too, so they take this path -
        // the scaling/split logic stays as defence for stamped payloads.
        if (layoutVersion(raw) < LAYOUT_VERSION) {
            return clone(DEFAULT_PANELS);
        }

        var hasNew = CART_SUBKEYS.some(function (key) {
            return raw[key] && typeof raw[key] === 'object';
        });
        var hasLegacyCart = raw.cart && typeof raw.cart === 'object' && !hasNew;

        // detect old 24×16 coordinate space: nothing exceeds the legacy
        // bounds and the new (finer-grid) blocks are absent
        var legacyScale = !hasNew && Object.keys(raw).every(function (key) {
            var r = raw[key];
            if (!r || typeof r !== 'object') {
                return true;
            }
            return toNumber(r.x, 0) + toNumber(r.w, 0) <= LEGACY_COLS
                && toNumber(r.y, 0) + toNumber(r.h, 0) <= LEGACY_ROWS;
        });

        var scale = (legacyScale && (GRID_COLS / LEGACY_COLS)) || 1;

        // When visibility is unset in a saved rect, fall back to the pinned
        // default for THAT block (floats default hidden) rather than a blanket
        // `true` - a blanket true would silently re-open holds/session (and
        // raise the dimmed .pos-floating-overlay that blocks the workspace)
        // on a cold boot. The default-visible static blocks still default on.
        function scaled(r, key) {
            var def = DEFAULT_PANELS[key] || {};
            return {
                x: Math.round(toNumber(r.x, 0) * scale),
                y: Math.round(toNumber(r.y, 0) * scale),
                w: Math.round(toNumber(r.w, MIN_W) * scale),
                h: Math.round(toNumber(r.h, MIN_H) * scale),
                visible: r.visible === undefined ? !!def.visible : !!r.visible
            };
        }

        var out = clone(DEFAULT_PANELS);

        // straightforward keys present in both old + new schemas
        ['catalog', 'quickkeys', 'customer', 'holds', 'session', 'toolbar'].forEach(function (key) {
            if (raw[key] && typeof raw[key] === 'object') {
                out[key] = scaled(raw[key], key);
            }
        });

        if (hasNew) {
            CART_SUBKEYS.forEach(function (key) {
                if (raw[key] && typeof raw[key] === 'object') {
                    out[key] = scaled(raw[key], key);
                }
            });
        } else if (hasLegacyCart) {
            // split the old cart column into items / totals / actions
            var c = scaled(raw.cart, 'cart-items');
            var itemsH = Math.max(MIN_H, Math.round(c.h * 0.55));
            var totalsH = Math.max(MIN_H, Math.round((c.h - itemsH) / 2));
            var actionsH = Math.max(MIN_H, c.h - itemsH - totalsH);

            out['cart-items'] = { x: c.x, y: c.y, w: c.w, h: itemsH, visible: c.visible };
            out['cart-totals'] = { x: c.x, y: c.y + itemsH, w: c.w, h: totalsH, visible: c.visible };
            out['cart-actions'] = { x: c.x, y: c.y + itemsH + totalsH, w: c.w, h: actionsH, visible: c.visible };
        }

        return out;
    }

    /* ------------------------------------------------------------
     * The store
     * ---------------------------------------------------------- */
    function createLayoutStore() {
        return {
            panels: clone(PRESETS[DEFAULT_PRESET]),
            theme: clone(THEME_DEFAULTS),
            accents: ACCENTS.slice(),
            presets: ['classic', 'mirrored', 'catalog-max', 'compact'],
            editMode: false,
            saving: false,
            loaded: false,

            /** internal */
            _fallbackModals: { settings: false, layout: false },
            _snapshot: null,
            _zMap: {},
            _zTop: 10,
            _boolBridge: false,
            _bridged: false,
            _persistTimer: null,
            _revealTimer: null,
            /* open-order of FLOAT_KEYS - last entry is the top-most floating
             * panel that Escape / outside-click closes first (contract §13) */
            _floatStack: [],

            /* ---------------- boot ---------------- */

            init: function () {
                var self = this;

                this.applyTheme();
                this._applyGridTemplate();
                this._bridgePosStore();
                // app.js registers Alpine.store('pos') after this store
                // (pinned script order) - retry the bridge once Alpine
                // has finished booting every store.
                setTimeout(function () {
                    self._bridgePosStore();
                    self.applyPanels();
                }, 0);
                this.applyPanels();
                this._beginReveal();
                attachPointerHandling();

                window.addEventListener('resize', function () {
                    // grid metrics captured at gesture start go stale when
                    // the viewport changes - abort (revert) any active drag
                    abortDrag();
                    self.applyPanels();
                });

                // load saved preferences once the cashier is known; the
                // workspace becomes visible here, so this is when the
                // staggered panel reveal actually plays
                window.addEventListener('pos:authenticated', function () {
                    self._bridgePosStore();
                    self._beginReveal();
                    self.load();
                });

                // header "Holds"/"Session" buttons (and anything else)
                // toggle panel visibility via this window event. app.js
                // ALSO listens and flips its $store.pos.panels boolean
                // (togglePanel) - exactly ONE owner may react, otherwise
                // the two listeners cancel each other out. When the boolean
                // bridge is live, app.js owns the flip and the Alpine.effect
                // installed by _bridgePosStore mirrors the new boolean into
                // visibility (+ debounced persist); this listener only
                // ADOPTS the settled value on the next tick as a safety net,
                // and only toggles locally when no $store.pos boolean map
                // exists. Either way the float-stack is kept in sync.
                window.addEventListener('pos:toggle-panel', function (event) {
                    var panel = event && event.detail && event.detail.panel;

                    if (!panel || !self.panels[panel]) {
                        return;
                    }

                    self._bridgePosStore();

                    var pos = posStore();
                    var bridged = self._boolBridge && pos && pos.panels
                        && typeof pos.panels[panel] === 'boolean';

                    if (!bridged) {
                        self.toggleVisible(panel);
                        return;
                    }

                    // app.js togglePanel runs in its own listener; after
                    // every sync listener has fired, adopt the settled value
                    setTimeout(function () {
                        var flag = pos.panels[panel];

                        if (typeof flag === 'boolean' && self.panels[panel].visible !== flag) {
                            self.toggleVisible(panel, flag);
                        } else {
                            self._trackFloat(panel, !!self.panels[panel].visible);
                        }
                    }, 0);
                });

                // header edit-mode toggle (app.js toggleLayoutEdit) - keep
                // drag/resize working no matter which flag flips first
                window.addEventListener('pos:layout-edit', function (event) {
                    var enabled = !!(event && event.detail && event.detail.enabled);

                    if (enabled === self.editMode) {
                        return;
                    }
                    if (enabled) {
                        self.enterEdit();
                    } else {
                        self._snapshot = null;
                        self.setEdit(false);
                        self._schedulePersist();
                    }
                });

                // audible scan feedback (catalog.js dispatches pos:scan)
                window.addEventListener('pos:scan', function () {
                    self.beep();
                });

                // interop hooks for any component that prefers events
                window.addEventListener('pos:open-settings', function () {
                    self.openSettings();
                });
                window.addEventListener('pos:open-layout', function () {
                    self.openLayout();
                });

                /* Floating-panel close affordances (contract §13b/§13c). */
                // (b) Escape closes the top-most floating panel first; if
                //     none are open it falls through to any open modal
                //     (app.js owns modal Escape, so we only act on floaters).
                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape' || event.keyCode === 27) {
                        // a modal owns Escape while it is open; don't steal it.
                        // detect an actually-visible overlay (x-show writes
                        // display:none when closed -> no client rects)
                        if (!self.editMode && !isOverlayVisible() && self.closeTopFloat()) {
                            event.stopPropagation();
                            return;
                        }
                    }
                    // Always-reachable Layout / Settings shortcuts so the UI
                    // can never be bricked when every block is hidden
                    // (contract §18c). Ignore while typing in a field.
                    if (event.shiftKey && !event.metaKey && !event.ctrlKey && !event.altKey) {
                        var tag = (event.target && event.target.tagName) || '';
                        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(tag)
                            || (event.target && event.target.isContentEditable);

                        if (!typing && (event.key === 'L' || event.key === 'l')) {
                            event.preventDefault();
                            self.openLayout();
                        } else if (!typing && (event.key === 'S' || event.key === 's')) {
                            event.preventDefault();
                            self.openSettings();
                        }
                    }
                });

                // (c) clicking outside a floating panel closes it - handled by
                //     the dimmed .pos-floating-overlay backdrop (created in
                //     _applyFloatingOverlay) whose own pointerdown calls
                //     closeTopFloat(). The backdrop covers the whole workspace
                //     below the header and sits beneath the panel, so a click
                //     on the panel never reaches it. No document-level handler
                //     is needed (and would double-close).

                // opportunistic first load (no-op when not yet authenticated)
                this.load();
            },

            /* ---------------- modal interop ---------------- */

            isModalOpen: function (name) {
                var pos = posStore();

                if (pos) {
                    if (pos.modal === name || pos.activeModal === name) {
                        return true;
                    }
                    if (pos.modals && typeof pos.modals === 'object' && pos.modals[name]) {
                        return true;
                    }
                }

                return !!this._fallbackModals[name];
            },

            closeModal: function (name) {
                var pos = posStore();

                this._fallbackModals[name] = false;

                if (!pos) {
                    return;
                }
                if (typeof pos.closeModal === 'function') {
                    pos.closeModal(name);
                    return;
                }
                if (pos.modal === name) {
                    pos.modal = null;
                }
                if (pos.activeModal === name) {
                    pos.activeModal = null;
                }
                if (pos.modals && typeof pos.modals === 'object' && pos.modals[name]) {
                    pos.modals[name] = false;
                }
            },

            openModal: function (name) {
                var pos = posStore();

                if (pos && typeof pos.openModal === 'function') {
                    pos.openModal(name);
                    return;
                }
                this._fallbackModals[name] = true;
            },

            openSettings: function () {
                this.openModal('settings');
            },

            closeSettings: function () {
                this.closeModal('settings');
            },

            openLayout: function () {
                if (!this.canEditLayout()) {
                    this.notify(t('layout_denied', 'You are not allowed to edit the layout.'), 'error');
                    return;
                }
                this.openModal('layout');
            },

            canEditLayout: function () {
                var pos = posStore();

                if (pos && typeof pos.hasPermission === 'function') {
                    return !!pos.hasPermission('can_edit_layout');
                }

                return true; // server still enforces on save
            },

            notify: function (message, type) {
                var pos = posStore();

                if (pos && typeof pos.notify === 'function') {
                    pos.notify(message, type || 'info');
                }
            },

            /* ---------------- floating panels (contract §13) ---------------- */

            /** Track open-order of FLOAT_KEYS so Escape / outside-click can
             *  close the visually top-most floating panel first. */
            _trackFloat: function (key, visible) {
                if (FLOAT_KEYS.indexOf(key) === -1) {
                    return;
                }
                var idx = this._floatStack.indexOf(key);

                if (visible) {
                    if (idx !== -1) {
                        this._floatStack.splice(idx, 1);
                    }
                    this._floatStack.push(key);
                } else if (idx !== -1) {
                    this._floatStack.splice(idx, 1);
                }
            },

            /** Close the top-most open floating panel; returns true if one
             *  was closed (so the caller can stop further Escape handling). */
            closeTopFloat: function () {
                // walk from the top, skipping any that drifted closed
                for (var i = this._floatStack.length - 1; i >= 0; i -= 1) {
                    var key = this._floatStack[i];

                    if (this.panels[key] && this.panels[key].visible) {
                        this.closePanel(key);
                        return true;
                    }
                    this._floatStack.splice(i, 1);
                }
                return false;
            },

            /** Explicit close for a floating panel (header X, Escape,
             *  outside-click). Routes through the SAME path as the header
             *  toggle (the pos:toggle-panel event) so app.js stays the single
             *  owner of the boolean flip and visible:false persists. When no
             *  $store.pos boolean bridge exists this store toggles directly. */
            closePanel: function (key) {
                if (!this.panels[key] || !this.panels[key].visible) {
                    return;
                }

                var pos = posStore();
                var bridged = this._boolBridge && pos && pos.panels
                    && typeof pos.panels[key] === 'boolean';

                // drop it from the float stack immediately so a rapid second
                // Escape / outside-click targets the next panel down
                this._trackFloat(key, false);

                if (bridged) {
                    window.dispatchEvent(new CustomEvent('pos:toggle-panel', { detail: { panel: key } }));
                } else {
                    this.toggleVisible(key, false);
                }
            },

            /* ---------------- panel registry ---------------- */

            setPanels: function (raw) {
                var self = this;

                if (!raw || typeof raw !== 'object') {
                    return;
                }
                PANEL_KEYS.forEach(function (key) {
                    var fallback = self.panels[key] || PRESETS[DEFAULT_PRESET][key];
                    Object.assign(self.panels[key], normalizePanel(raw[key], fallback, key));
                });
                this._syncFloatStack();
                this.applyPanels();
            },

            plainPanels: function () {
                // `_v` stamps the persisted payload with the layout schema
                // version (§27 stale-save demotion). setPanels()/normalize
                // iterate PANEL_KEYS only, so the extra key is inert when a
                // snapshot is re-applied; the server stores the JSON blob
                // verbatim, so it round-trips back into preference/load.
                var out = { _v: LAYOUT_VERSION };
                var self = this;

                PANEL_KEYS.forEach(function (key) {
                    var p = self.panels[key];
                    out[key] = { x: p.x, y: p.y, w: p.w, h: p.h, visible: !!p.visible };
                });

                return out;
            },

            applyPreset: function (name) {
                if (!PRESETS[name]) {
                    return;
                }
                this.setPanels(clone(PRESETS[name]));
                this._zMap = {};
                this.applyPanels();
                this._syncVisibilityToPos();
                if (!this.editMode) {
                    this._schedulePersist();
                }
            },

            resetLayout: function () {
                this.applyPreset(DEFAULT_PRESET);
            },

            toggleVisible: function (key, visible) {
                if (!this.panels[key]) {
                    return;
                }
                this.panels[key].visible = visible === undefined
                    ? !this.panels[key].visible
                    : !!visible;
                this._trackFloat(key, this.panels[key].visible);
                this.applyPanels();
                this._syncVisibilityToPos();
                // Outside edit mode the toggle is committed immediately
                // (debounced); inside edit mode Save/Cancel decides.
                if (!this.editMode) {
                    this._schedulePersist();
                }
            },

            bringToFront: function (key) {
                this._zTop += 1;
                this._zMap[key] = this._zTop;
                this.applyPanels();
            },

            panelElement: function (key) {
                return document.querySelector('.pos-grid [data-panel="' + key + '"]')
                    || document.getElementById('pos-panel-' + key);
            },

            /** Rebuild the float open-order from current visibility (used
             *  after a bulk setPanels / preset / load). */
            _syncFloatStack: function () {
                var self = this;

                this._floatStack = this._floatStack.filter(function (key) {
                    return self.panels[key] && self.panels[key].visible;
                });
                FLOAT_KEYS.forEach(function (key) {
                    if (self.panels[key] && self.panels[key].visible
                        && self._floatStack.indexOf(key) === -1) {
                        self._floatStack.push(key);
                    }
                });
            },

            /** Write the finer grid template inline so pos.css's 24×16
             *  template is overridden without editing it (contract §18d). */
            _applyGridTemplate: function () {
                var grid = document.getElementById('pos-grid')
                    || document.querySelector('.pos-grid');

                if (!grid) {
                    return;
                }
                // pos.css doubles the edit-mode snap-grid visual density
                // for this flag (48×32 instead of 24×16)
                grid.classList.add('pos-grid--fine');
                // skip on the mobile single-column fallback (grid becomes a
                // flex column there - see pos.css media query)
                if (window.matchMedia && window.matchMedia('(max-width: 900px)').matches) {
                    grid.style.gridTemplateColumns = '';
                    grid.style.gridTemplateRows = '';
                    return;
                }
                grid.style.gridTemplateColumns = 'repeat(' + GRID_COLS + ', 1fr)';
                grid.style.gridTemplateRows = 'repeat(' + GRID_ROWS + ', 1fr)';
            },

            /**
             * Which FLOAT_KEYS render as true floating overlays right now?
             * Outside edit mode every VISIBLE float floats (css-system's
             * .is-floating overlay convention - contract §12/§13). In edit
             * mode floats stay on the grid so they can be placed/resized.
             */
            _floatingKeys: function () {
                var self = this;

                if (this.editMode) {
                    return [];
                }
                return FLOAT_KEYS.filter(function (key) {
                    return self.panels[key] && self.panels[key].visible;
                });
            },

            applyPanels: function () {
                var self = this;

                this._applyGridTemplate();

                var floating = this._floatingKeys();

                /* Paint-order BANDS (round-1 fix): edit-mode ghosts must
                 * never occlude a visible block's drag handle - pointer
                 * hit-testing follows paint order, so the session/holds
                 * ghost rect over e.g. the cart-actions header used to
                 * swallow its pointerdown and bringToFront() then raised
                 * the GHOST. Ghosts now always paint BENEATH every visible
                 * block (band 1..n) and visible blocks keep their
                 * tap-brings-to-front order in a higher band (21..n).
                 * Ranks are re-normalised from _zMap on every apply so z
                 * never creeps toward the floating backdrop (90) / panel
                 * (95) / modal (200) layers; .is-floating panels get NO
                 * inline z so css-system's fixed z:95 (above its z:90
                 * backdrop) always wins. */
                var zOrder = PANEL_KEYS.slice().sort(function (a, b) {
                    return (self._zMap[a] || 0) - (self._zMap[b] || 0);
                });
                var zRank = {};

                zOrder.forEach(function (k, i) {
                    zRank[k] = i + 1;
                });

                PANEL_KEYS.forEach(function (key) {
                    var el = self.panelElement(key);
                    var p = self.panels[key];

                    if (!el || !p) {
                        return;
                    }

                    var ghost = !p.visible && self.editMode;
                    var isFloat = floating.indexOf(key) !== -1;

                    // css-system positions .is-floating overlays itself
                    // (fixed, right-anchored, viewport-clamped - grid-column/
                    // row are neutralised there with !important), so the inline
                    // grid placement below is harmless for floats.
                    el.style.gridColumn = (p.x + 1) + ' / span ' + p.w;
                    el.style.gridRow = (p.y + 1) + ' / span ' + p.h;
                    el.style.display = (p.visible || ghost) ? '' : 'none';
                    el.style.zIndex = isFloat ? '' : String(ghost ? zRank[key] : 20 + zRank[key]);
                    el.classList.toggle('is-ghost', ghost);
                    el.classList.toggle('is-floating', isFloat);
                    // UIFIX §33 round 2: pos.css right-anchors EVERY
                    // .is-floating overlay, which parked the Held Carts
                    // float exactly over the right sale column - at the
                    // default 1440×900 layout its subtree sat on the
                    // cart-actions row and swallowed clicks on the Hold
                    // button (Playwright: '#pos-panel-holds subtree
                    // intercepts pointer events'), so holding a SECOND
                    // cart meant closing/moving the panel first. Anchor
                    // the holds float to the LEFT edge instead (same
                    // spacing token, mirrored): the sale column is no
                    // longer occluded by dead panel chrome - a tap there
                    // hits the close-on-outside backdrop (panel closes,
                    // workflow continues) rather than the panel itself.
                    // Session keeps the css-system default; the inline
                    // overrides are cleared whenever the panel docks back
                    // onto the grid (edit mode / hidden).
                    if (key === 'holds') {
                        el.style.left = isFloat ? 'var(--pos-sp-3)' : '';
                        el.style.right = isFloat ? 'auto' : '';
                    }
                    el.style.opacity = ghost ? '0.4' : '';
                    // size hint for pos.css so content can reflow gracefully
                    // at small rects (contract §25): tighter paddings, fewer
                    // grid columns, ellipsised labels etc. The block itself
                    // remains a .pos-panel flex column; .pos-panel__body is
                    // the scroll region at every size.
                    el.setAttribute('data-block-size', blockSize(p));

                    // SE resize handle - GUARANTEED on every block (§44/§51):
                    // injected at runtime on each geometry pass so no panel
                    // template ever needs to ship one (and a handle an Alpine
                    // re-render might drop is re-created on the next apply).
                    // touch-action:none is (re-)pinned inline even on a
                    // template-provided handle so pointermove keeps firing
                    // for the gesture on touch; pos.css gives the handle its
                    // corner position + a z-index above pinned footers /
                    // CHARGE so it is always hit-testable (§51). The same
                    // delegated pointerdown engine below serves every
                    // block's handle - catalog, quickkeys, customer, the
                    // three cart sub-blocks, toolbar, holds, session.
                    var handle = el.querySelector('.pos-panel__resize');

                    if (!handle) {
                        handle = document.createElement('div');
                        handle.className = 'pos-panel__resize';
                        handle.setAttribute('aria-hidden', 'true');
                        el.appendChild(handle);
                    }
                    handle.style.touchAction = 'none';
                });

                this._applyFloatingOverlay(floating.length > 0);
                // QUICK RESIZE permission hook (contract §44): pos.css only
                // reveals the hover SE resize handles (and the grab cursor
                // affordance) outside edit mode under this body class, so
                // cashiers without can_edit_layout - whose quick gestures
                // the pointer engine refuses anyway - never see a dead
                // affordance. Re-synced on every geometry pass because the
                // permission set arrives with login/auth, after init.
                document.body.classList.toggle(
                    'pos-body--can-edit-layout', this.canEditLayout()
                );
                this.updateCollisions();
                // editor-toolbar eclipse fix (contract §24 round 2): keep the
                // fixed .pos-layout-toolbar pointer-transparent outside its
                // controls while editing, and fully dodged during a gesture -
                // re-synced here so enter/exit edit, gesture end (finishDrag ->
                // applyPanels) and Alpine re-renders all settle correctly
                syncEditorToolbar(this.editMode, !!drag);
            },

            /**
             * Maintain the single dimmed backdrop behind floating overlays
             * (css-system's .pos-floating-overlay). Clicking it closes the
             * top-most floating panel (contract §13c). Created lazily and
             * removed when no float is open.
             */
            _applyFloatingOverlay: function (show) {
                var self = this;
                var el = document.getElementById('pos-floating-overlay');

                if (!show) {
                    if (el) {
                        el.parentNode.removeChild(el);
                    }
                    return;
                }
                if (!el) {
                    el = document.createElement('div');
                    el.id = 'pos-floating-overlay';
                    el.className = 'pos-floating-overlay';
                    el.setAttribute('aria-hidden', 'true');
                    el.addEventListener('pointerdown', function (event) {
                        event.preventDefault();
                        self.closeTopFloat();
                    });
                    document.body.appendChild(el);
                }
            },

            /**
             * Collisions are allowed but highlighted while editing. During
             * an active drag the engine passes the SNAPPED candidate rect of
             * the dragged block (activeKey/override) so the highlight tracks
             * exactly where the block would land, not where it started
             * (contract §24 "accurate overlap highlight").
             */
            updateCollisions: function (activeKey, override) {
                var self = this;
                var rects = {};

                PANEL_KEYS.forEach(function (key) {
                    rects[key] = (key === activeKey && override) ? override : self.panels[key];
                });

                var visible = PANEL_KEYS.filter(function (key) {
                    return rects[key] && rects[key].visible;
                });

                // §44 round 2: the overlap highlight also shows LIVE during
                // an engaged quick (out-of-edit) gesture - previously it was
                // edit-mode-only, so a quick resize gave no hint it was about
                // to land on a neighbor. Outside any gesture, non-edit mode
                // stays highlight-free as before (quick-resize drops resolve
                // their overlaps in finishDrag, so none persist anyway).
                var quickLive = !!(drag && drag.quick && drag.started);

                PANEL_KEYS.forEach(function (key) {
                    if (!rects[key]) {
                        return;
                    }

                    var colliding = rects[key].visible && visible.some(function (other) {
                        return other !== key && overlaps(rects[key], rects[other]);
                    });
                    var el = self.panelElement(key);

                    if (el) {
                        el.classList.toggle('is-colliding', (self.editMode || quickLive) && colliding);
                    }
                });
            },

            /* ---------------- edit mode ---------------- */

            enterEdit: function () {
                if (this.editMode) {
                    return;
                }
                this._snapshot = this.plainPanels();
                this.setEdit(true);
            },

            cancelEdit: function () {
                if (this._snapshot) {
                    this.setPanels(this._snapshot);
                    this._snapshot = null;
                }
                this.setEdit(false);
                this.closeModal('layout');
            },

            saveAndExit: function () {
                var self = this;

                this.saving = true;
                this.persist({ layout: this.plainPanels(), theme: this.plainTheme() })
                    .then(function () {
                        self._snapshot = null;
                        self.notify(t('layout_saved', 'Layout saved.'), 'success');
                        self.setEdit(false);
                        self.closeModal('layout');
                    })
                    .catch(function (error) {
                        self.notify((error && error.message) || t('save_failed', 'Could not save.'), 'error');
                    })
                    .finally(function () {
                        self.saving = false;
                    });
            },

            setEdit: function (flag) {
                flag = !!flag;
                if (this.editMode === flag) {
                    return;
                }
                this.editMode = flag;

                var pos = posStore();

                if (pos && 'layoutEdit' in pos) {
                    pos.layoutEdit = flag;
                }

                var grid = document.getElementById('pos-grid')
                    || document.querySelector('.pos-grid');

                if (grid) {
                    grid.classList.toggle('is-editing', flag);
                }
                document.body.classList.toggle('pos-body--edit', flag);
                // re-apply geometry so hidden panels ghost in / out with
                // the edit-mode flag (and collisions re-evaluate)
                this.applyPanels();
            },

            _beginReveal: function () {
                var self = this;

                document.body.classList.add('pos-body--revealing');

                if (this._revealTimer) {
                    clearTimeout(this._revealTimer);
                }
                this._revealTimer = setTimeout(function () {
                    self._revealTimer = null;
                    document.body.classList.remove('pos-body--revealing');
                }, 800);
            },

            /* ---------------- theme ---------------- */

            setTheme: function (patch) {
                patch = patch || {};

                if (patch.mode !== undefined) {
                    this.theme.mode = patch.mode === 'dark' ? 'dark' : 'light';
                }
                if (patch.theme !== undefined && patch.mode === undefined) {
                    this.theme.mode = patch.theme === 'dark' ? 'dark' : 'light';
                }
                if (patch.accent !== undefined) {
                    var accent = String(patch.accent);
                    if (/^#[0-9a-f]{3}([0-9a-f]{3})?$/i.test(accent)) {
                        this.theme.accent = accent.toLowerCase();
                    }
                }
                if (patch.density !== undefined) {
                    this.theme.density = patch.density === 'compact' ? 'compact' : 'comfortable';
                }
                if (patch.btn_scale !== undefined) {
                    this.theme.btn_scale = clamp(toNumber(patch.btn_scale, 1), 0.8, 1.5);
                }
                if (patch.font_scale !== undefined) {
                    this.theme.font_scale = clamp(toNumber(patch.font_scale, 1), 0.8, 1.4);
                }
                if (patch.idle_lock_enabled !== undefined) {
                    this.theme.idle_lock_enabled = !!patch.idle_lock_enabled;
                }
                if (patch.sound_on_scan !== undefined) {
                    this.theme.sound_on_scan = !!patch.sound_on_scan;
                }

                this.applyTheme();
            },

            plainTheme: function () {
                return clone(this.theme);
            },

            resetTheme: function () {
                this.theme = clone(THEME_DEFAULTS);
                this.applyTheme();
            },

            applyTheme: function () {
                var root = document.documentElement;
                var theme = this.theme;

                root.setAttribute('data-theme', theme.mode);
                root.setAttribute('data-density', theme.density);
                root.style.setProperty('--pos-accent', theme.accent);
                root.style.setProperty('--pos-font-scale', String(theme.font_scale));
                root.style.setProperty('--pos-btn-scale', String(theme.btn_scale));

                this._syncThemeToPos();
            },

            saveSettings: function () {
                var self = this;

                this.saving = true;
                this.persist({ theme: this.plainTheme() })
                    .then(function () {
                        self.notify(t('settings_saved', 'Settings saved.'), 'success');
                        self.closeSettings();
                    })
                    .catch(function (error) {
                        self.notify((error && error.message) || t('save_failed', 'Could not save.'), 'error');
                    })
                    .finally(function () {
                        self.saving = false;
                    });
            },

            idleLockMinutes: function () {
                var pos = posStore();

                if (pos && pos.config && pos.config.idle_lock_minutes !== undefined) {
                    return parseInt(pos.config.idle_lock_minutes, 10) || 0;
                }

                var boot = readBootConfig();

                if (boot.idle_lock_minutes !== undefined) {
                    return parseInt(boot.idle_lock_minutes, 10) || 0;
                }
                if (boot.config && boot.config.idle_lock_minutes !== undefined) {
                    return parseInt(boot.config.idle_lock_minutes, 10) || 0;
                }

                return 5;
            },

            /* ---------------- persistence ---------------- */

            _schedulePersist: function () {
                var self = this;

                if (this._persistTimer) {
                    clearTimeout(this._persistTimer);
                }
                this._persistTimer = setTimeout(function () {
                    self._persistTimer = null;
                    self.persist({ layout: self.plainPanels(), theme: self.plainTheme() })
                        .catch(function () {
                            // best-effort auto-save only
                        });
                }, 800);
            },

            persist: function (payload) {
                if (!window.PosApi) {
                    return Promise.reject({ message: 'PosApi unavailable' });
                }
                this._ensureApiConfigured();

                return window.PosApi.post('preference/save', payload);
            },

            load: function () {
                var self = this;

                if (!window.PosApi) {
                    return Promise.resolve();
                }
                this._ensureApiConfigured();

                return window.PosApi.get('preference/load')
                    .then(function (data) {
                        data = data || {};
                        if (data.layout) {
                            var stale = layoutVersion(data.layout) < LAYOUT_VERSION;
                            var migrated = migrateLayout(data.layout);

                            // Cold-boot guard (contract §13/§2): a floating
                            // panel (holds/session) whose open state was
                            // persisted must NOT auto-restore open on a fresh
                            // login - it would raise the dimmed
                            // .pos-floating-overlay over the workspace and
                            // intercept every pointer event before the cashier
                            // can act. The cashier re-opens floats deliberately
                            // via the header toggle; static blocks rehydrate
                            // normally. Only force this on the initial load so a
                            // float opened during the session and re-loaded
                            // (e.g. manual refresh after auth) isn't fought.
                            if (!self.loaded) {
                                FLOAT_KEYS.forEach(function (key) {
                                    if (migrated[key]) {
                                        migrated[key].visible = false;
                                    }
                                });
                            }
                            self.setPanels(migrated);

                            // One-time upgrade of a demoted pre-§27 save:
                            // re-persist the pinned defaults WITH the `_v`
                            // stamp so future loads honor saves again. Only
                            // when this cashier may save layouts - the
                            // server gates layout writes on can_edit_layout,
                            // so without it the (idempotent) demotion simply
                            // re-runs client-side on every load instead.
                            if (stale && self.canEditLayout()) {
                                self._schedulePersist();
                            }
                        }
                        if (data.theme) {
                            self.setTheme(data.theme);
                        }
                        self.loaded = true;
                        self._syncFloatStack();
                        self.applyPanels();
                        self._syncVisibilityToPos();
                    })
                    .catch(function () {
                        // not logged in yet / offline - defaults stay active
                    });
            },

            _ensureApiConfigured: function () {
                if (window.PosApi.baseUrl) {
                    return;
                }

                var boot = readBootConfig();
                var baseUrl = boot.api_base_url || boot.apiBaseUrl || boot.base_url || boot.baseUrl;
                var formKey = boot.form_key || boot.formKey;

                if (baseUrl || formKey) {
                    window.PosApi.configure({ baseUrl: baseUrl, formKey: formKey });
                }
            },

            /* ---------------- sounds ---------------- */

            beep: function () {
                if (!this.theme.sound_on_scan) {
                    return;
                }
                try {
                    var Ctx = window.AudioContext || window.webkitAudioContext;

                    if (!Ctx) {
                        return;
                    }
                    if (!this._audioCtx) {
                        this._audioCtx = new Ctx();
                    }

                    var ctx = this._audioCtx;
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();

                    osc.type = 'square';
                    osc.frequency.value = 1175;
                    gain.gain.setValueAtTime(0.06, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.1);
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.start();
                    osc.stop(ctx.currentTime + 0.11);
                } catch (e) {
                    // audio is best-effort only
                }
            },

            isIdleLockEnabled: function () {
                return !!this.theme.idle_lock_enabled;
            },

            isSoundOnScan: function () {
                return !!this.theme.sound_on_scan;
            },

            /* ---------------- $store.pos bridge ---------------- */

            _bridgePosStore: function () {
                var self = this;
                var pos = posStore();

                if (this._bridged || !pos || !pos.panels || typeof pos.panels !== 'object') {
                    return;
                }
                this._bridged = true;

                var allObjects = PANEL_KEYS.every(function (key) {
                    return pos.panels[key] && typeof pos.panels[key] === 'object';
                });

                if (allObjects && PANEL_KEYS.every(function (key) { return 'w' in pos.panels[key]; })) {
                    PANEL_KEYS.forEach(function (key) {
                        pos.panels[key] = Object.assign({}, self.panels[key], pos.panels[key]);
                    });
                    this.panels = pos.panels;
                } else {
                    // boolean visibility map (header togglePanel) -> mirror it.
                    // Only the keys app.js actually tracks (the toggleable
                    // float panels) appear as booleans; other blocks stay
                    // owned by this store.
                    this._boolBridge = true;
                    PANEL_KEYS.forEach(function (key) {
                        if (typeof pos.panels[key] === 'boolean') {
                            self.panels[key].visible = pos.panels[key];
                        }
                    });
                    this._syncFloatStack();
                    if (window.Alpine && typeof window.Alpine.effect === 'function') {
                        window.Alpine.effect(function () {
                            var changed = false;

                            PANEL_KEYS.forEach(function (key) {
                                var flag = pos.panels[key];

                                if (typeof flag === 'boolean' && self.panels[key].visible !== flag) {
                                    self.panels[key].visible = flag;
                                    self._trackFloat(key, flag);
                                    changed = true;
                                }
                            });
                            if (changed) {
                                self.applyPanels();
                                if (!self.editMode) {
                                    self._schedulePersist();
                                }
                            }
                        });
                    }
                }
            },

            _syncVisibilityToPos: function () {
                if (!this._boolBridge) {
                    return;
                }

                var pos = posStore();
                var self = this;

                if (!pos || !pos.panels) {
                    return;
                }
                PANEL_KEYS.forEach(function (key) {
                    if (typeof pos.panels[key] === 'boolean') {
                        pos.panels[key] = !!self.panels[key].visible;
                    }
                });
            },

            _syncThemeToPos: function () {
                var pos = posStore();

                if (pos) {
                    pos.theme = Object.assign({}, pos.theme || {}, this.plainTheme());
                }
            }
        };
    }

    /* ------------------------------------------------------------
     * Pointer-event drag + resize engine (contract §24)
     *
     * Identical for every movable block. During the gesture the block
     * follows the pointer 1:1 in PX (transform while moving, live px
     * width/height while resizing) rAF-throttled; the store's rect is
     * only committed ON DROP, snapped to the nearest cell using
     * gap/padding-aware grid metrics (no grab-offset jump, no spring,
     * no off-by-one from the grid origin). setPointerCapture keeps the
     * gesture alive outside the element; touch + mouse both work
     * (pointer events; touch-action:none on handles via pos.css +
     * inline on the injected resize handle). Drag starts ONLY from the
     * panel header / drag handle - never from buttons or inputs, so
     * controls inside blocks stay clickable in edit mode.
     *
     * QUICK MOVE (contract §43) + QUICK RESIZE (contract §44): the ⠿
     * drag handle / header drag zone also starts a MOVE outside edit
     * mode, and the SE corner handle (.pos-panel__resize - injected by
     * applyPanels on every block, pos.css reveals it on hover with
     * cursor:nwse-resize) starts a RESIZE outside edit mode - same
     * engine for both. §51 guarantees the handle WORKS on every block,
     * not just the catalog: (a) applyPanels injects the handle on every
     * .pos-panel at runtime and re-pins touch-action:none on each pass,
     * (b) pos.css reveals it via DESCENDANT selectors so the cart
     * sub-blocks under panel-cart.phtml's display:contents host (which
     * the old `.pos-grid > .pos-panel` child combinator skipped - the
     * whole right column was unresizable) match too, and (c) the
     * handle's z-index sits above pinned footers / the CHARGE slab /
     * bottom button rows, so the SE corner is always hit-testable while
     * CHARGE keeps every click outside that small corner zone. During a
     * resize the panel carries .is-resizing so the handle stays painted
     * for the whole gesture. The gesture only engages after a small
     * movement threshold so a plain tap/click never repositions or
     * resizes a panel (touch-safe). While quick-dragging the snap-grid
     * hint shows (.is-quick-dragging on .pos-grid) and the drop commits
     * + persists immediately via preference/save; per-block MIN_SIZES
     * and the no-inversion clamps apply exactly as in edit mode. A
     * quick RESIZE additionally resolves collisions on drop - neighbors
     * it grew into are shrunk clear toward their far edge (never below
     * their own MIN_SIZES, never off-grid; see
     * resolveQuickResizeOverlaps) so the overlap that used to bury the
     * SE handle under a higher-z neighbor is never committed/persisted
     * (§44 round 2); the is-colliding highlight also tracks live during
     * an engaged quick gesture. Quick MOVE stays free-form (§43). Both
     * quick gestures share the same exemptions: floating overlays
     * (holds/session), the <=900px stacked fallback, and cashiers
     * without can_edit_layout (mirrored onto <body> as
     * .pos-body--can-edit-layout so pos.css only reveals the hover
     * handles for cashiers who may actually use them). Visibility
     * toggles, presets and Reset stay in edit mode / the Layout modal.
     * ---------------------------------------------------------- */
    var pointerAttached = false;
    var drag = null;

    /* px of pointer travel before a quick (out-of-edit) move/resize
     * engages - a tap/click on the header or the SE handle must never
     * reposition or resize (contract §43/§44) */
    var QUICK_DRAG_THRESHOLD_PX = 5;

    var INTERACTIVE_SELECTOR =
        'button, a[href], input, select, textarea, label, [contenteditable="true"], [role="button"]';

    function layoutStore() {
        try {
            return window.Alpine ? window.Alpine.store('posLayout') : null;
        } catch (e) {
            return null;
        }
    }

    /* ------------------------------------------------------------
     * Layout-editor toolbar eclipse fix (contract §24 round 2).
     *
     * The fixed bottom-center .pos-layout-toolbar (modal-layout.phtml,
     * z-index 220) paints OVER the workspace grid, and at the pinned
     * default layout it covered the customer block's header and SE
     * resize handle on both target viewports (1280×800 / 1366×768) -
     * every pointerdown landed on the toolbar div, so drag/resize of
     * that block silently did nothing.
     *
     * Two-part fix, inline styles only (no pos.css change needed):
     *  1. PASSTHROUGH while editing: the toolbar CONTAINER gets
     *     pointer-events:none and each interactive control inside it
     *     gets pointer-events:auto back. Presets / checkboxes / Reset /
     *     Cancel / Save keep working exactly as before, but the
     *     container's padding, hint row, labels, dividers and flex gaps
     *     stop eating pointer events - hit-testing (and therefore
     *     onPointerDown) falls through to the panel header / resize
     *     handle underneath, which is most of the toolbar's area.
     *  2. AUTO-DODGE during a gesture: the moment a drag/resize starts
     *     the whole toolbar fades out and becomes fully click-through
     *     (controls included) so it can't obstruct the live follow or
     *     the drop either; it restores the instant the gesture ends
     *     (finishDrag -> applyPanels re-syncs). aria-hidden mirrors the
     *     dodge so AT focus can't land on invisible controls.
     *
     * Idempotent + self-healing: applyPanels() re-syncs the state on
     * every geometry pass, so edit-mode enter/exit, Alpine re-renders
     * and aborted gestures all settle correctly.
     * ---------------------------------------------------------- */
    var EDITOR_TOOLBAR_SELECTOR = '.pos-layout-toolbar';

    function syncEditorToolbar(editMode, dragging) {
        var el = document.querySelector(EDITOR_TOOLBAR_SELECTOR);

        if (!el) {
            return;
        }

        var controls = el.querySelectorAll(INTERACTIVE_SELECTOR);
        var i;

        if (!editMode) {
            // outside edit mode the toolbar is hidden (x-show) - drop every
            // inline override so the stylesheet is the single source again
            el.style.pointerEvents = '';
            el.style.opacity = '';
            el.style.transition = '';
            el.removeAttribute('aria-hidden');
            for (i = 0; i < controls.length; i += 1) {
                controls[i].style.pointerEvents = '';
            }
            return;
        }

        el.style.transition = 'opacity 150ms ease';
        el.style.pointerEvents = 'none';
        el.style.opacity = dragging ? '0' : '';
        if (dragging) {
            el.setAttribute('aria-hidden', 'true');
        } else {
            el.removeAttribute('aria-hidden');
        }
        for (i = 0; i < controls.length; i += 1) {
            controls[i].style.pointerEvents = dragging ? 'none' : 'auto';
        }
    }

    function attachPointerHandling() {
        if (pointerAttached) {
            return;
        }
        pointerAttached = true;
        document.addEventListener('pointerdown', onPointerDown, true);
        // pointer capture retargets move/up to the captured panel; they
        // still bubble to window, so one set of listeners serves every
        // gesture (and doubles as the no-capture fallback).
        window.addEventListener('pointermove', onPointerMove, { passive: false });
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerCancel);
    }

    /** Gap/padding-aware grid metrics. A column i spans
     *  [originX + i·pitchX, ... + unit] where pitch = unit + gap - using the
     *  real computed padding/gap kills the off-by-one drop errors the old
     *  rect.width / GRID_COLS math produced. */
    function gridMetrics(grid) {
        var rect = grid.getBoundingClientRect();
        var cs = window.getComputedStyle(grid);
        var padL = parseFloat(cs.paddingLeft) || 0;
        var padT = parseFloat(cs.paddingTop) || 0;
        var innerW = rect.width - padL - (parseFloat(cs.paddingRight) || 0);
        var innerH = rect.height - padT - (parseFloat(cs.paddingBottom) || 0);
        var gapX = parseFloat(cs.columnGap) || 0;
        var gapY = parseFloat(cs.rowGap) || 0;

        return {
            originX: rect.left + padL,
            originY: rect.top + padT,
            innerW: innerW,
            innerH: innerH,
            gapX: gapX,
            gapY: gapY,
            pitchX: (innerW + gapX) / GRID_COLS,
            pitchY: (innerH + gapY) / GRID_ROWS
        };
    }

    function onPointerDown(event) {
        var store = layoutStore();

        if (!store || drag) {
            return;
        }
        // primary button only (touch/pen report 0)
        if (event.button !== undefined && event.button !== 0) {
            return;
        }
        if (!event.target || typeof event.target.closest !== 'function') {
            return;
        }

        var panel = event.target.closest('.pos-grid [data-panel]');

        if (!panel) {
            return;
        }

        var key = panel.getAttribute('data-panel');

        if (PANEL_KEYS.indexOf(key) === -1 || !store.panels[key]) {
            return;
        }

        /* QUICK MOVE (contract §43) + QUICK RESIZE (contract §44):
         * outside edit mode the drag handle / header drag zone starts a
         * move directly, and the SE corner handle starts a resize
         * directly. Edit mode keeps its exact §24 behaviour (immediate
         * drag/resize, tap-to-front). */
        var quick = !store.editMode;

        if (!quick) {
            store.bringToFront(key);
        }

        var mode = null;

        if (event.target.closest('.pos-panel__resize')) {
            // the injected SE handle resizes in BOTH modes (§24 in edit
            // mode, §44 quick resize in normal mode - pos.css reveals it
            // on hover outside edit mode for permitted cashiers)
            mode = 'resize';
        } else if (event.target.closest(INTERACTIVE_SELECTOR)) {
            // a real control (panel-header buttons, body controls): never
            // hijack it into a drag - clicks keep working in edit mode
            return;
        } else if (event.target.closest('.pos-panel__header, .pos-panel__drag, [data-drag-handle]')) {
            mode = 'move';
        }
        if (!mode) {
            return;
        }
        if (quick) {
            // Quick move/resize never applies to: floating overlays
            // (holds / session are fixed-positioned by css-system outside
            // edit mode - grid coords don't apply; they are placed via
            // edit mode), the stacked single-column phone fallback (the
            // grid template is inert there), or cashiers without the
            // layout permission (the gesture persists via
            // preference/save, so it honors the same can_edit_layout
            // gate as the Layout modal - §43/§44).
            if (panel.classList.contains('is-floating')
                || !store.canEditLayout()
                || (window.matchMedia && window.matchMedia('(max-width: 900px)').matches)) {
                return;
            }
        }

        var grid = panel.closest('.pos-grid');

        if (!grid) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        var elRect = panel.getBoundingClientRect();

        drag = {
            key: key,
            mode: mode,
            el: panel,
            grid: grid,
            quick: quick,
            // edit-mode gestures engage immediately (§24); quick moves
            // wait for the movement threshold so a tap never moves (§43)
            started: !quick,
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            lastX: event.clientX,
            lastY: event.clientY,
            raf: 0,
            metrics: gridMetrics(grid),
            elLeft: elRect.left,
            elTop: elRect.top,
            elW: elRect.width,
            elH: elRect.height,
            orig: Object.assign({}, store.panels[key]),
            cand: Object.assign({}, store.panels[key])
        };

        try {
            panel.setPointerCapture(event.pointerId);
        } catch (e) {
            // capture is an enhancement; window listeners still track
        }
        if (drag.started) {
            beginDragVisuals();
        }
    }

    /**
     * Visual start of a gesture - runs at pointerdown in edit mode, or
     * the moment a quick move/resize passes its threshold (contract
     * §43/§44), so a plain tap on a header or the SE handle changes
     * nothing at all.
     */
    function beginDragVisuals() {
        var store = layoutStore();

        if (drag.quick && store) {
            // the moved/resized block should track + land above any
            // overlap; edit mode already did this at pointerdown
            store.bringToFront(drag.key);
        }
        drag.el.classList.add('is-dragging');
        if (drag.mode === 'resize') {
            // §44/§51: pos.css keeps the SE handle painted while
            // .is-resizing is on the panel, so the handle never blinks
            // out mid-gesture when the pointer outruns the corner /
            // leaves the panel's hover area (capture keeps the gesture
            // alive; this keeps the affordance visible).
            drag.el.classList.add('is-resizing');
        }
        // lift the gesture above BOTH z-bands (ghosts 1..n, visible 21..n)
        // but below the floating backdrop (90) / modals (200) so even a
        // ghost being placed tracks visibly on top; finishDrag/abortDrag
        // re-stamp the banded value via applyPanels
        drag.el.style.zIndex = '60';
        if (drag.quick) {
            // snap-grid hint while quick-dragging/resizing (contract
            // §43/§44) - same dotted overlay pos.css draws in edit mode
            drag.grid.classList.add('is-quick-dragging');
        } else {
            // auto-dodge the fixed editor toolbar for the whole gesture so
            // it can never obstruct the live follow or the drop (contract
            // §24 round 2); finishDrag restores it via applyPanels
            syncEditorToolbar(true, true);
        }
    }

    function onPointerMove(event) {
        if (!drag || event.pointerId !== drag.pointerId) {
            return;
        }
        drag.lastX = event.clientX;
        drag.lastY = event.clientY;
        if (event.cancelable) {
            event.preventDefault(); // no scroll/selection mid-gesture
        }
        if (!drag.raf) {
            drag.raf = window.requestAnimationFrame(applyDragFrame);
        }
    }

    /** rAF-throttled 1:1 px follow + live snapped-candidate collisions. */
    function applyDragFrame() {
        if (!drag) {
            return;
        }
        drag.raf = 0;

        var store = layoutStore();

        if (!store) {
            return;
        }
        if (!store.editMode && !drag.quick) {
            // edit mode ended mid-gesture (modal save/cancel) - revert
            abortDrag();
            return;
        }

        var m = drag.metrics;
        var dx = drag.lastX - drag.startX;
        var dy = drag.lastY - drag.startY;

        // quick moves/resizes only engage past a small travel threshold
        // so a plain tap/click on the header or the SE handle never
        // repositions or resizes (contract §43/§44)
        if (!drag.started) {
            if (Math.sqrt(dx * dx + dy * dy) < QUICK_DRAG_THRESHOLD_PX) {
                return;
            }
            drag.started = true;
            beginDragVisuals();
        }

        if (drag.mode === 'move') {
            // clamp so the block's box stays inside the grid content box
            // (it can never be pushed under the header or off the grid)
            dx = clamp(dx, m.originX - drag.elLeft, m.originX + m.innerW - drag.elW - drag.elLeft);
            dy = clamp(dy, m.originY - drag.elTop, m.originY + m.innerH - drag.elH - drag.elTop);
            drag.el.style.transform = 'translate3d(' + dx + 'px, ' + dy + 'px, 0)';
            drag.cand = snapMove(dx, dy);
        } else {
            var min = minSize(drag.key);
            // px floor mirrors the cell-unit minimum so the live preview
            // can't undershoot what the snap would allow (no inversion -
            // width/height never go negative or below min)
            var minWpx = min.w * m.pitchX - m.gapX;
            var minHpx = min.h * m.pitchY - m.gapY;
            var w = clamp(drag.elW + dx, minWpx, m.originX + m.innerW - drag.elLeft);
            var h = clamp(drag.elH + dy, minHpx, m.originY + m.innerH - drag.elTop);

            drag.el.style.width = w + 'px';
            drag.el.style.height = h + 'px';
            drag.cand = snapResize(w, h);
        }

        store.updateCollisions(drag.key, drag.cand);
    }

    /** Snap the block's live px position to the nearest grid cell. */
    function snapMove(dx, dy) {
        var m = drag.metrics;
        var p = drag.orig;
        var x = Math.round((drag.elLeft + dx - m.originX) / m.pitchX);
        var y = Math.round((drag.elTop + dy - m.originY) / m.pitchY);

        return {
            x: clamp(x, 0, GRID_COLS - p.w),
            y: clamp(y, 0, GRID_ROWS - p.h),
            w: p.w,
            h: p.h,
            visible: p.visible
        };
    }

    /** Snap the block's live px size to the nearest cell span.
     *  A span of n cells renders n·pitch − gap px, hence the +gap. */
    function snapResize(wPx, hPx) {
        var m = drag.metrics;
        var p = drag.orig;
        var min = minSize(drag.key);

        return {
            x: p.x,
            y: p.y,
            w: clamp(Math.round((wPx + m.gapX) / m.pitchX), min.w, GRID_COLS - p.x),
            h: clamp(Math.round((hPx + m.gapY) / m.pitchY), min.h, GRID_ROWS - p.y),
            visible: p.visible
        };
    }

    /* ------------------------------------------------------------
     * Quick-resize collision resolution (contract §44 round 2).
     *
     * A quick resize used to COMMIT a silent overlap: growing Quick Keys
     * (16->22 cols) into the adjacent Customer panel left Customer - a
     * later PANEL_KEYS entry, so higher in the persistent paint band
     * (zRank falls back to registry order once _zMap is gone, e.g. after
     * a reload) - painted OVER the resized panel's right edge AND its SE
     * resize handle. elementsFromPoint at the handle center resolved to
     * the neighbor's body, so every shrink-back pointerdown hit the
     * neighbor instead of the handle: the panel could not be shrunk back
     * in normal mode (one-way gesture). bringToFront during the gesture
     * doesn't help across reloads because _zMap is never persisted.
     *
     * Fix: ON DROP of a quick resize, RESOLVE the overlap instead of
     * committing it - each overlapped grid neighbor is shrunk toward its
     * own far edge (the near edge is pulled flush to the resized panel's
     * boundary; x/y/w/h stay inside the neighbor's ORIGINAL rect, so a
     * resolution can never create a NEW collision or push anything
     * off-grid), respecting that neighbor's MIN_SIZES. The repro above
     * resolves to Customer x:22 w:10 (its exact §25 minimum). When a
     * neighbor cannot shrink clear (the resized rect swallows it past
     * its minimum on every side) its rect is left untouched - the
     * gestured panel was brought to front when the gesture engaged
     * (beginDragVisuals), so its handle stays reachable to undo, and
     * edit mode / Reset remain the escape hatch as before.
     *
     * Scope: quick (out-of-edit) RESIZE drops only. Quick MOVE stays
     * free-form per contract §43 (overlaps allowed, design unchanged),
     * and edit mode keeps its §24 "collisions allowed but highlighted"
     * semantics - Save/Cancel decides there.
     * ---------------------------------------------------------- */

    /** Smallest-loss pure-shrink of rect b clear of rect a (grid units).
     *  Returns a replacement rect for b, or null when no side can give
     *  enough room without violating b's per-block minimum. */
    function shrinkClearOf(a, b, min) {
        var cands = [];
        var w = (b.x + b.w) - (a.x + a.w); // pull b's LEFT edge right
        var w2 = a.x - b.x;                // pull b's RIGHT edge left
        var h = (b.y + b.h) - (a.y + a.h); // pull b's TOP edge down
        var h2 = a.y - b.y;                // pull b's BOTTOM edge up

        if (w >= min.w) {
            cands.push({ x: a.x + a.w, y: b.y, w: w, h: b.h, visible: b.visible, cost: b.w - w });
        }
        if (w2 >= min.w) {
            cands.push({ x: b.x, y: b.y, w: w2, h: b.h, visible: b.visible, cost: b.w - w2 });
        }
        if (h >= min.h) {
            cands.push({ x: b.x, y: a.y + a.h, w: b.w, h: h, visible: b.visible, cost: b.h - h });
        }
        if (h2 >= min.h) {
            cands.push({ x: b.x, y: b.y, w: b.w, h: h2, visible: b.visible, cost: b.h - h2 });
        }
        if (!cands.length) {
            return null;
        }
        cands.sort(function (p, q) {
            return p.cost - q.cost;
        });

        return { x: cands[0].x, y: cands[0].y, w: cands[0].w, h: cands[0].h, visible: cands[0].visible };
    }

    /** Shrink every visible grid neighbor clear of the just-resized
     *  block `key`. Floating overlays are skipped (outside edit mode
     *  they render fixed-positioned off the grid - their saved rects
     *  must not be disturbed by a grid-space resolution). Returns true
     *  when any neighbor's rect changed (caller persists). */
    function resolveQuickResizeOverlaps(store, key) {
        var a = store.panels[key];
        var floating = store._floatingKeys();
        var changed = false;

        PANEL_KEYS.forEach(function (other) {
            if (other === key) {
                return;
            }

            var b = store.panels[other];

            if (!b || !b.visible || floating.indexOf(other) !== -1 || !overlaps(a, b)) {
                return;
            }

            var fitted = shrinkClearOf(a, b, minSize(other));

            if (fitted) {
                Object.assign(b, fitted);
                changed = true;
            }
        });

        return changed;
    }

    function onPointerUp(event) {
        if (!drag || event.pointerId !== drag.pointerId) {
            return;
        }
        finishDrag(true);
    }

    function onPointerCancel(event) {
        if (!drag || event.pointerId !== drag.pointerId) {
            return;
        }
        // OS/browser stole the gesture - put the block back where it was
        abortDrag();
    }

    /** Revert without committing (pointercancel / viewport resize /
     *  edit mode ended mid-gesture). Safe to call when idle. */
    function abortDrag() {
        if (drag) {
            finishDrag(false);
        }
    }

    function finishDrag(commit) {
        var d = drag;

        drag = null; // re-entrancy guard (lostpointercapture etc.)

        if (!d) {
            return;
        }
        if (d.raf) {
            window.cancelAnimationFrame(d.raf);
        }
        try {
            d.el.releasePointerCapture(d.pointerId);
        } catch (e) {
            // already released / never captured
        }

        // clear the live-follow inline styles; the committed grid rect
        // takes over (snap happens HERE, exactly once, on drop)
        d.el.style.transform = '';
        d.el.style.width = '';
        d.el.style.height = '';
        d.el.style.zIndex = '';
        d.el.classList.remove('is-dragging');
        d.el.classList.remove('is-resizing');
        if (d.grid) {
            d.grid.classList.remove('is-quick-dragging');
        }

        var store = layoutStore();

        if (store && store.panels[d.key]) {
            var moved = false;

            if (commit && d.started && d.cand) {
                // store.panels was never mutated during the gesture, so a
                // revert is just "don't commit" - no springing possible
                var p = store.panels[d.key];

                moved = p.x !== d.cand.x || p.y !== d.cand.y
                    || p.w !== d.cand.w || p.h !== d.cand.h;
                Object.assign(p, d.cand);

                // §44 round 2: a quick RESIZE never commits a silent
                // overlap - overlapped neighbors shrink clear on drop
                // (see resolveQuickResizeOverlaps), so the resized
                // panel's SE handle stays reachable to shrink back even
                // after a reload resets the z-order. Quick MOVE stays
                // free-form (§43) and edit mode keeps §24 semantics.
                if (moved && d.quick && d.mode === 'resize') {
                    resolveQuickResizeOverlaps(store, d.key);
                }
            }
            // applyPanels also re-syncs the editor toolbar out of its
            // dodged state (drag is already null here)
            store.applyPanels();
            // a quick move/resize (outside edit mode) commits + persists
            // on drop via preference/save (contract §43/§44); edit-mode
            // gestures keep committing via Save/Cancel in the Layout modal
            if (d.quick && moved) {
                store._schedulePersist();
            }
        } else {
            // store unavailable mid-gesture - still un-dodge the toolbar
            syncEditorToolbar(!!(store && store.editMode), false);
        }
    }

    /* ------------------------------------------------------------
     * Registration
     * ---------------------------------------------------------- */
    document.addEventListener('alpine:init', function () {
        window.Alpine.store('posLayout', createLayoutStore());
    });

    // Public facade for non-Alpine callers (api/app/catalog scripts)
    window.PosLayout = {
        store: layoutStore,
        openSettings: function () {
            var s = layoutStore();
            if (s) {
                s.openSettings();
            }
        },
        openLayout: function () {
            var s = layoutStore();
            if (s) {
                s.openLayout();
            }
        },
        closePanel: function (key) {
            var s = layoutStore();
            if (s) {
                s.closePanel(key);
            }
        },
        closeTopFloat: function () {
            var s = layoutStore();
            return s ? s.closeTopFloat() : false;
        },
        isModalOpen: function (name) {
            var s = layoutStore();
            return s ? s.isModalOpen(name) : false;
        },
        isIdleLockEnabled: function () {
            var s = layoutStore();
            return s ? s.isIdleLockEnabled() : true;
        },
        isSoundOnScan: function () {
            var s = layoutStore();
            return s ? s.isSoundOnScan() : true;
        },
        beep: function () {
            var s = layoutStore();
            if (s) {
                s.beep();
            }
        },
        presets: Object.keys(PRESETS),
        panelKeys: PANEL_KEYS.slice()
    };
})();
