// ERP 通用 fetch 包装
// 用法:在页面 </body> 前加 <script src="assets/erp-fetch.js"></script>
// 效果:自动给 POST/PUT/DELETE/PATCH 请求加 X-CSRF-Token header
// 依赖:页面 head 里有 <meta name="csrf-token" content="...">

(function() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) {
        console.warn('[erp-fetch] no <meta name="csrf-token"> found, CSRF auto-injection disabled');
        return;
    }
    var token = meta.getAttribute('content');
    var UNSAFE = ['POST', 'PUT', 'DELETE', 'PATCH'];

    var origFetch = window.fetch.bind(window);
    window.fetch = function(input, init) {
        init = init || {};
        var method = (init.method || (input && input.method) || 'GET').toUpperCase();
        if (UNSAFE.indexOf(method) >= 0) {
            init.headers = init.headers || {};
            // Headers 对象 / 数组 / 普通对象都处理
            if (init.headers instanceof Headers) {
                if (!init.headers.has('X-CSRF-Token')) init.headers.set('X-CSRF-Token', token);
            } else if (Array.isArray(init.headers)) {
                var has = init.headers.some(function(h) {
                    return h[0].toLowerCase() === 'x-csrf-token';
                });
                if (!has) init.headers.push(['X-CSRF-Token', token]);
            } else {
                if (!init.headers['X-CSRF-Token'] && !init.headers['x-csrf-token']) {
                    init.headers['X-CSRF-Token'] = token;
                }
            }
        }
        return origFetch(input, init);
    };
})();
