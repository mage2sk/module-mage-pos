/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Panth_MagePos terminal - offline mode (spec §9).
 *
 * window.PosOffline - IndexedDB `panth_pos` with the pinned object stores
 * `catalog` (snapshot rows from pos/catalog/snapshot, keyPath id),
 * `queue` (orders queued while offline, keyPath client_uuid) and
 * `meta` (key/value bookkeeping, keyPath key).
 *
 * API: cacheCatalog(items), refreshCatalog(), searchLocal(q), findByBarcode(code),
 * queueOrder(order) (client_uuid via crypto.randomUUID()), queuedOrders(),
 * pendingCount(), flushQueue() (pos/sync/push), isOffline(), setOffline(flag).
 *
 * Online/offline `window` events are wired here: going online auto-flushes
 * the queue; the offline banner state is mirrored into Alpine.store('pos')
 * (`offline`, `queuedOrders`) and broadcast as the `pos:offline-changed` /
 * `pos:queue-changed` CustomEvents.
 *
 * Offline banner (design system): a `.pos-offline-banner` warning strip is
 * owned by this file - it reuses an existing `[data-pos-offline-banner]`
 * element when a template ships one, otherwise it is inserted directly
 * below `.pos-header`. State hooks: `hidden`, `data-offline="0|1"` and
 * `data-pending="<n>"`; the visible copy covers offline mode and the
 * queued-sale count.
 */
(function () {
    'use strict';

    var DB_NAME = 'panth_pos';
    var DB_VERSION = 1;
    var STORE_CATALOG = 'catalog';
    var STORE_QUEUE = 'queue';
    var STORE_META = 'meta';
    var SEARCH_LIMIT = 20;

    var dbPromise = null;
    var offlineFlag = typeof navigator !== 'undefined' ? !navigator.onLine : false;
    var flushing = false;
    // In-memory mirror of the catalog store so offline search/barcode lookups
    // don't re-read IndexedDB on every keystroke (contract item 29).
    var catalogMem = null;
    // Reused while a snapshot refresh is already in flight (single fetch).
    var refreshPromise = null;

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

    function dispatch(name, detail) {
        window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
    }

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        // RFC 4122 v4 fallback for non-secure contexts.
        var bytes = new Uint8Array(16);
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < 16; i++) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = '';
        for (var j = 0; j < 16; j++) {
            hex += bytes[j].toString(16).padStart(2, '0');
        }
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16)
            + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
    }

    function openDb() {
        if (dbPromise) {
            return dbPromise;
        }
        dbPromise = new Promise(function (resolve, reject) {
            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB is not available'));
                return;
            }
            var request = window.indexedDB.open(DB_NAME, DB_VERSION);
            request.onupgradeneeded = function (event) {
                var db = event.target.result;
                if (!db.objectStoreNames.contains(STORE_CATALOG)) {
                    db.createObjectStore(STORE_CATALOG, { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains(STORE_QUEUE)) {
                    db.createObjectStore(STORE_QUEUE, { keyPath: 'client_uuid' });
                }
                if (!db.objectStoreNames.contains(STORE_META)) {
                    db.createObjectStore(STORE_META, { keyPath: 'key' });
                }
            };
            request.onsuccess = function () {
                resolve(request.result);
            };
            request.onerror = function () {
                dbPromise = null;
                reject(request.error || new Error('Could not open the panth_pos database'));
            };
        });
        return dbPromise;
    }

    function requestToPromise(request) {
        return new Promise(function (resolve, reject) {
            request.onsuccess = function () {
                resolve(request.result);
            };
            request.onerror = function () {
                reject(request.error);
            };
        });
    }

    function readAll(storeName) {
        return openDb().then(function (db) {
            var store = db.transaction(storeName, 'readonly').objectStore(storeName);
            return requestToPromise(store.getAll());
        });
    }

    function readOne(storeName, key) {
        return openDb().then(function (db) {
            var store = db.transaction(storeName, 'readonly').objectStore(storeName);
            return requestToPromise(store.get(key));
        });
    }

    function writeRows(storeName, rows, clearFirst) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(storeName, 'readwrite');
                var store = tx.objectStore(storeName);
                if (clearFirst) {
                    store.clear();
                }
                rows.forEach(function (row) {
                    store.put(row);
                });
                tx.oncomplete = function () {
                    resolve(rows.length);
                };
                tx.onerror = function () {
                    reject(tx.error);
                };
                tx.onabort = function () {
                    reject(tx.error);
                };
            });
        });
    }

    /**
     * Delete many keys in ONE readwrite transaction (the queue flush removes
     * every synced order at once instead of a transaction per row).
     */
    function deleteRows(storeName, keys) {
        if (!keys || !keys.length) {
            return Promise.resolve(true);
        }
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(storeName, 'readwrite');
                var store = tx.objectStore(storeName);
                keys.forEach(function (key) {
                    store.delete(key);
                });
                tx.oncomplete = function () {
                    resolve(true);
                };
                tx.onerror = function () {
                    reject(tx.error);
                };
                tx.onabort = function () {
                    reject(tx.error);
                };
            });
        });
    }

    function countRows(storeName) {
        return openDb().then(function (db) {
            var store = db.transaction(storeName, 'readonly').objectStore(storeName);
            return requestToPromise(store.count());
        });
    }

    /**
     * Find (or lazily create, right below `.pos-header`) the warning strip.
     * Templates may pre-render their own `[data-pos-offline-banner]` element;
     * it always wins over the generated one.
     * @returns {HTMLElement|null}
     */
    function ensureBanner() {
        var banner = document.querySelector('[data-pos-offline-banner]');
        if (banner) {
            return banner;
        }
        var header = document.querySelector('.pos-header');
        if (!header || !header.parentNode) {
            return null;
        }
        banner = document.createElement('div');
        banner.className = 'pos-offline-banner';
        banner.setAttribute('data-pos-offline-banner', '');
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');
        banner.hidden = true;
        header.parentNode.insertBefore(banner, header.nextSibling);
        return banner;
    }

    function syncBannerState() {
        return pendingCount().catch(function () {
            return 0;
        }).then(function (pending) {
            var store = posStore();
            if (store) {
                store.offline = offlineFlag;
                store.queuedOrders = pending;
            }
            var banner = ensureBanner();
            if (banner) {
                var parts = [];
                if (offlineFlag) {
                    parts.push(t('Offline - sales are saved on this register and sync automatically.'));
                }
                if (pending > 0) {
                    parts.push(t('%1 queued sale(s) waiting to sync.').replace('%1', String(pending)));
                }
                banner.textContent = parts.join(' ');
                banner.hidden = parts.length === 0;
                banner.setAttribute('data-offline', offlineFlag ? '1' : '0');
                banner.setAttribute('data-pending', String(pending));
            }
            return pending;
        });
    }

    function isOffline() {
        return offlineFlag;
    }

    function setOffline(flag) {
        flag = !!flag;
        var changed = offlineFlag !== flag;
        offlineFlag = flag;
        syncBannerState();
        if (changed) {
            dispatch('pos:offline-changed', { offline: offlineFlag });
            if (offlineFlag) {
                notify(t('You are offline. Sales will be queued and synced automatically.'), 'warning');
            } else {
                notify(t('Back online.'), 'success');
            }
        }
        return offlineFlag;
    }

    /**
     * Cached catalog rows, served from the in-memory mirror after the first
     * read (invalidated whenever cacheCatalog rewrites the store).
     */
    function readCatalog() {
        if (catalogMem) {
            return Promise.resolve(catalogMem);
        }
        return readAll(STORE_CATALOG).then(function (rows) {
            catalogMem = rows || [];
            return catalogMem;
        });
    }

    /**
     * Replace the local catalog cache with snapshot rows
     * ({id, sku, name, price, image, type, barcode}) - one batched
     * clear+put transaction.
     */
    function cacheCatalog(items) {
        var rows = Array.isArray(items) ? items.filter(function (item) {
            return item && typeof item === 'object' && item.id !== undefined && item.id !== null;
        }) : [];
        return writeRows(STORE_CATALOG, rows, true).then(function (count) {
            catalogMem = rows;
            return writeRows(STORE_META, [{
                key: 'catalog_synced_at',
                value: new Date().toISOString(),
                count: count
            }], false).then(function () {
                dispatch('pos:catalog-cached', { count: count });
                return count;
            });
        });
    }

    /**
     * Pull a fresh snapshot from pos/catalog/snapshot into the cache.
     * Concurrent callers (auth event + manual refresh) share one request.
     */
    function refreshCatalog() {
        if (offlineFlag || !window.PosApi) {
            return Promise.resolve(0);
        }
        if (refreshPromise) {
            return refreshPromise;
        }
        refreshPromise = window.PosApi.get('catalog/snapshot').then(function (items) {
            return cacheCatalog(Array.isArray(items) ? items : []);
        }).catch(function () {
            // Unauthorized / offline-disabled / network - keep the stale cache.
            return 0;
        }).finally(function () {
            refreshPromise = null;
        });
        return refreshPromise;
    }

    /**
     * Local search fallback over the cached catalog (name / sku / barcode).
     */
    function searchLocal(q, limit) {
        var query = String(q || '').trim().toLowerCase();
        var max = limit || SEARCH_LIMIT;
        return readCatalog().then(function (rows) {
            rows = rows || [];
            if (query === '') {
                return rows.slice(0, max);
            }
            var matches = [];
            for (var i = 0; i < rows.length && matches.length < max; i++) {
                var row = rows[i];
                var name = String(row.name || '').toLowerCase();
                var sku = String(row.sku || '').toLowerCase();
                var barcode = String(row.barcode || '').toLowerCase();
                if (name.indexOf(query) !== -1 || sku.indexOf(query) !== -1 || barcode.indexOf(query) !== -1) {
                    matches.push(row);
                }
            }
            return matches;
        }).catch(function () {
            return [];
        });
    }

    /**
     * Exact barcode lookup against the cached catalog (barcode, then sku).
     */
    function findByBarcode(code) {
        var needle = String(code || '').trim().toLowerCase();
        if (needle === '') {
            return Promise.resolve(null);
        }
        return readCatalog().then(function (rows) {
            rows = rows || [];
            for (var i = 0; i < rows.length; i++) {
                if (String(rows[i].barcode || '').toLowerCase() === needle) {
                    return rows[i];
                }
            }
            for (var j = 0; j < rows.length; j++) {
                if (String(rows[j].sku || '').toLowerCase() === needle) {
                    return rows[j];
                }
            }
            return null;
        }).catch(function () {
            return null;
        });
    }

    /**
     * Queue an order placed while offline. Entry shape mirrors SyncService:
     * {client_uuid, cart: {items, customer_id, discounts, note?}, payments, created_at}.
     * Returns the client_uuid.
     */
    function queueOrder(order) {
        var entry = Object.assign({}, order || {});
        if (!entry.client_uuid) {
            entry.client_uuid = uuid();
        }
        if (!entry.created_at) {
            entry.created_at = new Date().toISOString();
        }
        return writeRows(STORE_QUEUE, [entry], false).then(function () {
            dispatch('pos:queue-changed', { client_uuid: entry.client_uuid });
            syncBannerState();
            return entry.client_uuid;
        });
    }

    function queuedOrders() {
        return readAll(STORE_QUEUE).catch(function () {
            return [];
        });
    }

    function pendingCount() {
        return countRows(STORE_QUEUE).catch(function () {
            return 0;
        });
    }

    /**
     * Push the queue to pos/sync/push. Entries reported as created or
     * duplicate are removed; error entries stay queued for the next flush.
     */
    function flushQueue() {
        if (flushing || offlineFlag || !window.PosApi) {
            return Promise.resolve({ flushed: 0, failed: 0 });
        }
        flushing = true;
        return queuedOrders().then(function (queued) {
            if (!queued.length) {
                return { flushed: 0, failed: 0 };
            }
            return window.PosApi.post('sync/push', { orders: queued }).then(function (data) {
                var results = (data && data.results) || {};
                var flushed = 0;
                var failed = 0;
                var syncedEntries = [];
                queued.forEach(function (entry) {
                    var result = results[entry.client_uuid];
                    if (result && (result.status === 'created' || result.status === 'duplicate')) {
                        flushed++;
                        syncedEntries.push({ client_uuid: entry.client_uuid, result: result });
                    } else {
                        failed++;
                    }
                });
                // One transaction removes every synced entry (item 29), then
                // each sync is announced for receipt/queue listeners.
                return deleteRows(STORE_QUEUE, syncedEntries.map(function (entry) {
                    return entry.client_uuid;
                })).then(function () {
                    syncedEntries.forEach(function (entry) {
                        dispatch('pos:order-synced', {
                            client_uuid: entry.client_uuid,
                            result: entry.result
                        });
                    });
                }).then(function () {
                    if (flushed > 0) {
                        notify(t('Offline orders synced: %1').replace('%1', String(flushed)), 'success');
                    }
                    if (failed > 0) {
                        notify(t('Some offline orders could not be synced: %1').replace('%1', String(failed)), 'error');
                    }
                    dispatch('pos:queue-changed', {});
                    syncBannerState();
                    return { flushed: flushed, failed: failed, results: results };
                });
            });
        }).catch(function (error) {
            // Network/auth failure - everything stays queued.
            return { flushed: 0, failed: 0, error: error && error.message };
        }).finally(function () {
            flushing = false;
        });
    }

    function catalogInfo() {
        return Promise.all([
            readOne(STORE_META, 'catalog_synced_at').catch(function () {
                return null;
            }),
            countRows(STORE_CATALOG).catch(function () {
                return 0;
            })
        ]).then(function (parts) {
            return {
                synced_at: parts[0] ? parts[0].value : null,
                count: parts[1]
            };
        });
    }

    window.PosOffline = {
        isOffline: isOffline,
        setOffline: setOffline,
        cacheCatalog: cacheCatalog,
        refreshCatalog: refreshCatalog,
        searchLocal: searchLocal,
        findByBarcode: findByBarcode,
        queueOrder: queueOrder,
        queuedOrders: queuedOrders,
        pendingCount: pendingCount,
        flushQueue: flushQueue,
        catalogInfo: catalogInfo,
        uuid: uuid
    };

    // Online/offline wiring: going online auto-flushes the queue.
    window.addEventListener('online', function () {
        setOffline(false);
        flushQueue();
    });
    window.addEventListener('offline', function () {
        setOffline(true);
    });

    // After EVERY auth entry path (login / PIN unlock / boot - app.js
    // dispatches 'pos:authenticated' per the fix contract), refresh the
    // catalog snapshot cache and retry any queued orders from a previous
    // offline shift.
    window.addEventListener('pos:authenticated', function () {
        if (!offlineFlag) {
            refreshCatalog();
            flushQueue();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        syncBannerState();
        // Best-effort late flush in case the auth event fired before this
        // script (or is not dispatched at all): unauthorized errors are
        // swallowed and the queue is left intact.
        window.setTimeout(function () {
            if (!offlineFlag) {
                flushQueue();
            }
        }, 4000);
    });
})();
