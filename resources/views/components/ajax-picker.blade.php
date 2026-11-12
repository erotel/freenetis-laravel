@props([
    'name',               // jméno skrytého pole s ID (např. iface_id)
    'label' => '',
    'searchUrl',          // endpoint vracející JSON [{id,label,detail}]
    'value' => null,      // aktuální ID (nebo null)
    'text' => null,       // aktuální popisek do vstupu (nebo null)
    'placeholder' => 'Začněte psát…',
    'required' => false,
    'error' => null,      // jméno pole pro @error (default = name)
])
@php $uid = 'pk' . \Illuminate\Support\Str::random(8); $errKey = $error ?? $name; @endphp
<div class="m-form-group" id="{{ $uid }}" style="position:relative">
    @if($label !== '')
        <label class="m-form-label" for="{{ $uid }}_search">{{ $label }}@if($required) <span style="color:#c0392b">*</span>@endif</label>
    @endif
    <input type="hidden" name="{{ $name }}" id="{{ $uid }}_id" value="{{ $value }}">
    <div style="display:flex;gap:6px;align-items:stretch">
        <input class="m-form-input" type="text" id="{{ $uid }}_search" autocomplete="off"
               value="{{ $text }}" placeholder="{{ $placeholder }}" style="flex:1" @if($required && !$value) required @endif>
        <button type="button" class="m-btn" id="{{ $uid }}_clear" title="Vymazat" tabindex="-1"
                style="padding:0 12px"@if(!$value) hidden @endif>×</button>
    </div>
    <div id="{{ $uid }}_results" role="listbox" hidden
         style="position:absolute;z-index:40;left:0;right:0;background:var(--fn-card,#fff);border:1px solid var(--fn-border,#ddd);border-radius:6px;margin-top:2px;max-height:280px;overflow:auto;box-shadow:0 6px 18px rgba(0,0,0,.12)"></div>
    @error($errKey) <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
</div>
<script>
(function () {
    var root   = document.getElementById(@json($uid));
    if (!root) return;
    var hidden = document.getElementById(@json($uid . '_id'));
    var input  = document.getElementById(@json($uid . '_search'));
    var clear  = document.getElementById(@json($uid . '_clear'));
    var box    = document.getElementById(@json($uid . '_results'));
    var url    = @json($searchUrl);
    var timer = null, seq = 0;

    function hide() { box.hidden = true; box.innerHTML = ''; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function pick(id, label) {
        hidden.value = id;
        input.value = label;
        input.removeAttribute('required');
        clear.hidden = false;
        hide();
    }

    function render(items) {
        if (!items.length) { box.innerHTML = '<div style="padding:8px 12px;color:#888;font-size:14px">Nic nenalezeno</div>'; box.hidden = false; return; }
        box.innerHTML = '';
        items.forEach(function (it) {
            var row = document.createElement('div');
            row.setAttribute('role', 'option');
            row.style.cssText = 'padding:7px 12px;cursor:pointer;border-bottom:1px solid var(--fn-border,#eee)';
            row.innerHTML = '<div>' + esc(it.label) + '</div>' +
                (it.detail ? '<div style="font-size:12px;color:#888">' + esc(it.detail) + '</div>' : '');
            row.addEventListener('mouseenter', function () { row.style.background = 'var(--fn-hover,#f3f1ee)'; });
            row.addEventListener('mouseleave', function () { row.style.background = ''; });
            // mousedown (ne click) — proběhne dřív než blur vstupu
            row.addEventListener('mousedown', function (e) { e.preventDefault(); pick(it.id, it.label); });
            box.appendChild(row);
        });
        box.hidden = false;
    }

    function search() {
        var q = input.value.trim();
        if (q.length < 2) { hide(); return; }
        var my = ++seq;
        fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (data) { if (my === seq) render(Array.isArray(data) ? data : []); })
            .catch(function () { if (my === seq) hide(); });
    }

    input.addEventListener('input', function () {
        hidden.value = '';                 // ruční editace zruší dřívější výběr
        clear.hidden = input.value === '';
        clearTimeout(timer); timer = setTimeout(search, 200);
    });
    input.addEventListener('focus', function () { if (input.value.trim().length >= 2) search(); });
    clear.addEventListener('click', function () { hidden.value = ''; input.value = ''; clear.hidden = true; hide(); input.focus(); });
    document.addEventListener('click', function (e) { if (!root.contains(e.target)) hide(); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });
})();
</script>
