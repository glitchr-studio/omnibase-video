/*
 * omnibase/video's player: one <media-controller> for the whole visit, kept
 * OUTSIDE #content so a transparent.js navigation never touches it - the
 * film goes on playing while the visitor browses, shrunk in a corner, and
 * takes its place again when they come back to its page.
 *
 *   import 'media-chrome';            // the controls (custom elements)
 *   import 'media-chrome/menu';       // the settings menu (speed, quality)
 *   import 'media-chrome/lang/fr';    // their French words
 *   import 'hls-video-element';       // <hls-video>: hls.js where the browser lacks HLS
 *   import VideoPlayer from '../vendor/omnibase/video/assets/player.js';
 *   VideoPlayer.start();
 *
 * The layout holds the dock (@Video/client/_dock.html.twig, outside #content);
 * a page holds a stage (@Video/client/_player.html.twig): its data-* say what
 * to play. On each page (load, transparent:load) the player looks for a stage:
 *
 *   - the film it already plays: it lays itself over the stage again;
 *   - another film: it loads it there;
 *   - no stage and a film playing: it shrinks into the corner (mini-player);
 *   - no stage and nothing playing: it hides.
 *
 * It sends the beacons the views are counted from (Service\Views): start,
 * progress every ten seconds and on pause, end - the seconds really played,
 * not the seeks. It resumes where the visitor stopped (the server's position
 * for a member, this browser's otherwise; ?t= wins), seeks on the comments'
 * timecodes, shares the link at the current second, and has a cinema mode.
 * A platform's film (an embed) plays in its own iframe in the page, once the
 * visitor accepted the EMBEDS feature of omnibase/consent.
 *
 * ENGINES. The player's own commands (the dock, the beacons, resuming, the
 * timecodes) talk to an engine, never to a <video> directly. The native one
 * ("hls", "mp4": <hls-video> / <video> under media-chrome) is here; a
 * platform's engine (YouTube, Vimeo... - glitchr/omnishow's, later) is
 * registered from outside and gets the stages whose data-type is its name:
 *
 *   VideoPlayer.registerEngine('youtube', function (host, data) { return {
 *       load(data)        // prepare what data says (src, externalId, poster, start)
 *       play()  pause()  seek(seconds)
 *       time()  duration()  paused()  rate()
 *       on(event, fn)     // 'play' | 'pause' | 'time' | 'seeking' | 'end' | 'ready'
 *       destroy()
 *   }; });
 *
 * host is the dock's <media-controller>; data the stage's dataset (id, src,
 * type, platform, externalId, poster, duration...). omnibase/video keeps a
 * source's reference (platform + id), never its logic.
 *
 * ONE THING AT A TIME. When it starts playing, the player dispatches
 * `media:play` on document ({detail: {source: 'video', id, element}}); when
 * anything else on the page does (omnibase/music's bar, another player), it
 * pauses.
 */
var dock, controller, stage, current = null, beacon = null, layoutTimer = null, resizeObserver = null;
var started = false;
var engines = {}, engine = null;

function $(selector, root) { return (root || document).querySelector(selector); }

function token() {
    var bytes = new Uint8Array(12);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
}

function media() { return controller ? controller.querySelector('[slot="media"]') : null; }

// ── Engines ───────────────────────────────────────────────────────────

/** The native engine: <hls-video> (HLS, ours or remote) or <video> (a progressive file), under media-chrome. */
function nativeEngine(host) {
    var el = null, handlers = {};
    function emit(name) { (handlers[name] || []).forEach(function (fn) { fn(); }); }
    return {
        element: function () { return el; },
        load: function (data) {
            el = document.createElement(data.type === 'hls' ? 'hls-video' : 'video');
            el.setAttribute('slot', 'media');
            el.setAttribute('playsinline', '');
            el.setAttribute('crossorigin', '');
            el.setAttribute('preload', 'metadata');
            if (data.poster) el.setAttribute('poster', data.poster);
            if (data.storyboard) {
                var track = document.createElement('track');
                track.kind = 'metadata';
                track.label = 'thumbnails';
                track.default = true;
                track.src = data.storyboard;
                el.appendChild(track);
            }
            el.setAttribute('src', data.src);
            host.prepend(el);
            el.addEventListener('loadedmetadata', function () { emit('ready'); }, { once: true });
            el.addEventListener('playing', function () { emit('play'); });
            el.addEventListener('pause', function () { emit('pause'); });
            el.addEventListener('timeupdate', function () { emit('time'); });
            el.addEventListener('seeking', function () { emit('seeking'); });
            el.addEventListener('ended', function () { emit('end'); });
        },
        play: function () { var p = el && el.play && el.play(); if (p && p.catch) p.catch(function () { /* the browser wants a gesture first */ }); },
        pause: function () { try { if (el) el.pause(); } catch (e) {} },
        seek: function (seconds) { try { if (el) el.currentTime = seconds; } catch (e) {} },
        time: function () { return el ? el.currentTime : 0; },
        duration: function () { return el && isFinite(el.duration) ? el.duration : 0; },
        paused: function () { return !el || el.paused; },
        seeking: function () { return !!(el && el.seeking); },
        rate: function () { return (el && el.playbackRate) || 1; },
        on: function (name, fn) { (handlers[name] = handlers[name] || []).push(fn); },
        destroy: function () { if (el) { try { el.pause(); } catch (e) {} el.remove(); el = null; } handlers = {}; }
    };
}
engines.hls = nativeEngine;
engines.mp4 = nativeEngine;

function registerEngine(name, factory) { engines[name] = factory; }
function playing() { return !!(engine && !engine.paused()); }

function store(key, value) {
    try { if (value === undefined) return localStorage.getItem(key); if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value); } catch (e) { return null; }
}

// ── Where the dock stands ─────────────────────────────────────────────

function place() {
    if (!dock) return;
    dock.classList.toggle('is-playing', playing());
    if (stage && document.body.contains(stage)) {
        var rect = stage.getBoundingClientRect();
        dock.classList.remove('is-mini');
        dock.classList.add('is-attached');
        dock.hidden = false;
        dock.style.top = (rect.top + window.scrollY) + 'px';
        dock.style.left = (rect.left + window.scrollX) + 'px';
        dock.style.width = rect.width + 'px';
        dock.style.height = rect.height + 'px';
        return;
    }
    dock.classList.remove('is-attached');
    dock.style.top = dock.style.left = dock.style.width = dock.style.height = '';
    if (current && playing()) {
        dock.classList.add('is-mini');
        dock.hidden = false;
    } else {
        dock.classList.remove('is-mini');
        dock.hidden = true;
    }
}

function watchLayout() {
    if (resizeObserver) resizeObserver.disconnect();
    clearInterval(layoutTimer);
    if (stage && window.ResizeObserver) {
        resizeObserver = new ResizeObserver(place);
        resizeObserver.observe(stage);
        resizeObserver.observe(document.body);
    }
    // Fonts, pictures and sticky headers move things without resizing the stage.
    layoutTimer = setInterval(place, 400);
}

// ── Loading a film ────────────────────────────────────────────────────

function mount(next) {
    var data = next.dataset;
    stage = next;
    var same = current && current.id === data.id;
    if (!same) load(data);
    if (data.cinema !== undefined) cinema(store('video.cinema') === '1');
    place();
    watchLayout();
    var start = startAt(data);
    if (same && start !== null && new URLSearchParams(location.search).has('t')) seek(start, true);
}

function load(data) {
    if (current) flushBeacon('progress', true);
    if (engine) engine.destroy();

    engine = (engines[data.type] || nativeEngine)(controller, data);
    current = { id: data.id, slug: data.slug, url: data.url, title: data.title, duration: parseFloat(data.duration || '0'), beacon: data.beacon, source: data.source || 'direct', referrer: document.referrer };
    beacon = { token: token(), watched: 0, last: null, started: false, timer: null };
    var bar = $('[data-video-dock-title]', dock);
    if (bar) { bar.textContent = data.title || ''; bar.setAttribute('href', data.url || '#'); }

    var start = startAt(data);
    listen(engine, beacon, current);
    engine.on('ready', function () { if (start !== null) engine.seek(start); });
    engine.load(data);
    if (data.autoplay !== undefined) engine.play();
}

/** ?t= first, then the member's place on the server, then this browser's; not too near either end. */
function startAt(data) {
    var params = new URLSearchParams(location.search);
    var t = params.has('t') ? parseFloat(params.get('t')) : NaN;
    if (!isNaN(t)) return Math.max(0, t);
    var duration = parseFloat(data.duration || '0');
    var resume = parseFloat(data.resume || store('video.position.' + data.id) || 'NaN');
    if (!isNaN(resume) && resume > 5 && (!duration || resume < duration - 10)) return resume;
    return null;
}

function seek(seconds, play) {
    if (!engine) return;
    engine.seek(seconds);
    if (play && engine.paused()) engine.play();
}

// ── The beacons ───────────────────────────────────────────────────────

function listen(e, b, film) {
    // Bound to this film's engine and counters: a late event of the previous film changes nothing.
    var live = function () { return engine === e; };
    e.on('play', function () {
        if (!live()) return;
        b.last = e.time();
        if (!b.started) { b.started = true; send('start'); }
        clearInterval(b.timer);
        b.timer = setInterval(function () { if (live()) send('progress'); else clearInterval(b.timer); }, 10000);
        // One thing at a time on the page: the others hear it and pause.
        document.dispatchEvent(new CustomEvent('media:play', { detail: { source: 'video', id: film.id, element: e.element ? e.element() : null } }));
        place();
    });
    e.on('time', function () {
        if (!live()) return;
        var now = e.time();
        if (e.paused() || (e.seeking && e.seeking())) { b.last = now; return; }
        var delta = now - (b.last === null ? now : b.last);
        // Played, not jumped: a seek or a stall is not watching.
        if (delta > 0 && delta < 1.5 * e.rate() + 0.5) b.watched += delta;
        b.last = now;
        if (Math.floor(now) % 5 === 0) store('video.position.' + film.id, String(Math.floor(now)));
    });
    e.on('seeking', function () { if (live()) b.last = null; });
    e.on('pause', function () { if (!live()) return; clearInterval(b.timer); if (b.started) send('progress'); place(); });
    e.on('end', function () {
        if (!live()) return;
        clearInterval(b.timer);
        send('end');
        store('video.position.' + film.id, null);
        place();
    });
}

/** Something else started playing on the page: this player gives way. */
function yieldTo(event) {
    var detail = event.detail || {};
    if (detail.source === 'video' && current && String(detail.id) === String(current.id)) return;
    if (engine && !engine.paused()) engine.pause();
}

function payload(event) {
    return JSON.stringify({ token: beacon.token, event: event, position: engine ? engine.time() : 0, watched: Math.round(beacon.watched * 10) / 10, source: current.source, referrer: current.referrer });
}

function send(event) {
    if (!current || !current.beacon || !beacon) return;
    try {
        fetch(current.beacon, { method: 'POST', body: payload(event), headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', keepalive: true }).catch(function () {});
    } catch (e) { /* a beacon lost is a second not counted */ }
}

function flushBeacon(event) {
    if (!current || !current.beacon || !beacon || !beacon.started) return;
    if (navigator.sendBeacon) navigator.sendBeacon(current.beacon, new Blob([payload(event)], { type: 'application/json' }));
    else send(event);
}

// ── Cinema, sharing, timecodes, the dock's buttons ────────────────────

function cinema(on) {
    document.documentElement.classList.toggle('video-cinema', !!on);
    store('video.cinema', on ? '1' : '0');
    setTimeout(place, 50);
}

function clicks(e) {
    var seekLink = e.target.closest('a[data-seek]');
    if (seekLink && stage && current) {
        e.preventDefault();
        seek(parseFloat(seekLink.dataset.seek), true);
        stage.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }
    if (e.target.closest('[data-video-cinema]')) { cinema(!document.documentElement.classList.contains('video-cinema')); return; }
    var share = e.target.closest('[data-video-share]');
    if (share) {
        e.preventDefault();
        var url = new URL(share.dataset.videoShare || location.href, location.href);
        var withTime = share.closest('form, [data-video-share-box]');
        var box = withTime ? withTime.querySelector('[name="at_time"]') : null;
        if (engine && (!box || box.checked) && engine.time() > 1) url.searchParams.set('t', String(Math.floor(engine.time())));
        else url.searchParams.delete('t');
        var done = function () { share.classList.add('is-copied'); setTimeout(function () { share.classList.remove('is-copied'); }, 1800); };
        if (navigator.share && share.dataset.native !== undefined) navigator.share({ title: current ? current.title : document.title, url: url.toString() }).catch(function () {});
        else if (navigator.clipboard) navigator.clipboard.writeText(url.toString()).then(done, function () { window.prompt('', url.toString()); });
        else window.prompt('', url.toString());
        return;
    }
    if (e.target.closest('[data-video-dock-close]')) {
        if (engine) engine.pause();
        flushBeacon('progress');
        dock.hidden = true;
        dock.classList.remove('is-mini');
        return;
    }
    if (e.target.closest('[data-video-dock-toggle]')) {
        if (engine) { if (engine.paused()) engine.play(); else engine.pause(); }
    }
}

/** A comment carries the moment the player was at when it was written. */
function submits(e) {
    var form = e.target.closest && e.target.closest('form[data-video-moment]');
    if (!form) return;
    var field = form.querySelector('[name="moment"]');
    var wanted = form.querySelector('[name="with_moment"]');
    if (field) field.value = (engine && current && stage && (!wanted || wanted.checked) && engine.time() > 0) ? String(Math.floor(engine.time())) : '';
}

// ── Platforms' films: their own player, after consent ─────────────────

function embeds(root) {
    (root || document).querySelectorAll('[data-video-embed]:not([data-video-embed-ready])').forEach(function (box) {
        box.setAttribute('data-video-embed-ready', '');
        var load = function () {
            if (box.querySelector('iframe')) return;
            var frame = document.createElement('iframe');
            frame.src = box.dataset.videoEmbed;
            frame.title = box.dataset.title || '';
            frame.allow = 'autoplay; encrypted-media; fullscreen; picture-in-picture';
            frame.allowFullscreen = true;
            frame.referrerPolicy = 'strict-origin-when-cross-origin';
            frame.loading = 'lazy';
            box.innerHTML = '';
            box.appendChild(frame);
        };
        var button = box.querySelector('[data-video-embed-play]');
        if (window.Consent && typeof window.Consent.use === 'function') {
            var on = window.Consent.use('EMBEDS', {}, function () { if (box.dataset.loadNow !== undefined) load(); });
            if (button) button.addEventListener('click', function () {
                if (window.Consent.enabled('EMBEDS')) load();
                else { box.dataset.loadNow = ''; window.Consent.open(); }
            });
            if (on && box.dataset.autoload !== undefined) load();
        } else if (button) {
            button.addEventListener('click', load);
        }
    });
}

// ── The page ──────────────────────────────────────────────────────────

function scan() {
    var next = document.querySelector('[data-video-player]');
    stage = null;
    if (next && controller) mount(next);
    else { place(); watchLayout(); }
    embeds();
}

var VideoPlayer = {
    start: function () {
        if (started) return VideoPlayer;
        started = true;
        dock = document.getElementById('video-dock');
        if (!dock) return VideoPlayer;
        // A child of <body>: positioned against the document, never inside a transformed box.
        if (dock.parentNode !== document.body) document.body.appendChild(dock);
        controller = dock.querySelector('media-controller');
        document.addEventListener('click', clicks);
        document.addEventListener('submit', submits, true);
        document.addEventListener('media:play', yieldTo);
        window.addEventListener('resize', place);
        window.addEventListener('transparent:load', scan);
        window.addEventListener('pagehide', function () { flushBeacon('progress'); });
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flushBeacon('progress'); });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan, { once: true });
        else scan();
        return VideoPlayer;
    },
    registerEngine: registerEngine,
    engine: function () { return engine; },
    seek: seek,
    current: function () { return current; },
    media: media
};

window.VideoPlayer = VideoPlayer;
export default VideoPlayer;
