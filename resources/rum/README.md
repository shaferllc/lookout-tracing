# Lookout RUM (browser)

Vanilla JavaScript for Real User Monitoring: Web Vitals plus optional SPA / Livewire navigation beacons to your Lookout ingest endpoint.

This directory is the canonical source inside the `lookout/tracing` Composer package (`resources/rum/lookout-rum.js`). A separate Git repository may mirror only this folder for npm or CDN workflows; bump `version` in `package.json` when you cut a JS release.

See the tracing package README for `LookoutRum.init({ … })` options.
