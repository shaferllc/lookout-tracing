/**
 * Lookout RUM — Web Vitals + SPA / Livewire navigation + custom interaction timers → POST /api/ingest/rum
 * No dependencies. Use api_key inside JSON body for sendBeacon compatibility.
 *
 *   LookoutRum.init({
 *     endpoint: 'https://your-lookout.example/api/ingest/rum',
 *     apiKey: 'your-project-api-key',
 *     environment: 'production',
 *     release: '1.0.0',
 *     livewireNavigate: true,
 *     extra: { app: 'checkout' },
 *     traceId: () => document.querySelector('meta[name="lookout-trace-id"]')?.content?.trim() || null,
 *     clientRoute: () => window.location.pathname,
 *   });
 *
 *   var t = LookoutRum.start('checkout.submit', { step: 'payment' });
 *   t.stop({ success: true });
 *
 *   LookoutRum.time('import.csv', () => runImport(), { rows: 100 });
 */
(function (global) {
  'use strict';

  var state = {
    initialized: false,
    endpoint: '',
    dumpEndpoint: '',
    apiKey: '',
    environment: null,
    release: null,
    traceId: null,
    clientRoute: null,
    livewireNavigate: false,
    defaultExtra: {},
    vitals: { lcp_ms: null, inp_ms: null, cls: null, fcp_ms: null, ttfb_ms: null },
    clsSession: 0,
    inpMax: null,
    observers: [],
  };

  var DUMP_REDACT_KEYS = [
    'password', 'pass', 'pwd', 'secret', 'token', 'api_key', 'apikey',
    'authorization', 'auth', 'access_token', 'refresh_token', 'private_key',
    'card', 'card_number', 'cvv', 'cvc', 'ssn',
  ];

  var DUMP_LIMITS = { maxDepth: 6, maxChildren: 100, maxString: 8192, maxTotalBytes: 262144 };

  function dumpRedactKey(key) {
    if (key == null) return false;
    var needle = String(key).toLowerCase();
    for (var i = 0; i < DUMP_REDACT_KEYS.length; i++) {
      if (needle === DUMP_REDACT_KEYS[i] || needle.indexOf(DUMP_REDACT_KEYS[i]) !== -1) return true;
    }
    return false;
  }

  function dumpCap(str, max) {
    return str.length > max ? str.slice(0, max) : str;
  }

  /**
   * Serialize an arbitrary JS value into the normalized dump tree the PHP/Rails SDKs emit:
   * {type, class?, key?, value?, preview?, children?, truncated?, ref?}. Bounded by depth, child
   * count, string length and total size, with cycle detection and key-based redaction.
   */
  function serializeDump(value) {
    var ctx = { totalBytes: 0, truncated: false, seen: [] };
    var tree = dumpNode(value, null, 0, ctx);
    return {
      tree: tree,
      preview: typeof tree.preview === 'string' ? tree.preview : dumpDescribe(value),
      root_type: typeof tree.type === 'string' ? tree.type : dumpTypeOf(value),
      root_class: dumpClassOf(value),
      truncated: ctx.truncated,
    };
  }

  function dumpTypeOf(value) {
    if (value === null || value === undefined) return 'null';
    if (typeof value === 'string') return 'string';
    if (typeof value === 'boolean') return 'bool';
    if (typeof value === 'number') return Number.isInteger(value) ? 'int' : 'float';
    if (Array.isArray(value)) return 'array';
    if (typeof value === 'function') return 'function';
    if (typeof value === 'object') return 'object';
    return 'string';
  }

  function dumpClassOf(value) {
    if (value && typeof value === 'object' && !Array.isArray(value) && value.constructor && value.constructor.name && value.constructor.name !== 'Object') {
      return value.constructor.name.slice(0, 255);
    }
    return null;
  }

  function dumpDescribe(value) {
    if (Array.isArray(value)) return 'array:' + value.length;
    if (value && typeof value === 'object') return dumpClassOf(value) || 'object';
    if (typeof value === 'string') return dumpCap(value, 64);
    return dumpTypeOf(value);
  }

  function dumpNode(value, key, depth, ctx) {
    var n = {};
    if (key !== null && key !== undefined) n.key = dumpCap(String(key), 128);

    if (key !== null && key !== undefined && dumpRedactKey(key)) {
      ctx.truncated = true;
      n.type = 'redacted';
      n.preview = '[redacted]';
      return n;
    }

    if (depth >= DUMP_LIMITS.maxDepth) {
      ctx.truncated = true;
      n.type = 'truncated';
      n.preview = dumpDescribe(value);
      return n;
    }

    if (value === null || value === undefined) {
      n.type = 'null';
      n.value = null;
      ctx.totalBytes += 8;
      return n;
    }

    var t = typeof value;
    if (t === 'string') return dumpScalarString(n, value, ctx);
    if (t === 'number') {
      n.type = Number.isInteger(value) ? 'int' : 'float';
      n.value = value;
      ctx.totalBytes += 8;
      return n;
    }
    if (t === 'boolean') {
      n.type = 'bool';
      n.value = value;
      ctx.totalBytes += 8;
      return n;
    }
    if (t === 'bigint' || t === 'symbol') {
      return dumpScalarString(n, String(value), ctx);
    }
    if (t === 'function') {
      n.type = 'function';
      n.preview = 'function ' + (value.name || 'anonymous');
      return n;
    }

    // object or array — reference types can cycle
    if (ctx.seen.indexOf(value) !== -1) {
      n.type = 'ref';
      n.ref = ctx.seen.indexOf(value);
      n.preview = (dumpClassOf(value) || 'object') + ' {ref}';
      return n;
    }
    ctx.seen.push(value);

    if (Array.isArray(value)) {
      dumpContainer(n, value.map(function (v, i) { return [i, v]; }), 'array', null, depth, ctx);
    } else {
      var klass = dumpClassOf(value);
      var pairs = [];
      for (var k in value) {
        if (Object.prototype.hasOwnProperty.call(value, k)) pairs.push([k, value[k]]);
      }
      // Plain objects ({}) are the map equivalent of a PHP associative array → 'array';
      // only class instances are 'object'.
      dumpContainer(n, pairs, klass ? 'object' : 'array', klass, depth, ctx);
    }

    ctx.seen.pop();
    return n;
  }

  function dumpScalarString(n, value, ctx) {
    var capped = dumpCap(value, DUMP_LIMITS.maxString);
    if (capped.length < value.length) {
      ctx.truncated = true;
      n.truncated = true;
    }
    n.type = 'string';
    n.value = capped;
    ctx.totalBytes += capped.length;
    return n;
  }

  function dumpContainer(n, pairs, type, klass, depth, ctx) {
    n.type = type;
    if (klass) n.class = dumpCap(klass, 255);
    var count = pairs.length;
    n.preview = klass ? klass + ' {#' + count + '}' : 'array:' + count + ' […]';

    var children = [];
    for (var i = 0; i < pairs.length; i++) {
      if (i >= DUMP_LIMITS.maxChildren) {
        ctx.truncated = true;
        children.push({ type: 'truncated', preview: '+' + (count - DUMP_LIMITS.maxChildren) + ' more' });
        break;
      }
      if (ctx.totalBytes >= DUMP_LIMITS.maxTotalBytes) {
        ctx.truncated = true;
        children.push({ type: 'truncated', preview: '…' });
        break;
      }
      children.push(dumpNode(pairs[i][1], pairs[i][0], depth + 1, ctx));
    }
    if (children.length) n.children = children;
    return n;
  }

  function postDump(entry) {
    if (!state.initialized || !state.dumpEndpoint || !state.apiKey) return;
    var body = JSON.stringify({ api_key: state.apiKey, entries: [entry] });
    var blob = new Blob([body], { type: 'application/json' });
    try {
      if (global.navigator && global.navigator.sendBeacon && global.navigator.sendBeacon(state.dumpEndpoint, blob)) {
        return;
      }
    } catch (e) {}
    try {
      global.fetch(state.dumpEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        keepalive: true,
        mode: 'cors',
      });
    } catch (e2) {}
  }

  function resolve(fnOrVal) {
    if (fnOrVal == null) return null;
    if (typeof fnOrVal === 'function') {
      try {
        return fnOrVal();
      } catch (e) {
        return null;
      }
    }
    return fnOrVal;
  }

  function mergeExtra(base, extra) {
    var out = {};
    var k;
    base = base && typeof base === 'object' ? base : {};
    extra = extra && typeof extra === 'object' ? extra : {};
    for (k in base) {
      if (Object.prototype.hasOwnProperty.call(base, k)) out[k] = base[k];
    }
    for (k in extra) {
      if (Object.prototype.hasOwnProperty.call(extra, k)) out[k] = extra[k];
    }
    return out;
  }

  function basePayload(navType, overrides) {
    overrides = overrides || {};
    var tid = overrides.trace_id != null ? overrides.trace_id : resolve(state.traceId);
    if (tid && typeof tid === 'string' && !/^[a-f0-9]{32}$/i.test(tid)) tid = null;
    var extra = mergeExtra(state.defaultExtra, overrides.extra);
    var payload = {
      api_key: state.apiKey,
      page_url: overrides.page_url != null ? String(overrides.page_url).slice(0, 2048) : String(global.location.href || '').slice(0, 2048),
      navigation_type: navType,
      client_route: overrides.client_route != null ? overrides.client_route : resolve(state.clientRoute),
      trace_id: tid ? tid.toLowerCase() : null,
      environment: overrides.environment != null ? overrides.environment : state.environment,
      release: overrides.release != null ? overrides.release : state.release,
      lcp_ms: overrides.lcp_ms != null ? overrides.lcp_ms : state.vitals.lcp_ms,
      inp_ms: overrides.inp_ms != null ? overrides.inp_ms : state.vitals.inp_ms,
      fcp_ms: overrides.fcp_ms != null ? overrides.fcp_ms : state.vitals.fcp_ms,
      ttfb_ms: overrides.ttfb_ms != null ? overrides.ttfb_ms : state.vitals.ttfb_ms,
      cls: overrides.cls != null ? overrides.cls : state.vitals.cls,
    };
    if (Object.keys(extra).length) payload.extra = extra;
    return payload;
  }

  function stripNulls(o) {
    var out = {};
    for (var k in o) {
      if (Object.prototype.hasOwnProperty.call(o, k) && o[k] != null) out[k] = o[k];
    }
    return out;
  }

  function send(navType, overrides) {
    if (!state.initialized || !state.endpoint || !state.apiKey) return;
    var body = JSON.stringify(stripNulls(basePayload(navType, overrides || {})));
    var blob = new Blob([body], { type: 'application/json' });
    try {
      if (global.navigator && global.navigator.sendBeacon && global.navigator.sendBeacon(state.endpoint, blob)) {
        return;
      }
    } catch (e) {}
    try {
      global.fetch(state.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        keepalive: true,
        mode: 'cors',
      });
    } catch (e2) {}
  }

  function readTtfbFcp() {
    try {
      var nav = performance.getEntriesByType('navigation')[0];
      if (nav && nav.responseStart > 0 && nav.requestStart > 0) {
        state.vitals.ttfb_ms = Math.max(0, Math.round(nav.responseStart - nav.requestStart));
      }
    } catch (e) {}
    try {
      var paints = performance.getEntriesByType('paint');
      for (var i = 0; i < paints.length; i++) {
        if (paints[i].name === 'first-contentful-paint') {
          state.vitals.fcp_ms = Math.max(0, Math.round(paints[i].startTime));
          break;
        }
      }
    } catch (e2) {}
  }

  function observeLcp() {
    if (!global.PerformanceObserver) return;
    try {
      var po = new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        if (!entries.length) return;
        var last = entries[entries.length - 1];
        state.vitals.lcp_ms = Math.max(0, Math.round(last.renderTime || last.loadTime || last.startTime));
      });
      po.observe({ type: 'largest-contentful-paint', buffered: true });
      state.observers.push(po);
    } catch (e) {}
  }

  function observeCls() {
    if (!global.PerformanceObserver) return;
    try {
      var po = new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        for (var i = 0; i < entries.length; i++) {
          var e = entries[i];
          if (!e.hadRecentInput) state.clsSession += e.value || 0;
        }
        state.vitals.cls = Math.round(state.clsSession * 1000000) / 1000000;
      });
      po.observe({ type: 'layout-shift', buffered: true });
      state.observers.push(po);
    } catch (e) {}
  }

  function observeInp() {
    if (!global.PerformanceObserver) return;
    try {
      var po = new PerformanceObserver(function (list) {
        var entries = list.getEntries();
        for (var i = 0; i < entries.length; i++) {
          var e = entries[i];
          if (e.interactionId && typeof e.duration === 'number') {
            var d = Math.round(e.duration);
            if (state.inpMax == null || d > state.inpMax) state.inpMax = d;
          }
        }
        if (state.inpMax != null) state.vitals.inp_ms = state.inpMax;
      });
      po.observe({ type: 'event', buffered: true, durationThreshold: 16 });
      state.observers.push(po);
    } catch (e) {}
  }

  function onVisibility() {
    if (document.visibilityState === 'hidden') {
      send('visibility_change');
    }
  }

  function noopTimer() {
    return {
      stop: function () {
        return 0;
      },
      cancel: function () {},
      isFinished: function () {
        return true;
      },
    };
  }

  var globalApi = {
    init: function (cfg) {
      if (!cfg || !cfg.endpoint || !cfg.apiKey) return;
      state.initialized = true;
      state.endpoint = String(cfg.endpoint).replace(/\/$/, '');
      state.dumpEndpoint = cfg.dumpEndpoint
        ? String(cfg.dumpEndpoint).replace(/\/$/, '')
        : state.endpoint.replace(/\/rum$/, '/dump');
      state.apiKey = String(cfg.apiKey);
      state.environment = cfg.environment != null ? String(cfg.environment) : null;
      state.release = cfg.release != null ? String(cfg.release) : null;
      state.defaultExtra = cfg.extra && typeof cfg.extra === 'object' ? mergeExtra({}, cfg.extra) : {};
      state.traceId =
        cfg.traceId != null
          ? cfg.traceId
          : function () {
              var m = document.querySelector('meta[name="lookout-trace-id"]');
              return m && m.content ? m.content.trim() : null;
            };
      state.clientRoute =
        cfg.clientRoute != null
          ? cfg.clientRoute
          : function () {
              return global.location.pathname;
            };
      state.livewireNavigate = !!cfg.livewireNavigate;

      readTtfbFcp();
      observeLcp();
      observeCls();
      observeInp();

      global.addEventListener('pageshow', function () {
        readTtfbFcp();
      });

      global.addEventListener('load', function () {
        readTtfbFcp();
        send('load');
      });

      document.addEventListener('visibilitychange', onVisibility);

      global.addEventListener('popstate', function () {
        send('popstate');
      });

      if (state.livewireNavigate) {
        document.addEventListener('livewire:navigated', function () {
          send('livewire_navigate');
        });
      }
    },

    /** Merge session-level attributes onto every beacon (until changed). */
    setExtra: function (keyOrObj, val) {
      if (keyOrObj && typeof keyOrObj === 'object') {
        state.defaultExtra = mergeExtra(state.defaultExtra, keyOrObj);
      } else if (typeof keyOrObj === 'string') {
        state.defaultExtra[keyOrObj] = val;
      }
    },

    /** Send a beacon with optional overrides (navigation_type, extra, vitals, trace_id, …). */
    send: function (options) {
      options = options || {};
      var nav = options.navigation_type || options.navigationType || 'manual';
      send(nav, options);
    },

    /** Start a custom interaction timer; {@code stop()} records duration_ms in extra. */
    start: function (name, extra) {
      if (!state.initialized) return noopTimer();
      var startMs = global.performance && performance.now ? performance.now() : Date.now();
      var baseExtra = mergeExtra(state.defaultExtra, extra || {});
      if (name) baseExtra.interaction = String(name);
      var traceAtStart = resolve(state.traceId);
      var finished = false;
      return {
        stop: function (moreExtra) {
          if (finished || !state.initialized) return 0;
          finished = true;
          var endMs = global.performance && performance.now ? performance.now() : Date.now();
          var durationMs = Math.max(0, Math.round(endMs - startMs));
          var payloadExtra = mergeExtra(baseExtra, moreExtra || {});
          payloadExtra.duration_ms = durationMs;
          send('interaction', { extra: payloadExtra, trace_id: traceAtStart });
          return durationMs;
        },
        cancel: function () {
          finished = true;
        },
        isFinished: function () {
          return finished;
        },
      };
    },

    /** Run fn (sync or Promise) and record wall time as an interaction beacon. */
    time: function (name, fn, extra) {
      if (!state.initialized || typeof fn !== 'function') return fn && fn();
      var timer = globalApi.start(name, extra);
      try {
        var result = fn();
        if (result && typeof result.then === 'function') {
          return result.then(
            function (value) {
              if (!timer.isFinished()) timer.stop({ success: true });
              return value;
            },
            function (err) {
              if (!timer.isFinished()) {
                timer.stop({
                  success: false,
                  error: err && err.message ? String(err.message).slice(0, 500) : String(err),
                });
              }
              throw err;
            }
          );
        }
        if (!timer.isFinished()) timer.stop({ success: true });
        return result;
      } catch (err) {
        if (!timer.isFinished()) {
          timer.stop({
            success: false,
            error: err && err.message ? String(err.message).slice(0, 500) : String(err),
          });
        }
        throw err;
      }
    },

    /**
     * Explicit dump API: capture a value (as a normalized, redacted tree) to the Lookout Dumps watcher.
     * Returns the value so it can be used inline: {@code var u = LookoutRum.dump(user, 'user');}
     */
    dump: function (value, label) {
      if (!state.initialized || !state.dumpEndpoint || !state.apiKey) return value;
      try {
        var result = serializeDump(value);
        var entry = {
          source: 'javascript',
          format: 'json',
          preview: result.preview,
          root_type: result.root_type,
          root_class: result.root_class,
          tree: result.tree,
          truncated: result.truncated,
          trace_id: (function () {
            var tid = resolve(state.traceId);
            return tid && typeof tid === 'string' && /^[a-f0-9]{32}$/i.test(tid) ? tid.toLowerCase() : null;
          })(),
          environment: state.environment,
          release: state.release,
        };
        if (label != null) entry.label = String(label).slice(0, 512);
        postDump(stripNulls(entry));
      } catch (e) {}
      return value;
    },

    /** @deprecated alias */
    sendManual: function (overrides) {
      globalApi.send(overrides || { navigation_type: 'manual' });
    },

    /** @deprecated alias */
    flush: function () {
      globalApi.send({ navigation_type: 'manual' });
    },
  };

  global.LookoutRum = globalApi;
})(typeof window !== 'undefined' ? window : this);
