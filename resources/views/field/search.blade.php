@extends('field.layout')

@section('title', 'Hledat')

@section('content')
<div style="position:sticky;top:0;z-index:10;padding-bottom:10px;margin-bottom:4px">
    <input type="search" id="f-q" class="f-input" placeholder="Jméno, VS, adresa, IP, telefon…"
           autocomplete="off" autocapitalize="none" autocorrect="off" autofocus
           enterkeyhint="search" inputmode="search">
</div>

<div id="f-status" class="f-empty">Začni psát pro vyhledávání.</div>
<div id="f-results"></div>

<div style="text-align:center;margin-top:28px">
    <a href="{{ route('webauthn.index') }}" style="color:var(--muted);font-size:14px">🔑 Biometrické přihlášení</a>
</div>

@php
    $statusLabels = ['ok' => 'V pořádku', 'overdue' => 'Po splatnosti', 'suspended' => 'Pozastaveno'];
@endphp
@endsection

@section('scripts')
<script>
(function () {
    var input   = document.getElementById('f-q');
    var results = document.getElementById('f-results');
    var statusEl= document.getElementById('f-status');
    var url     = '{{ route('field.search.results') }}';
    var memberBase = '{{ url('field/member') }}';
    var deviceBase = '{{ url('field/device') }}';
    var timer = null, lastSeq = 0;
    var STATUS = {ok:'V pořádku', overdue:'Po splatnosti', suspended:'Pozastaveno'};

    function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

    function fmtMoney(v){
        if (v === null || v === undefined) return '';
        return new Intl.NumberFormat('cs-CZ').format(Math.round(v)) + ' Kč';
    }

    function render(items){
        if (!items.length){
            results.innerHTML = '';
            statusEl.textContent = 'Nic nenalezeno.';
            statusEl.style.display = 'block';
            return;
        }
        statusEl.style.display = 'none';
        var html = '<div class="f-card" style="padding:6px 12px">';
        items.forEach(function(m){
            if (m.kind === 'device') {
                html += '<a class="f-row" href="' + deviceBase + '/' + m.id + '">'
                      + '<span style="width:12px;height:12px;border-radius:3px;background:var(--accent);flex-shrink:0" title="Zařízení"></span>'
                      + '<span class="f-row-main">'
                      +   '<div class="f-row-title">' + esc(m.name) + '</div>'
                      +   '<div class="f-row-sub">Zařízení' + (m.detail ? ' · ' + esc(m.detail) : '') + '</div>'
                      + '</span>'
                      + '<span class="f-chevron">›</span>'
                      + '</a>';
                return;
            }
            var sub = [];
            if (m.vs) sub.push('VS ' + esc(m.vs));
            if (m.address) sub.push(esc(m.address));
            var bal = (m.balance === null || m.balance === undefined) ? ''
                : '<div class="f-row-sub" style="' + (m.balance < 0 ? 'color:var(--red)' : 'color:var(--green)') + '">' + esc(fmtMoney(m.balance)) + '</div>';
            html += '<a class="f-row" href="' + memberBase + '/' + m.id + '">'
                  + '<span class="f-dot ' + esc(m.status) + '" title="' + esc(STATUS[m.status] || '') + '"></span>'
                  + '<span class="f-row-main">'
                  +   '<div class="f-row-title">' + esc(m.name) + '</div>'
                  +   '<div class="f-row-sub">' + esc(m.type_label) + (sub.length ? ' · ' + sub.join(' · ') : '') + '</div>'
                  + '</span>'
                  + (bal ? '<span style="text-align:right">' + bal + '</span>' : '')
                  + '<span class="f-chevron">›</span>'
                  + '</a>';
        });
        html += '</div>';
        results.innerHTML = html;
    }

    function search(q){
        var seq = ++lastSeq;
        fetch(url + '?q=' + encodeURIComponent(q), {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(function(r){ return r.json(); })
            .then(function(data){ if (seq === lastSeq) render(data); })
            .catch(function(){ if (seq === lastSeq){ statusEl.textContent = 'Chyba spojení.'; statusEl.style.display='block'; } });
    }

    input.addEventListener('input', function(){
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2){
            results.innerHTML = '';
            statusEl.textContent = 'Začni psát pro vyhledávání.';
            statusEl.style.display = 'block';
            lastSeq++;
            return;
        }
        statusEl.textContent = 'Hledám…';
        statusEl.style.display = 'block';
        timer = setTimeout(function(){ search(q); }, 300);
    });
})();
</script>
@endsection
