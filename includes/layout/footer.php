<?php
// ============================================================
// المسار: includes/layout/footer.php
// الوظيفة: ذيل الصفحة المشترك — يُغلق كل العناصر المفتوحة
//          ويُحمّل Bootstrap JS + سكريبت CSRF للـ AJAX
// الاعتمادات: يُضمَّن بعد محتوى الصفحة مباشرةً
// المتغيرات المتاحة من header.php:
//   $lang, $lang_code, $csrf_token, $is_rtl, $depth
// ============================================================

// ——— التحقق من تعريف المتغيرات الأساسية كحماية ———
$lang_code  = $lang_code  ?? ($_SESSION['lang'] ?? 'ar');
$lang       = $lang       ?? [];
$csrf_token = $csrf_token ?? ($_SESSION['csrf_token'] ?? '');
$is_rtl     = $is_rtl     ?? ($lang_code === 'ar');
$base_path  = $base_path  ?? '';
?>

    </main><!-- /.ts-main-content -->
</div><!-- /.ts-wrapper -->

<!-- ============================================================
     Bootstrap 5 Bundle JS (يشمل Popper)
     ============================================================ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- ============================================================
     تحقق من تحميل Bootstrap
     ============================================================ -->
<script>
if (typeof bootstrap === 'undefined') {
    console.error('Bootstrap JS failed to load!');
}
</script>

<!-- ============================================================
     CSRF Token — يُضاف تلقائياً لكل AJAX request
     ============================================================ -->
<script>
(function () {
    'use strict';

    var CSRF_TOKEN = <?= json_encode($csrf_token) ?>;

    /**
     * يُعيد توجيه كل fetch() ليحمل X-CSRF-Token تلقائياً
     * ويتعامل مع انتهاء الجلسة (403) بإعادة التوجيه لتسجيل الدخول
     */
    var _originalFetch = window.fetch;
    window.fetch = function (input, init) {
        init = init || {};
        init.headers = init.headers || {};

        // إضافة الـ token فقط للطلبات غير GET/HEAD
        var method = (init.method || 'GET').toUpperCase();
        if (method !== 'GET' && method !== 'HEAD') {
            if (init.headers instanceof Headers) {
                if (!init.headers.has('X-CSRF-Token')) {
                    init.headers.set('X-CSRF-Token', CSRF_TOKEN);
                }
            } else {
                init.headers['X-CSRF-Token'] = init.headers['X-CSRF-Token'] || CSRF_TOKEN;
            }
        }

        return _originalFetch.call(this, input, init).then(function (response) {
            // انتهت الجلسة أو CSRF مرفوض → إعادة التوجيه لتسجيل الدخول
            if (response.status === 403) {
                response.clone().json().then(function (data) {
                    if (data && data.message &&
                        (data.message.includes('جلسة') || data.message.includes('session') ||
                         data.message.includes('expired') || data.message.includes('CSRF'))) {
                        window.location.href = <?= json_encode(($base_path ?? '') . 'auth/login.php') ?>;
                    }
                }).catch(function () {});
            }
            return response;
        });
    };

    /**
     * يُعيد توجيه XMLHttpRequest ليحمل X-CSRF-Token تلقائياً
     * (دعم الكود القديم الذي لا يستخدم fetch)
     */
    var _XHR_open = XMLHttpRequest.prototype.open;
    var _XHR_send = XMLHttpRequest.prototype.send;
    var _pendingMethod = '';

    XMLHttpRequest.prototype.open = function (method) {
        _pendingMethod = method.toUpperCase();
        return _XHR_open.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
        if (_pendingMethod !== 'GET' && _pendingMethod !== 'HEAD') {
            this.setRequestHeader('X-CSRF-Token', CSRF_TOKEN);
        }
        return _XHR_send.apply(this, arguments);
    };

    /**
     * دالة مساعدة عامة — AJAX POST بـ fetch
     * الاستخدام: tsPost('/api/save_invoice.php', data).then(...)
     *
     * @param {string} url
     * @param {Object} data
     * @returns {Promise<Object>}
     */
    window.tsPost = function (url, data) {
        return fetch(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(data)
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    };

    /**
     * دالة مساعدة عامة — AJAX GET بـ fetch
     * الاستخدام: tsGet('/api/search_products.php', {q: 'كريم'}).then(...)
     *
     * @param {string} url
     * @param {Object} params
     * @returns {Promise<Object>}
     */
    window.tsGet = function (url, params) {
        params = params || {};
        var qs = Object.keys(params)
            .filter(function (k) { return params[k] !== null && params[k] !== undefined; })
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
            .join('&');
        if (qs) url += (url.includes('?') ? '&' : '?') + qs;
        return fetch(url, { method: 'GET' }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    };

    /**
     * عرض Toast notification خفيف
     * الاستخدام: tsToast('تم الحفظ', 'success')
     *
     * @param {string} message
     * @param {'success'|'error'|'warning'|'info'} type
     * @param {number} duration  مدة الظهور بالمللي ثانية
     */
    window.tsToast = function (message, type, duration) {
        type     = type     || 'info';
        duration = duration || 3500;

        var colors = {
            success: { bg: 'var(--ts-success)',  icon: '✓' },
            error:   { bg: 'var(--ts-danger)',   icon: '✕' },
            warning: { bg: 'var(--ts-gold)',      icon: '⚠' },
            info:    { bg: 'var(--ts-silver)',    icon: 'ℹ' }
        };
        var c = colors[type] || colors.info;

        // حاوية الـ Toasts
        var container = document.getElementById('ts-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'ts-toast-container';
            container.style.cssText = [
                'position:fixed',
                'bottom:1.5rem',
                <?= $is_rtl ? "'left:1.5rem'" : "'right:1.5rem'" ?>,
                'z-index:9999',
                'display:flex',
                'flex-direction:column',
                'gap:.5rem',
                'max-width:320px',
                'pointer-events:none'
            ].join(';');
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.style.cssText = [
            'background:' + c.bg,
            'color:' + (type === 'warning' ? '#0D0D0D' : '#fff'),
            'padding:.65rem 1rem',
            'border-radius:6px',
            'font-size:.88rem',
            'font-weight:500',
            'box-shadow:0 4px 12px rgba(0,0,0,.4)',
            'display:flex',
            'align-items:center',
            'gap:.5rem',
            'opacity:0',
            'transform:translateY(8px)',
            'transition:opacity .25s,transform .25s',
            'pointer-events:auto',
            'font-family:Tajawal,sans-serif',
            'direction:' + (<?= $is_rtl ? 'true' : 'false' ?> ? 'rtl' : 'ltr')
        ].join(';');

        toast.innerHTML = '<span style="font-size:1rem">' + c.icon + '</span>'
                        + '<span>' + message + '</span>';

        container.appendChild(toast);

        // Animation ظهور
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                toast.style.opacity  = '1';
                toast.style.transform = 'translateY(0)';
            });
        });

        // إخفاء تلقائي
        setTimeout(function () {
            toast.style.opacity   = '0';
            toast.style.transform = 'translateY(8px)';
            setTimeout(function () {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }, 280);
        }, duration);
    };

    /**
     * تأكيد حذف/تعطيل مع dialog عربي/إنجليزي
     * الاستخدام: if (tsConfirm()) { ... }
     *
     * @param {string} message  نص التأكيد (اختياري)
     * @returns {boolean}
     */
    window.tsConfirm = function (message) {
        message = message || <?= json_encode($lang['confirm_action'] ?? 'هل أنت متأكد؟') ?>;
        return window.confirm(message);
    };

    /**
     * تنسيق المبلغ المالي — JavaScript
     * الاستخدام: tsFormatMoney(1500.5) // '1,500.50 SDG'
     *
     * @param {number} amount
     * @returns {string}
     */
    window.tsFormatMoney = function (amount) {
        amount = parseFloat(amount) || 0;
        return amount.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' SDG';
    };

    /**
     * Debounce — لتأخير تنفيذ دالة (مفيد لحقول البحث)
     * الاستخدام: input.addEventListener('input', tsDebounce(fn, 300))
     *
     * @param {Function} fn
     * @param {number}   wait  مدة الانتظار بالمللي ثانية
     * @returns {Function}
     */
    window.tsDebounce = function (fn, wait) {
        var timer;
        return function () {
            var ctx  = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, wait || 300);
        };
    };

})();
</script>

<!-- ============================================================
     سكريبت إضافي خاص بالصفحة (اختياري)
     يُعرَّف في الصفحة قبل include footer كـ $extra_js
     ============================================================ -->
<?php if (!empty($extra_js)): ?>
<script><?= $extra_js ?></script>
<?php endif; ?>

<!-- ============================================================
     Footer Bar — شريط الذيل
     ============================================================ -->
<footer class="no-print" style="
    text-align: center;
    padding: .6rem 1rem;
    font-size: .75rem;
    color: var(--ts-text-muted);
    border-top: 1px solid var(--ts-border);
    background-color: var(--ts-bg-dark);
    margin-top: auto;
">
    <span style="color:var(--ts-gold); font-weight:600;">Top Shine</span>
    &nbsp;✦&nbsp;
    <?= ($lang_code === 'ar') ? 'توب شاين' : 'Top Shine POS' ?>
    &nbsp;&copy;&nbsp;<?= date('Y') ?>
</footer>

</body>
</html>