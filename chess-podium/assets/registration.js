(function() {
    var forms = document.querySelectorAll('.cp-reg-form');
    forms.forEach(function(form) {
        var tid = form.dataset.tid;
        var rest = form.dataset.rest;
        var freeRest = form.dataset.freeRest;
        var nonce = form.dataset.nonce;
        var isFree = form.dataset.free === '1';

        function submit(method) {
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            var fd = new FormData(form);
            fd.append('tournament_id', tid);
            var data = {};
            fd.forEach(function(v, k) { data[k] = v; });
            if (form.dataset.return) data.return_url = form.dataset.return;

            var endpoint = (isFree || method === 'free') ? freeRest : rest;
            if (!endpoint) {
                alert('Registration endpoint not configured.');
                return;
            }
            if (method !== 'free') {
                fd.append('payment_method', method);
                data.payment_method = method;
            }
            data._wpnonce = nonce;

            var btn = form.querySelector('.cp-reg-free') || form.querySelector('.cp-pay-' + method);
            if (btn) btn.disabled = true;

            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify(data),
                credentials: 'same-origin'
            }).then(function(r) {
                var ct = r.headers.get('Content-Type') || '';
                if (ct.indexOf('json') >= 0) return r.json();
                return r.text().then(function(t) { throw new Error(t || 'Invalid response'); });
            }).then(function(res) {
                var url = res.url || res.redirectUrl;
                if (res.success && url) {
                    window.location.href = url;
                } else {
                    alert(res.message || (res.data && res.data.message) || 'Error');
                    if (btn) btn.disabled = false;
                }
            }).catch(function(err) {
                alert(err && err.message ? err.message : 'Network error. Check your connection.');
                if (btn) btn.disabled = false;
            });
        }

        var freeBtn = form.querySelector('.cp-reg-free');
        var stripeBtn = form.querySelector('.cp-pay-stripe');
        var paypalBtn = form.querySelector('.cp-pay-paypal');
        if (freeBtn) freeBtn.addEventListener('click', function() { submit('free'); });
        if (stripeBtn) stripeBtn.addEventListener('click', function() { submit('stripe'); });
        if (paypalBtn) paypalBtn.addEventListener('click', function() { submit('paypal'); });
    });
})();
