/**
 * Lookout RUM — Web Vitals + SPA / Livewire navigation beacons → POST /api/ingest/rum
 * No dependencies. Use api_key inside JSON body for sendBeacon compatibility.
 *
 *   LookoutRum.init({
 *     endpoint: 'https://your-lookout.example/api/ingest/rum',
 *     apiKey: 'your-project-api-key',
 *     environment: 'production',
 *     release: '1.0.0',
 *     livewireNavigate: true,
 *     traceId: () => document.querySelector('meta[name="lookout-trace-id"]')?.content?.trim() || null,
 *     clientRoute: () => window.location.pathname,
 *   });
 */
(function (global) {
  'use strict';

  var state = {
    endpoint: '',
    apiKey: '',
    environment: null,
    release: null,
    traceId: null,
    clientRoute: null,
    livewireNavigate: false,
    vitals: { lcp_ms: null, inp_ms: null, cls: null, fcp_ms: null, ttfb_ms: null },
    clsSession: 0,
    inpMax: null,
    observers: [],
  };

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

  function basePayload(navType) {
    var tid = resolve(state.traceId);
    if (tid && typeof tid === 'string' && !/^[a-f0-9]{32}$/i.test(tid)) tid = null;
    return {
      api_key: state.apiKey,
      page_url: String(global.location.href || '').slice(0, 2048),
      navigation_type: navType,
      client_route: resolve(state.clientRoute),
      trace_id: tid ? tid.toLowerCase() : null,
      environment: state.environment,
      release: state.release,
      lcp_ms: state.vitals.lcp_ms,
      inp_ms: state.vitals.inp_ms,
      fcp_ms: state.vitals.fcp_ms,
      ttfb_ms: state.vitals.ttfb_ms,
      cls: state.vitals.cls,
    };
  }

  function stripNulls(o) {
    var out = {};
    for (var k in o) {
      if (Object.prototype.hasOwnProperty.call(o, k) && o[k] != null) out[k] = o[k];
    }
    return out;
  }

  function send(navType) {
    if (!state.endpoint || !state.apiKey) return;
    var body = JSON.stringify(stripNulls(basePayload(navType)));
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

  var globalApi = {
    init: function (cfg) {
      if (!cfg || !cfg.endpoint || !cfg.apiKey) return;
      state.endpoint = String(cfg.endpoint).replace(/\/$/, '');
      state.apiKey = String(cfg.apiKey);
      state.environment = cfg.environment != null ? String(cfg.environment) : null;
      state.release = cfg.release != null ? String(cfg.release) : null;
      state.traceId =
        cfg.traceId != null
          ? cfg.traceId
          : function () {
              var m = document.querySelector('meta[name="lookout-trace-id"]');
              return m && m.content ? m.content.trim() : null;
            };
      state.clientRoute = cfg.clientRoute != null ? cfg.clientRoute : function () {
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

    /** Send a beacon immediately (e.g. after manual route change). */
    sendManual: function () {
      send('manual');
    },

    /** @deprecated alias */
    flush: function () {
      send('manual');
    },
  };

  global.LookoutRum = globalApi;
})(typeof window !== 'undefined' ? window : this);
