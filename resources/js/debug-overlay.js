/**
 * Lookout debug overlay: when a Livewire update fails with a 500 whose body is
 * the Lookout debug page (detected by its embedded copy-data island), show it
 * in a full-screen iframe instead of Livewire's default "whoops" dialog.
 * Injected only when the debug page is enabled — dev tooling, not a shipped asset.
 */
(function () {
    'use strict';

    function showOverlay(html) {
        var existing = document.getElementById('lk-debug-overlay');
        if (existing) { existing.remove(); }

        var wrap = document.createElement('div');
        wrap.id = 'lk-debug-overlay';
        wrap.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(9,10,14,.72);display:flex;flex-direction:column;padding:24px;';

        var bar = document.createElement('div');
        bar.style.cssText = 'display:flex;justify-content:flex-end;margin-bottom:8px;';
        var close = document.createElement('button');
        close.textContent = 'Close (Esc)';
        close.style.cssText = 'background:#171a21;color:#e6e9ef;border:1px solid #262c38;border-radius:6px;padding:6px 14px;font:600 12px -apple-system,sans-serif;cursor:pointer;';
        close.addEventListener('click', remove);
        bar.appendChild(close);

        var frame = document.createElement('iframe');
        frame.style.cssText = 'flex:1;width:100%;border:1px solid #262c38;border-radius:10px;background:#0f1115;';
        frame.setAttribute('sandbox', 'allow-scripts allow-popups allow-modals');
        frame.srcdoc = html;

        function remove() {
            wrap.remove();
            document.removeEventListener('keydown', onKey);
        }
        function onKey(e) { if (e.key === 'Escape') { remove(); } }
        document.addEventListener('keydown', onKey);

        wrap.appendChild(bar);
        wrap.appendChild(frame);
        document.body.appendChild(wrap);
    }

    function isDebugPage(content) {
        return typeof content === 'string' && content.indexOf('lk-copy-data') !== -1;
    }

    document.addEventListener('livewire:init', function () {
        if (!window.Livewire || typeof window.Livewire.hook !== 'function') { return; }
        window.Livewire.hook('request', function (ctx) {
            if (typeof ctx.fail !== 'function') { return; }
            ctx.fail(function (failure) {
                if (failure && failure.status === 500 && isDebugPage(failure.content)) {
                    if (typeof failure.preventDefault === 'function') { failure.preventDefault(); }
                    showOverlay(failure.content);
                }
            });
        });
    });
})();
