/*
 * Web Bluetooth → ESC/POS bridge for thermal receipt printers.
 *
 * Replaces the print-dialog round-trip with a direct write to a paired
 * Bluetooth thermal printer. The OS print spooler often forces letter or
 * A4 paper geometry which mangles 58/80mm receipts; sending raw ESC/POS
 * bytes lets the printer do what it's designed for and gives us
 * sub-second print times instead of "open dialog → pick printer →
 * adjust page setup → print."
 *
 * Pairing model:
 *   - Web Bluetooth requires HTTPS and a user gesture for the initial
 *     pairing dialog. The "🔗 Pair printer" button on the receipt modal
 *     satisfies that.
 *   - Once paired, Chrome 92+ remembers the device for the origin so
 *     a same-day reload re-uses the connection without another prompt
 *     (we call navigator.bluetooth.getDevices() on init).
 *   - Older Chromes / some Android builds: the user re-pairs each
 *     session. Annoying but not broken.
 *
 * Browser support:
 *   ✓ Chrome desktop, Chrome Android, Edge desktop
 *   ✗ Safari (any platform), Firefox (any platform)
 *   On unsupported browsers BSESCPOS.isSupported() returns false and the
 *   POS code falls back to the existing window.print() path silently.
 *
 * Compatibility scope:
 *   Most BLE thermal printers in the Nigerian market follow one of three
 *   GATT-service families. The pair() flow probes them in order and uses
 *   the first that exposes a writable characteristic. If a particular
 *   model isn't covered, the pair-fallback uses acceptAllDevices so the
 *   user can pick anything advertising and we'll attempt the same probe
 *   on its services.
 */
(function (global) {
    'use strict';

    // ── ESC/POS opcodes ────────────────────────────────────────────────────
    var ESC = 0x1B, GS = 0x1D, LF = 0x0A;

    // ── Known printer service / characteristic UUIDs ─────────────────────
    // Order: try the most common (SPP-emulated 18F0) first, then vendor
    // services seen on Goojprt, MTP, Munbyn, Xprinter, Epson (BLE models),
    // and "any device with a writable characteristic" as last resort.
    var KNOWN_SERVICES = [
        // Generic printer service used by most cheap Chinese-made printers
        '000018f0-0000-1000-8000-00805f9b34fb',
        // Microchip Inc — used by some Munbyn / Xprinter
        '49535343-fe7d-4ae5-8fa9-9fafd205e455',
        // MTP / Goojprt / many "M58" portable printers
        'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    ];
    var KNOWN_WRITE_CHARS = [
        '00002af1-0000-1000-8000-00805f9b34fb',
        '49535343-8841-43f4-a8d4-ecbe34729bb3',
        'bef8d6c4-9fbf-4cf8-b8a4-0a1c7adfdfae',
    ];

    // ── State ──────────────────────────────────────────────────────────────
    var device = null;
    var characteristic = null;
    var listeners = []; // 'connect' | 'disconnect'

    function emit(event) {
        listeners.filter(function (l) { return l.event === event; })
                 .forEach(function (l) { try { l.cb(); } catch (e) {} });
    }

    function on(event, cb) { listeners.push({ event: event, cb: cb }); }

    // ── Public: feature detection ──────────────────────────────────────────
    function isSupported() {
        return !!(navigator.bluetooth && navigator.bluetooth.requestDevice);
    }

    function isPaired() {
        return !!(characteristic && device && device.gatt && device.gatt.connected);
    }

    function deviceName() {
        return device && device.name ? device.name : '';
    }

    // ── Pairing ────────────────────────────────────────────────────────────
    /**
     * Request a printer device. Triggers the browser's Bluetooth picker
     * UI; the user picks one of the discovered devices. Probes for a
     * writable characteristic on each known service in turn.
     */
    async function pair() {
        if (!isSupported()) {
            throw new Error('Web Bluetooth is not supported in this browser. Use Chrome or Edge on desktop, or Chrome on Android.');
        }
        var dev;
        try {
            dev = await navigator.bluetooth.requestDevice({
                // filters narrow the picker to advertising devices that
                // expose at least one of the known printer services. For
                // anything else the user can hit Cancel and we'll fall
                // back to acceptAllDevices below.
                filters: KNOWN_SERVICES.map(function (s) { return { services: [s] }; }),
                optionalServices: KNOWN_SERVICES,
            });
        } catch (err) {
            // NotFoundError is what we get when the user clicks Cancel or
            // when nothing in range matches the filters. Retry with
            // acceptAllDevices so users with an unlisted printer can
            // still pair manually.
            if (err && err.name === 'NotFoundError') {
                dev = await navigator.bluetooth.requestDevice({
                    acceptAllDevices: true,
                    optionalServices: KNOWN_SERVICES,
                });
            } else {
                throw err;
            }
        }

        device = dev;
        device.addEventListener('gattserverdisconnected', function () {
            characteristic = null;
            emit('disconnect');
        });
        await connectAndProbe();
        emit('connect');
        return { name: device.name || '(unnamed)' };
    }

    /**
     * Connect to GATT and find a writable characteristic on one of the
     * known services. Sets the module-level `characteristic` on success.
     * Throws if no suitable characteristic is found — at that point the
     * device is paired but we can't talk to it, and the caller should
     * surface "this printer isn't supported."
     */
    async function connectAndProbe() {
        var server = await device.gatt.connect();

        // Try the listed services first — fast path.
        for (var i = 0; i < KNOWN_SERVICES.length; i++) {
            try {
                var svc = await server.getPrimaryService(KNOWN_SERVICES[i]);
                var c = await findWritable(svc);
                if (c) { characteristic = c; return; }
            } catch (e) {
                // service not present on this device, try the next
            }
        }

        // Fall back to walking all primary services. Slow but covers
        // unlisted vendors. getPrimaryServices() requires the service to
        // be in optionalServices when calling requestDevice; we only got
        // KNOWN_SERVICES into optionalServices, so this loop will mostly
        // see those again — but if the device exposed a writable
        // characteristic on one of them under a different shape, we'll
        // find it here.
        var services = await server.getPrimaryServices();
        for (var j = 0; j < services.length; j++) {
            var ch = await findWritable(services[j]);
            if (ch) { characteristic = ch; return; }
        }

        throw new Error('Connected to "' + (device.name || 'device') + '" but no writable characteristic was found. This printer isn\'t supported by the bridge yet — please use the regular Print button.');
    }

    async function findWritable(service) {
        // Prefer the known write-characteristic UUIDs.
        for (var i = 0; i < KNOWN_WRITE_CHARS.length; i++) {
            try {
                var c = await service.getCharacteristic(KNOWN_WRITE_CHARS[i]);
                if (c && (c.properties.writeWithoutResponse || c.properties.write)) return c;
            } catch (e) { /* not present */ }
        }
        // Fall back to any writable characteristic on the service.
        var chars = await service.getCharacteristics();
        for (var j = 0; j < chars.length; j++) {
            if (chars[j].properties.writeWithoutResponse || chars[j].properties.write) {
                return chars[j];
            }
        }
        return null;
    }

    /**
     * Re-attach to a previously-paired device on page load (no UI prompt).
     * Chrome 92+ exposes navigator.bluetooth.getDevices(); older browsers
     * or platforms without it return undefined and we just no-op.
     */
    async function tryAutoReconnect() {
        if (!navigator.bluetooth || !navigator.bluetooth.getDevices) return false;
        try {
            var known = await navigator.bluetooth.getDevices();
            if (!known || !known.length) return false;
            // Use the first known device. Multi-printer setups would need
            // a picker, but the common case is one printer per till.
            device = known[0];
            device.addEventListener('gattserverdisconnected', function () {
                characteristic = null;
                emit('disconnect');
            });
            await connectAndProbe();
            emit('connect');
            return true;
        } catch (e) {
            // getDevices() can throw if permissions are revoked; not fatal.
            device = null;
            characteristic = null;
            return false;
        }
    }

    function disconnect() {
        try { if (device && device.gatt && device.gatt.disconnect) device.gatt.disconnect(); } catch (e) {}
        characteristic = null;
        emit('disconnect');
    }

    // ── Encoding helpers ───────────────────────────────────────────────────
    /**
     * Convert a JS string to a byte array the printer can render. JS uses
     * UTF-16 internally; ESC/POS printers default to a single-byte code
     * page (CP437 / CP850 / CP1252 depending on firmware). We substitute
     * the symbols we know cause trouble (₦ — not in CP437; em dash —
     * arrows — smart quotes) and replace any other non-ASCII with '?'
     * rather than risk garbled output.
     *
     * If a shop ever sells in a script that needs more than ASCII (e.g.
     * Arabic, Tamil) the right fix is to set the printer to CP-UTF8 or
     * use raster-image printing. Out of scope here.
     */
    function asciiBytes(str) {
        if (str == null) return new Uint8Array(0);
        var s = String(str)
            .replace(/₦/g, 'NGN ')
            .replace(/[—–]/g, '-')
            .replace(/[‘’]/g, "'")
            .replace(/[“”]/g, '"')
            .replace(/✓/g, '*')
            .replace(/[^\x20-\x7E\n\r]/g, '?');
        var out = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
        return out;
    }

    function concat() {
        var total = 0;
        for (var i = 0; i < arguments.length; i++) total += arguments[i].length;
        var out = new Uint8Array(total);
        var off = 0;
        for (var j = 0; j < arguments.length; j++) {
            out.set(arguments[j], off);
            off += arguments[j].length;
        }
        return out;
    }

    // Tiny named helpers so encodeReceipt reads top-down without a
    // forest of magic-number arrays.
    var INIT       = new Uint8Array([ESC, 0x40]);              // ESC @
    var ALIGN_L    = new Uint8Array([ESC, 0x61, 0x00]);        // ESC a 0
    var ALIGN_C    = new Uint8Array([ESC, 0x61, 0x01]);        // ESC a 1
    var ALIGN_R    = new Uint8Array([ESC, 0x61, 0x02]);        // ESC a 2
    var BOLD_ON    = new Uint8Array([ESC, 0x45, 0x01]);        // ESC E 1
    var BOLD_OFF   = new Uint8Array([ESC, 0x45, 0x00]);        // ESC E 0
    var SIZE_BIG   = new Uint8Array([ESC, 0x21, 0x10]);        // ESC ! 0x10 = double-height
    var SIZE_NORM  = new Uint8Array([ESC, 0x21, 0x00]);        // ESC ! 0
    var FEED_LINE  = new Uint8Array([LF]);
    // GS V 'B' n  — partial cut + feed n lines. Cheap printers without a
    // cutter just feed extra paper which is also fine.
    var CUT_FEED5  = new Uint8Array([GS, 0x56, 0x42, 0x05]);

    function lineOf(str) {
        return concat(asciiBytes(str), FEED_LINE);
    }

    /**
     * Build a left-and-right column line that fits the given width. Used
     * for "Subtotal ............... NGN 1,000" totals and item amount
     * columns. Truncates the left side if it would push the right amount
     * off the edge — better to lose a few characters of a long title
     * than wrap mid-amount.
     */
    function pad2col(left, right, width) {
        left = String(left == null ? '' : left);
        right = String(right == null ? '' : right);
        var space = width - right.length;
        if (space < 1) {
            // Right-side alone is wider than the strip — render on its
            // own line, no padding to do.
            return right + '\n';
        }
        if (left.length > space - 1) {
            left = left.slice(0, space - 1);
        }
        var pad = ' '.repeat(Math.max(1, space - left.length));
        return left + pad + right + '\n';
    }

    /**
     * Wrap a long string into lines that fit `width` columns. Naive
     * word-boundary-preferred wrap — fine for English book titles,
     * acceptable for everything else.
     */
    function wrap(str, width) {
        str = String(str || '');
        var words = str.split(/\s+/);
        var lines = [];
        var cur = '';
        for (var i = 0; i < words.length; i++) {
            var w = words[i];
            if (!cur.length) {
                cur = w;
            } else if (cur.length + 1 + w.length <= width) {
                cur += ' ' + w;
            } else {
                lines.push(cur);
                cur = w;
            }
            // Long single word: hard-break.
            while (cur.length > width) {
                lines.push(cur.slice(0, width));
                cur = cur.slice(width);
            }
        }
        if (cur.length) lines.push(cur);
        return lines;
    }

    // ── Encoder ────────────────────────────────────────────────────────────
    /**
     * Encode a structured receipt object to ESC/POS bytes.
     *
     *   data = {
     *     paperWidth: 80 | 58,        // mm; default 80
     *     shop, tagline, address, phone,
     *     ref, date, staff, customer, payment,
     *     items: [{title, author?, qty, price, total}],
     *     subtotal, discount, promo, tax, taxLabel, total,
     *     tendered, change,
     *     loyaltyEarned, footer
     *   }
     *
     * Currency is the caller's problem — pass already-formatted strings
     * for amounts (we don't want to redo locale-formatting decisions
     * here). The on-screen receipt's CUR + fmt(...) output is exactly
     * what we want.
     */
    function encodeReceipt(data) {
        var d = data || {};
        var width = d.paperWidth === 58 ? 32 : 48; // chars per line
        var parts = [];

        parts.push(INIT);

        // Header: shop name big + centered
        parts.push(ALIGN_C, SIZE_BIG, BOLD_ON);
        parts.push(lineOf(d.shop || ''));
        parts.push(SIZE_NORM, BOLD_OFF);
        if (d.tagline)  parts.push(lineOf(d.tagline));
        if (d.address)  {
            // Address can have line breaks — split and emit each.
            String(d.address).split(/\r?\n/).forEach(function (l) {
                if (l.trim()) parts.push(lineOf(l.trim()));
            });
        }
        if (d.phone)    parts.push(lineOf('Tel: ' + d.phone));

        parts.push(ALIGN_L);
        parts.push(asciiBytes('-'.repeat(width)), FEED_LINE);

        // Meta block — left-aligned key/value pairs
        if (d.ref)      parts.push(asciiBytes(pad2col('Ref:',     d.ref,     width)));
        if (d.date)     parts.push(asciiBytes(pad2col('Date:',    d.date,    width)));
        if (d.staff)    parts.push(asciiBytes(pad2col('Staff:',   d.staff,   width)));
        if (d.customer) parts.push(asciiBytes(pad2col('Cust:',    d.customer, width)));
        if (d.payment)  parts.push(asciiBytes(pad2col('Pay:',     d.payment, width)));

        parts.push(asciiBytes('-'.repeat(width)), FEED_LINE);

        // Items. We emit two lines per item:
        //   Title, wrapped to width
        //   "  qty @ unit price ............... line total"
        (d.items || []).forEach(function (it) {
            wrap(it.title || '', width).forEach(function (l) {
                parts.push(asciiBytes(l + '\n'));
            });
            var meta = '  ' + (it.qty || 1) + ' @ ' + (it.price || '');
            parts.push(asciiBytes(pad2col(meta, it.total || '', width)));
        });

        parts.push(asciiBytes('-'.repeat(width)), FEED_LINE);

        // Totals block
        if (d.subtotal != null) parts.push(asciiBytes(pad2col('Subtotal',           d.subtotal, width)));
        if (d.discount)         parts.push(asciiBytes(pad2col('Discount',  '-' +    d.discount, width)));
        if (d.promo)            parts.push(asciiBytes(pad2col('Promo',     '-' +    d.promo,    width)));
        if (d.tax)              parts.push(asciiBytes(pad2col(d.taxLabel || 'Tax',  d.tax,      width)));

        parts.push(BOLD_ON, SIZE_BIG);
        parts.push(asciiBytes(pad2col('TOTAL', d.total || '', width / 2)));
        // Half-width because SIZE_BIG doubles the visual character width;
        // pad2col is computing in raw chars and the printer renders each
        // as 2× wide.
        parts.push(SIZE_NORM, BOLD_OFF);

        if (d.tendered) parts.push(asciiBytes(pad2col('Tendered', d.tendered, width)));
        if (d.change)   parts.push(asciiBytes(pad2col('Change',   d.change,   width)));

        if (d.loyaltyEarned) {
            parts.push(asciiBytes('-'.repeat(width)), FEED_LINE);
            parts.push(ALIGN_C);
            parts.push(lineOf('+ ' + d.loyaltyEarned + ' loyalty points earned'));
            parts.push(ALIGN_L);
        }

        // Footer
        parts.push(asciiBytes('-'.repeat(width)), FEED_LINE);
        parts.push(ALIGN_C);
        if (d.footer)   parts.push(lineOf(d.footer));
        parts.push(lineOf('Powered by Bookshop'));

        // Cut + feed enough paper to clear the cutter blade.
        parts.push(CUT_FEED5);

        return concat.apply(null, parts);
    }

    // ── Transmit ───────────────────────────────────────────────────────────
    /**
     * Ship a receipt object to the paired printer. Encodes via
     * encodeReceipt and writes the bytes in MTU-safe chunks.
     *
     * BLE GATT writes have a 20-byte default MTU on most stacks (some
     * negotiate 512 but we can't rely on it). Chunking at 20 means a
     * 1KB receipt makes ~50 writes, taking ~150ms total — comfortably
     * under the print-dialog approach.
     */
    async function print(receiptData) {
        if (!isPaired()) {
            // Defensive: callers should check isPaired() first, but if
            // they didn't, try a one-shot reconnect rather than silently
            // failing. If that still doesn't work, throw — the caller
            // will fall back to the OS print dialog.
            var ok = await tryAutoReconnect();
            if (!ok || !isPaired()) {
                throw new Error('Printer not paired. Click "Pair printer" first.');
            }
        }

        var bytes = encodeReceipt(receiptData);
        var CHUNK = 20;
        var preferWriteWithoutResponse = !!characteristic.properties.writeWithoutResponse;

        for (var i = 0; i < bytes.length; i += CHUNK) {
            var slice = bytes.slice(i, Math.min(i + CHUNK, bytes.length));
            // writeValueWithoutResponse is dramatically faster on Android
            // because each ack saves an RTT (~30ms). Some Chromes don't
            // expose it as a separate method yet; fall back to writeValue.
            if (preferWriteWithoutResponse && characteristic.writeValueWithoutResponse) {
                await characteristic.writeValueWithoutResponse(slice);
            } else {
                await characteristic.writeValue(slice);
            }
        }
    }

    // ── Module export ──────────────────────────────────────────────────────
    global.BSESCPOS = {
        isSupported:        isSupported,
        isPaired:           isPaired,
        deviceName:         deviceName,
        pair:               pair,
        disconnect:         disconnect,
        tryAutoReconnect:   tryAutoReconnect,
        print:              print,
        // Exposed mainly for tests / debugging:
        _encode:            encodeReceipt,
        on:                 on,
    };

    // Best-effort silent reconnect on load. If the user paired earlier
    // today, this restores the connection without a UI prompt.
    if (isSupported()) {
        // Defer so it doesn't compete with the page's initial render.
        setTimeout(function () { tryAutoReconnect(); }, 500);
    }
})(window);
