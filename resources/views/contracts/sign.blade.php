<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title>Podpis smlouvy — PVfree.net</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="{{ asset('css/modern.css') }}">
<style>
body { background:#f0ede8; color:#222; padding:24px 12px; max-width:900px; margin:0 auto; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
h1 { font-size:24px; margin:0 0 8px; }
h2 { font-size:19px; margin:0 0 12px; }
.meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px 16px; font-size:17px; }
.meta-grid .lbl { color:#888; font-size:14px; }
.agree { display:flex; align-items:flex-start; gap:10px; margin-top:8px; font-size:17px; }
.agree input { margin-top:3px; }
.agree a { color:#185FA5; }
iframe { width:100%; height:60vh; border:1px solid #e8e8e8; border-radius:8px; background:#fff; }
.row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:6px; }
.msg { margin-top:10px; padding:10px; border-radius:6px; display:none; font-size:16px; }
.msg.ok  { background:#eaf6ea; color:#1c6e2b; border:1px solid #c3e6c3; }
.msg.err { background:#fbeaea; color:#a02525; border:1px solid #f5c6c6; }
ul.dl { padding-left:20px; margin-top:8px; }
.muted { color:#888; font-size:14px; }
</style>
</head>
<body>

<h1>Podpis smlouvy</h1>

<div id="loading" class="m-card">Načítám smlouvu…</div>

<div id="signedBox" class="m-card" style="display:none">
    <div class="m-card-title">Smlouva je již podepsaná</div>
    <p>Vaše smlouva byla již úspěšně podepsána. Níže si můžete prohlédnout finální PDF.</p>
    <iframe id="signedFrame" src=""></iframe>
</div>

<div id="metaBox" class="m-card" style="display:none">
    <div class="m-card-title">Údaje o smlouvě</div>
    <div class="meta-grid">
        <div><div class="lbl">Smlouva č.</div><strong id="contract_no">—</strong></div>
        <div><div class="lbl">Člen (ID)</div><strong id="member_id">—</strong></div>
        <div><div class="lbl">Jméno</div><strong id="full_name">—</strong></div>
        <div><div class="lbl">Telefon (mask.)</div><strong id="phone_mask">—</strong></div>
        <div><div class="lbl">Variabilní symbol</div><strong id="vs">—</strong></div>
        <div><div class="lbl">Stav</div><strong id="status">—</strong></div>
    </div>
</div>

<div id="previewBox" class="m-card" style="display:none">
    <div class="m-card-title">Náhled smlouvy</div>
    <iframe id="previewFrame" src=""></iframe>
</div>

<div id="agreementsBox" class="m-card" style="display:none">
    <div class="m-card-title">Souhlasy</div>
    <div class="agree">
        <input type="checkbox" id="agreeData">
        <label for="agreeData">Potvrzuji správnost uvedených údajů. Pokud některý údaj nesouhlasí, volejte 588&nbsp;207&nbsp;234.</label>
    </div>
    <div class="agree">
        <input type="checkbox" id="agreeVop">
        <label for="agreeVop">Souhlasím s <a href="https://smlouvy.pvfree.net/VOP-pvfree.pdf" target="_blank" rel="noopener">Všeobecnými obchodními podmínkami</a>.</label>
    </div>
    <div class="agree">
        <input type="checkbox" id="agreeGdpr">
        <label for="agreeGdpr">Souhlasím se zpracováním osobních údajů pro účel smlouvy (GDPR).</label>
    </div>
    <div class="agree">
        <input type="checkbox" id="agreePrice">
        <label for="agreePrice">Seznámil/a jsem se s <a href="https://drive.google.com/file/d/1kGVDs9Z1xpp3dbg63QT2XvqK0MVhgkom/view" target="_blank" rel="noopener">Ceníkem</a>.</label>
    </div>
    <div class="agree">
        <input type="checkbox" id="agreeSummary">
        <label for="agreeSummary">Potvrzuji, že jsem si prohlédl/a shrnutí smlouvy a souhlasím s ním.</label>
    </div>
    <div id="agreeMsg" class="msg err">Pro pokračování zaškrtněte všechny souhlasy.</div>
</div>

<div id="otpBox" class="m-card" style="display:none">
    <div class="m-card-title">Ověření SMS</div>
    <p class="muted">Zadejte 9 číslic mobilu (bez +420). Na něj přijde 6místný kód.</p>
    <label class="m-form-label" for="phone">Telefon</label>
    <div class="row">
        <input class="m-form-input" type="text" id="phone" inputmode="numeric" maxlength="9" placeholder="777123456" style="max-width:200px">
        <button class="m-btn m-btn-primary" id="btnSend" type="button">Poslat SMS kód</button>
    </div>
    <label class="m-form-label" for="otp" style="margin-top:14px">Ověřovací kód (6 číslic)</label>
    <div class="row">
        <input class="m-form-input" type="text" id="otp" inputmode="numeric" maxlength="6" placeholder="123456" style="max-width:160px">
        <button class="m-btn" id="btnVerify" type="button" disabled>Ověřit kód</button>
    </div>
    <div id="otpMsg" class="msg"></div>
</div>

<div id="finalBox" class="m-card" style="display:none">
    <div class="m-card-title">Dokončení podpisu</div>
    <p>Po stisku tlačítka se vygeneruje finální PDF smlouvy s digitálním podpisem.</p>
    <button class="m-btn m-btn-primary" id="btnFinalize" type="button">Dokončit a podepsat</button>
    <div id="finalMsg" class="msg"></div>
</div>

<script>
(function(){
    const TOKEN = @json($token);
    const URLS = {
        info:     @json(route('sign.info')),
        preview:  @json(route('sign.preview')),
        otpSend:  @json(route('sign.otp.send')),
        otpVerify:@json(route('sign.otp.verify')),
        finalize: @json(route('sign.finalize')),
    };

    if (!TOKEN) {
        document.getElementById('loading').textContent = 'Chybí podpisový token (parametr ?t=).';
        return;
    }

    const $ = id => document.getElementById(id);
    function show(el, on=true) { el.style.display = on ? '' : 'none'; }
    function setMsg(el, text, ok) { el.textContent = text; el.className = 'msg ' + (ok ? 'ok' : 'err'); el.style.display = 'block'; }
    function clearMsg(el) { el.textContent = ''; el.style.display = 'none'; }

    async function postForm(url, data) {
        const body = new URLSearchParams(data);
        const r = await fetch(url, {
            method: 'POST',
            body,
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        let j = {};
        try { j = await r.json(); } catch (_) {}
        return { ok: r.ok, status: r.status, json: j };
    }

    function allAgreed() {
        return ['agreeData','agreeVop','agreeGdpr','agreePrice','agreeSummary']
            .every(id => $(id).checked);
    }

    async function init() {
        const { ok, json } = await postForm(URLS.info, { t: TOKEN });
        if (!ok || json.error) {
            $('loading').textContent = 'Odkaz je neplatný nebo expirovaný.';
            return;
        }

        $('contract_no').textContent = json.contract_no || '—';
        $('member_id').textContent   = json.member_id ?? '—';
        $('full_name').textContent   = json.full_name || '—';
        $('phone_mask').textContent  = json.phone_mask || '—';
        $('vs').textContent          = json.variable_symbol || '—';
        $('status').textContent      = json.status || '—';

        show($('loading'), false);

        if (json.status === 'signed' && json.has_pdf) {
            // already signed — show PDF preview
            $('signedFrame').src = URLS.preview + '?t=' + encodeURIComponent(TOKEN);
            show($('signedBox'));
            show($('metaBox'));
            return;
        }

        // active flow
        $('previewFrame').src = URLS.preview + '?t=' + encodeURIComponent(TOKEN) + '&ts=' + Date.now();
        show($('metaBox'));
        show($('previewBox'));
        show($('agreementsBox'));
        show($('otpBox'));

        // input filters
        $('phone').addEventListener('input', () => {
            $('phone').value = $('phone').value.replace(/\D/g, '').slice(0, 9);
        });
        $('otp').addEventListener('input', () => {
            $('otp').value = $('otp').value.replace(/\D/g, '').slice(0, 6);
        });

        $('btnSend').addEventListener('click', onSend);
        $('btnVerify').addEventListener('click', onVerify);
        $('btnFinalize').addEventListener('click', onFinalize);

        const wrap = $('agreementsBox');
        wrap.addEventListener('change', () => {
            if (allAgreed()) clearMsg($('agreeMsg'));
        });
    }

    async function onSend() {
        clearMsg($('otpMsg'));
        if (!allAgreed()) { setMsg($('agreeMsg'), 'Zaškrtněte všechny souhlasy.', false); return; }
        clearMsg($('agreeMsg'));

        const phone9 = $('phone').value.trim();
        if (!/^\d{9}$/.test(phone9)) {
            setMsg($('otpMsg'), 'Zadejte 9 číslic mobilu (bez +420).', false);
            return;
        }
        const phone = '420' + phone9;

        $('btnSend').disabled = true;
        try {
            const { ok, json } = await postForm(URLS.otpSend, { t: TOKEN, phone });
            if (!ok || json.error) {
                setMsg($('otpMsg'), json.error || ('HTTP ' + (json.status || '?')), false);
                return;
            }
            setMsg($('otpMsg'), 'Kód odeslán na +' + phone + ' (platnost ' + (json.ttl_min || 5) + ' min).', true);
            $('btnVerify').disabled = false;
        } finally {
            $('btnSend').disabled = false;
        }
    }

    async function onVerify() {
        clearMsg($('otpMsg'));
        if (!allAgreed()) { setMsg($('agreeMsg'), 'Zaškrtněte všechny souhlasy.', false); return; }

        const otp = $('otp').value.trim();
        if (!/^\d{6}$/.test(otp)) {
            setMsg($('otpMsg'), 'Zadejte 6místný kód.', false);
            return;
        }

        $('btnVerify').disabled = true;
        try {
            const { ok, json } = await postForm(URLS.otpVerify, { t: TOKEN, otp });
            if (!ok || json.error) {
                setMsg($('otpMsg'), json.error || ('HTTP ' + (json.status || '?')), false);
                $('btnVerify').disabled = false;
                return;
            }
            setMsg($('otpMsg'), 'Kód ověřen úspěšně.', true);
            show($('finalBox'));
            $('btnFinalize').focus();
        } catch (e) {
            $('btnVerify').disabled = false;
        }
    }

    async function onFinalize() {
        clearMsg($('finalMsg'));
        if (!allAgreed()) { setMsg($('agreeMsg'), 'Zaškrtněte všechny souhlasy.', false); return; }

        $('btnFinalize').disabled = true;
        try {
            const { ok, json } = await postForm(URLS.finalize, { t: TOKEN });
            if (!ok || json.error) {
                setMsg($('finalMsg'), json.error || ('HTTP ' + (json.status || '?')), false);
                $('btnFinalize').disabled = false;
                return;
            }
            setMsg($('finalMsg'), 'Podepsaná smlouva a další dokumenty Vám byly poslány na email.', true);
            $('btnFinalize').textContent = 'Dokončeno ✓';
        } catch (e) {
            setMsg($('finalMsg'), 'Chyba: ' + (e.message || e), false);
            $('btnFinalize').disabled = false;
        }
    }

    init();
})();
</script>

</body>
</html>
