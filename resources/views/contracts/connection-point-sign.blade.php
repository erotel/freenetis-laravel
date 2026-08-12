<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title>Podpis dodatku – přípojné místo — PVfree.net</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="{{ asset('css/modern.css') }}">
<style>
body { background:#f0ede8; color:#222; padding:24px 12px; max-width:900px; margin:0 auto; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; }
h1 { font-size:24px; margin:0 0 8px; }
h2 { font-size:19px; margin:0 0 12px; }
.meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px 16px; font-size:17px; }
.meta-grid .lbl { color:#888; font-size:14px; }
iframe { width:100%; height:60vh; border:1px solid #e8e8e8; border-radius:8px; background:#fff; }
.row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:6px; }
.msg { margin-top:10px; padding:10px; border-radius:6px; display:none; font-size:16px; }
.msg.ok  { background:#eaf6ea; color:#1c6e2b; border:1px solid #c3e6c3; }
.msg.err { background:#fbeaea; color:#a02525; border:1px solid #f5c6c6; }
.muted { color:#888; font-size:14px; }
</style>
</head>
<body>

<h1>Podpis dodatku – přípojné místo</h1>

<div id="loading" class="m-card">Načítám údaje smlouvy…</div>

<div id="signedBox" class="m-card" style="display:none">
    <div class="m-card-title">Dodatek je již podepsaný</div>
    <p>Dodatek byl úspěšně podepsán. Níže si můžete prohlédnout finální PDF.</p>
    <iframe id="signedFrame" src=""></iframe>
</div>

<div id="metaBox" class="m-card" style="display:none">
    <div class="m-card-title">Údaje o smlouvě a přípojném místě</div>
    <div class="meta-grid">
        <div><div class="lbl">Smlouva č.</div><strong id="contract_no">—</strong></div>
        <div><div class="lbl">Jméno</div><strong id="full_name">—</strong></div>
        <div><div class="lbl">Telefon (mask.)</div><strong id="phone_mask">—</strong></div>
        <div><div class="lbl">Variabilní symbol</div><strong id="vs">—</strong></div>
        <div><div class="lbl">Adresa připojení</div><strong id="place_name">—</strong></div>
        <div><div class="lbl">Rychlost</div><strong id="place_speed">—</strong></div>
        <div><div class="lbl">Cena</div><strong id="place_price">—</strong></div>
        <div><div class="lbl">Účinnost od</div><strong id="effective_date">—</strong></div>
    </div>
    <p class="muted" style="margin-top:10px">
        Tento formulář slouží k elektronickému podpisu dodatku o přípojném místě.
        Pro potvrzení vám zašleme SMS kód na uvedené telefonní číslo. Číslo nelze v tomto formuláři měnit.
        V případě chyby kontaktujte podporu PVfree 588&nbsp;207&nbsp;234.
    </p>
</div>

<div id="previewBox" class="m-card" style="display:none">
    <div class="m-card-title">Náhled dodatku</div>
    <iframe id="previewFrame" src=""></iframe>
</div>

<div id="otpBox" class="m-card" style="display:none">
    <div class="m-card-title">Ověření SMS</div>
    <div class="row">
        <button class="m-btn m-btn-primary" id="btnSend" type="button">Odeslat SMS kód</button>
        <span class="muted" id="sendInfo"></span>
    </div>
    <label class="m-form-label" for="otp" style="margin-top:14px">Ověřovací kód (6 číslic z SMS)</label>
    <div class="row">
        <input class="m-form-input" type="text" id="otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="123456" style="max-width:160px">
        <button class="m-btn m-btn-primary" id="btnConfirm" type="button">Potvrdit dodatek</button>
    </div>
    <div id="otpMsg" class="msg"></div>
</div>

<script>
(function(){
    const TOKEN = @json($token);
    const URLS = {
        info:        @json(route('sign.connection_point.info')),
        preview:     @json(route('sign.connection_point.preview')),
        otpSend:     @json(route('sign.addon.otp.send')),
        otpVerify:   @json(route('sign.connection_point.otp.verify')),
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

    async function init() {
        const { ok, json } = await postForm(URLS.info, { t: TOKEN });
        if (!ok || json.error) {
            $('loading').textContent = 'Odkaz je neplatný nebo expirovaný.';
            return;
        }

        $('contract_no').textContent = json.contract_no || '—';
        $('full_name').textContent   = json.full_name || '—';
        $('phone_mask').textContent  = json.phone_mask || '—';
        $('vs').textContent          = json.variable_symbol || '—';
        $('place_name').textContent  = json.place_name || '—';
        $('place_speed').textContent = json.place_speed || '—';
        $('place_price').textContent = (json.place_price != null)
            ? (String(json.place_price).replace(/\.00$/, '') + ' Kč / měsíc') : '—';
        $('effective_date').textContent = json.effective_date || '—';

        show($('loading'), false);
        show($('metaBox'));

        if (json.signed) {
            $('signedFrame').src = URLS.preview + '?t=' + encodeURIComponent(TOKEN);
            show($('signedBox'));
            return;
        }

        $('previewFrame').src = URLS.preview + '?t=' + encodeURIComponent(TOKEN) + '&ts=' + Date.now();
        show($('previewBox'));
        show($('otpBox'));

        $('otp').addEventListener('input', () => {
            $('otp').value = $('otp').value.replace(/\D/g, '').slice(0, 6);
        });
        $('otp').addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') { ev.preventDefault(); $('btnConfirm').click(); }
        });

        $('btnSend').addEventListener('click', onSend);
        $('btnConfirm').addEventListener('click', onConfirm);
    }

    async function onSend() {
        clearMsg($('otpMsg'));
        $('btnSend').disabled = true;
        $('sendInfo').textContent = 'Odesílám SMS…';

        try {
            const { ok, json } = await postForm(URLS.otpSend, { t: TOKEN });
            if (!ok || json.error) {
                $('sendInfo').textContent = '';
                setMsg($('otpMsg'), json.error || ('HTTP ' + (json.status || '?')), false);
                return;
            }
            $('sendInfo').textContent = 'SMS odeslána na ' + (json.phone_mask || '(maskováno)') +
                                        ' (platnost ' + (json.ttl_min || 5) + ' min).';
            setMsg($('otpMsg'), 'SMS s kódem byla odeslána.', true);
        } finally {
            setTimeout(() => { $('btnSend').disabled = false; }, 15000);
        }
    }

    async function onConfirm() {
        clearMsg($('otpMsg'));
        const otp = $('otp').value.trim();
        if (!/^\d{6}$/.test(otp)) {
            setMsg($('otpMsg'), 'Zadejte 6místný kód z SMS.', false);
            return;
        }

        $('btnConfirm').disabled = true;
        try {
            const { ok, json } = await postForm(URLS.otpVerify, { t: TOKEN, otp });
            if (!ok || json.error) {
                setMsg($('otpMsg'), json.error || ('HTTP ' + (json.status || '?')), false);
                $('btnConfirm').disabled = false;
                return;
            }
            setMsg($('otpMsg'), 'Dodatek byl úspěšně podepsán.', true);
            $('btnConfirm').textContent = 'Dokončeno ✓';
            $('btnSend').disabled = true;

            if (json.download_url) {
                $('previewFrame').src = json.download_url;
            }
        } catch (e) {
            setMsg($('otpMsg'), 'Chyba komunikace se serverem.', false);
            $('btnConfirm').disabled = false;
        }
    }

    init();
})();
</script>

</body>
</html>
