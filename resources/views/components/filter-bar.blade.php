@props(['fields' => [], 'action' => '', 'current' => []])

@php
// Build JS-friendly field definitions
$fieldDefs = [];
foreach ($fields as $f) {
    $def = ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type']];
    if (isset($f['values'])) {
        $def['values'] = array_map(
            fn($label, $val) => ['v' => (string)$val, 'l' => $label],
            $f['values'], array_keys($f['values'])
        );
    }
    $fieldDefs[] = $def;
}

$opsByType = [
    'text'   => [['v'=>'contains','l'=>'obsahuje'],['v'=>'not_contains','l'=>'neobsahuje'],
                 ['v'=>'eq','l'=>'je přesně'],['v'=>'neq','l'=>'není'],
                 ['v'=>'empty','l'=>'je prázdný'],['v'=>'not_empty','l'=>'není prázdný']],
    'number' => [['v'=>'eq','l'=>'='],['v'=>'neq','l'=>'≠'],['v'=>'lt','l'=>'<'],
                 ['v'=>'lte','l'=>'≤'],['v'=>'gt','l'=>'>'],['v'=>'gte','l'=>'≥']],
    'date'   => [['v'=>'eq','l'=>'='],['v'=>'neq','l'=>'≠'],['v'=>'lt','l'=>'<'],
                 ['v'=>'lte','l'=>'≤'],['v'=>'gt','l'=>'>'],['v'=>'gte','l'=>'≥']],
    'select' => [['v'=>'eq','l'=>'je'],['v'=>'neq','l'=>'není']],
];

$rows = array_values(array_filter((array)$current, fn($r) => !empty($r['field'])));
if (empty($rows)) $rows = [['field'=>'','op'=>'eq','value'=>'','active'=>'1']];
@endphp

<form method="GET" action="{{ $action }}" class="fn-filter-bar">
{{-- Zachovat sort/dir/record_per_page z aktuálního requestu --}}
@foreach(request()->except(['filters','page']) as $k => $v)
    @if(is_array($v))
        @foreach($v as $vk => $vv)
        <input type="hidden" name="{{ $k }}[{{ $vk }}]" value="{{ $vv }}">
        @endforeach
    @else
    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endif
@endforeach

<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
    <strong style="font-size:16px">Pokročilé filtry</strong>
    <button type="button" class="m-btn" style="font-size:14px;padding:3px 10px" id="fnf-add">+ Přidat filtr</button>
    <a href="{{ $action }}" class="m-btn" style="font-size:14px;padding:3px 10px">Vynulovat</a>
</div>

<div id="fnf-rows">
@foreach($rows as $i => $row)
<div class="fnf-row" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px" data-index="{{ $i }}">
    <input type="checkbox" name="filters[{{ $i }}][active]" value="1"
           {{ !empty($row['active']) ? 'checked' : '' }}
           title="Aktivní" style="cursor:pointer">
    <select name="filters[{{ $i }}][field]" class="m-form-select fnf-field" style="width:160px;font-size:14px">
        <option value="">— pole —</option>
        @foreach($fields as $f)
        <option value="{{ $f['key'] }}" data-type="{{ $f['type'] }}"
                @if(!empty($f['values'])) data-values="{{ json_encode(array_map(fn($l,$v)=>['v'=>(string)$v,'l'=>$l],$f['values'],array_keys($f['values']))) }}" @endif
                {{ ($row['field'] ?? '') === $f['key'] ? 'selected' : '' }}>
            {{ $f['label'] }}
        </option>
        @endforeach
    </select>
    <select name="filters[{{ $i }}][op]" class="m-form-select fnf-op" style="width:130px;font-size:14px">
        @php
            $ftype = collect($fields)->firstWhere('key', $row['field'] ?? '')['type'] ?? 'text';
            $ops   = $opsByType[$ftype] ?? $opsByType['text'];
        @endphp
        @foreach($ops as $o)
        <option value="{{ $o['v'] }}" {{ ($row['op'] ?? 'eq') === $o['v'] ? 'selected' : '' }}>{{ $o['l'] }}</option>
        @endforeach
    </select>
    @php
        $ftype2 = collect($fields)->firstWhere('key', $row['field'] ?? '')['type'] ?? 'text';
        $fvals  = collect($fields)->firstWhere('key', $row['field'] ?? '')['values'] ?? null;
    @endphp
    @if($ftype2 === 'select' && $fvals)
    <select name="filters[{{ $i }}][value]" class="m-form-select fnf-value" style="width:180px;font-size:14px">
        @foreach($fvals as $v => $l)
        <option value="{{ $v }}" {{ ($row['value'] ?? '') === (string)$v ? 'selected' : '' }}>{{ $l }}</option>
        @endforeach
    </select>
    @elseif($ftype2 === 'date')
    <input type="date" name="filters[{{ $i }}][value]" class="m-form-input fnf-value"
           value="{{ $row['value'] ?? '' }}" style="width:150px;font-size:14px">
    @elseif($ftype2 === 'number')
    <input type="number" name="filters[{{ $i }}][value]" class="m-form-input fnf-value"
           value="{{ $row['value'] ?? '' }}" step="any" style="width:120px;font-size:14px">
    @else
    <input type="text" name="filters[{{ $i }}][value]" class="m-form-input fnf-value"
           value="{{ $row['value'] ?? '' }}" style="width:180px;font-size:14px">
    @endif
    <button type="button" class="fnf-remove" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:19px;line-height:1;padding:0 4px" title="Odebrat">×</button>
</div>
@endforeach
</div>

@php $pageKey = trim(parse_url($action, PHP_URL_PATH), '/'); @endphp
<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
    <button type="submit" class="m-btn m-btn-primary" style="font-size:14px;padding:4px 14px">Filtrovat</button>

    {{-- Uložit filtr --}}
    <div style="display:flex;gap:4px;align-items:center" id="fnf-save-wrap">
        <input type="text" id="fnf-save-name" class="m-form-input"
               placeholder="Název filtru..." style="width:150px;font-size:14px;padding:3px 8px">
        <button type="button" class="m-btn" style="font-size:14px;padding:4px 10px"
                id="fnf-save-btn">Uložit</button>
    </div>

    {{-- Uložené filtry --}}
    <div style="position:relative" id="fnf-saved-wrap">
        <button type="button" class="m-btn" style="font-size:14px;padding:4px 10px"
                id="fnf-saved-btn">Uložené filtry ▾</button>
        <div id="fnf-saved-dropdown" style="display:none;position:absolute;top:100%;left:0;z-index:500;
             background:var(--fn-card-bg);border:1px solid var(--fn-border);border-radius:6px;
             box-shadow:0 4px 12px rgba(0,0,0,.15);min-width:220px;margin-top:2px">
            <div id="fnf-saved-list" style="padding:4px 0;max-height:240px;overflow-y:auto;font-size:16px">
                <div style="padding:8px 12px;color:var(--fn-text-muted)">Načítám…</div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
(function(){
var FIELDS     = @json($fieldDefs);
var OPS        = @json($opsByType);
var FNF_PAGE   = @json($pageKey);
var FNF_ACTION = @json($action);
var CSRF = document.querySelector('meta[name=csrf-token]')?.content||'';

function fieldByKey(key){ return FIELDS.find(function(f){return f.key===key;})||null; }

function buildOps(type, currentOp){
    var ops = OPS[type]||OPS['text'];
    return ops.map(function(o){
        return '<option value="'+o.v+'"'+(o.v===currentOp?' selected':'')+'>'+o.l+'</option>';
    }).join('');
}

function buildValue(field, currentVal, name){
    if(!field) return '<input type="text" name="'+name+'" class="m-form-input fnf-value" value="'+escH(currentVal)+'" style="width:180px;font-size:14px">';
    if(field.type==='select'&&field.values){
        var opts=field.values.map(function(o){
            return '<option value="'+escH(o.v)+'"'+(o.v===currentVal?' selected':'')+'>'+escH(o.l)+'</option>';
        }).join('');
        return '<select name="'+name+'" class="m-form-select fnf-value" style="width:180px;font-size:14px">'+opts+'</select>';
    }
    if(field.type==='date')
        return '<input type="date" name="'+name+'" class="m-form-input fnf-value" value="'+escH(currentVal)+'" style="width:150px;font-size:14px">';
    if(field.type==='number')
        return '<input type="number" name="'+name+'" class="m-form-input fnf-value" value="'+escH(currentVal)+'" step="any" style="width:120px;font-size:14px">';
    return '<input type="text" name="'+name+'" class="m-form-input fnf-value" value="'+escH(currentVal)+'" style="width:180px;font-size:14px">';
}

function escH(s){ return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

function nextIndex(){
    var rows=document.querySelectorAll('#fnf-rows .fnf-row');
    return rows.length;
}

function rebindRow(row){
    var fieldSel = row.querySelector('.fnf-field');
    var opSel    = row.querySelector('.fnf-op');

    fieldSel.addEventListener('change', function(){
        var idx   = row.dataset.index;
        var key   = this.value;
        var field = fieldByKey(key);
        var type  = field ? field.type : 'text';

        opSel.innerHTML = buildOps(type, '');

        var valEl = row.querySelector('.fnf-value');
        var newVal = buildValue(field, '', 'filters['+idx+'][value]');
        valEl.outerHTML = newVal;
    });

    row.querySelector('.fnf-remove').addEventListener('click', function(){
        row.remove();
        renumberRows();
    });
}

function renumberRows(){
    document.querySelectorAll('#fnf-rows .fnf-row').forEach(function(row, i){
        row.dataset.index = i;
        row.querySelectorAll('[name]').forEach(function(el){
            el.name = el.name.replace(/filters\[\d+\]/, 'filters['+i+']');
        });
    });
}

// bind existing rows
document.querySelectorAll('#fnf-rows .fnf-row').forEach(function(row){ rebindRow(row); });

// add filter button
document.getElementById('fnf-add').addEventListener('click', function(){
    var idx = nextIndex();
    var fieldOpts = '<option value="">— pole —</option>'+FIELDS.map(function(f){
        var attrs = 'data-type="'+f.type+'"';
        if(f.values) attrs += ' data-values=\''+JSON.stringify(f.values)+'\'';
        return '<option value="'+f.key+'" '+attrs+'>'+escH(f.label)+'</option>';
    }).join('');

    var html = '<div class="fnf-row" data-index="'+idx+'" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px">'
        +'<input type="checkbox" name="filters['+idx+'][active]" value="1" checked title="Aktivní" style="cursor:pointer">'
        +'<select name="filters['+idx+'][field]" class="m-form-select fnf-field" style="width:160px;font-size:14px">'+fieldOpts+'</select>'
        +'<select name="filters['+idx+'][op]" class="m-form-select fnf-op" style="width:130px;font-size:14px">'+buildOps('text','contains')+'</select>'
        +'<input type="text" name="filters['+idx+'][value]" class="m-form-input fnf-value" style="width:180px;font-size:14px">'
        +'<button type="button" class="fnf-remove" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:19px;line-height:1;padding:0 4px" title="Odebrat">×</button>'
        +'</div>';

    var container = document.getElementById('fnf-rows');
    container.insertAdjacentHTML('beforeend', html);
    rebindRow(container.lastElementChild);
});

// ── Uložit filtr ─────────────────────────────────────────────────────────────
document.getElementById('fnf-save-btn').addEventListener('click', function(){
    var name = document.getElementById('fnf-save-name').value.trim();
    if (!name) { alert('Zadejte název filtru.'); return; }
    var filters = [];
    document.querySelectorAll('#fnf-rows .fnf-row').forEach(function(row){
        var active = row.querySelector('[name*="[active]"]');
        var field  = row.querySelector('.fnf-field');
        var op     = row.querySelector('.fnf-op');
        var val    = row.querySelector('.fnf-value');
        if (field && field.value) {
            filters.push({
                active: active && active.checked ? '1' : '',
                field: field.value, op: op ? op.value : 'contains', value: val ? val.value : ''
            });
        }
    });
    fetch('{{ route("saved-filters.store") }}', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-CSRF-TOKEN': CSRF},
        body: JSON.stringify({name: name, page: FNF_PAGE, filters: filters})
    }).then(function(r){ return r.json(); }).then(function(data){
        document.getElementById('fnf-save-name').value = '';
        appendSavedItem(data.id, data.name, data.filters || filters);
        var msg = document.createElement('div');
        msg.textContent = 'Filtr "'+name+'" byl uložen.';
        msg.style.cssText = 'background:#1a4a1a;color:#7dbb7d;padding:6px 12px;border-radius:6px;font-size:14px;margin-top:6px;';
        document.getElementById('fnf-save-wrap').appendChild(msg);
        setTimeout(function(){ msg.remove(); }, 3000);
    });
});

// ── Uložené filtry dropdown ───────────────────────────────────────────────────
var savedLoaded = false;
var savedBtn  = document.getElementById('fnf-saved-btn');
var savedDrop = document.getElementById('fnf-saved-dropdown');
var savedList = document.getElementById('fnf-saved-list');
if (savedBtn) {
    savedBtn.addEventListener('click', function(e){
        e.stopPropagation();
        var open = savedDrop.style.display !== 'none';
        savedDrop.style.display = open ? 'none' : 'block';
        if (!open && !savedLoaded) loadSaved();
    });
    document.addEventListener('click', function(){ savedDrop.style.display='none'; });
    savedDrop.addEventListener('click', function(e){ e.stopPropagation(); });
}
function loadSaved(){
    savedLoaded = true;
    fetch('{{ route("saved-filters.index") }}?page='+encodeURIComponent(FNF_PAGE))
    .then(function(r){ return r.json(); }).then(function(items){
        savedList.innerHTML = '';
        if (!items.length){
            savedList.innerHTML='<div style="padding:8px 12px;color:#aaa;font-size:14px">Žádné uložené filtry</div>';
            return;
        }
        items.forEach(function(item){ appendSavedItem(item.id, item.name, item.filters); });
    });
}
function appendSavedItem(id, name, filters){
    if (savedList.querySelector('[data-sfid="'+id+'"]')) return;
    var row = document.createElement('div');
    row.setAttribute('data-sfid', id);
    row.style.cssText='display:flex;align-items:center;padding:4px 8px 4px 12px;gap:4px';
    row.innerHTML='<span style="flex:1;font-size:16px;cursor:pointer" class="sf-label">'+escH(name)+'</span>'
        +'<button type="button" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:17px;padding:0 2px" title="Smazat">×</button>';
    row.querySelector('.sf-label').addEventListener('click', function(){
        applySavedFilter(filters); savedDrop.style.display='none';
    });
    row.querySelector('button').addEventListener('click', function(e){
        e.stopPropagation(); deleteSaved(id, row);
    });
    savedList.appendChild(row);
}
function applySavedFilter(filters){
    document.querySelectorAll('#fnf-rows .fnf-row').forEach(function(r){ r.remove(); });
    filters.forEach(function(f){
        document.getElementById('fnf-add').click();
        var rows = document.querySelectorAll('#fnf-rows .fnf-row');
        var row  = rows[rows.length-1];
        var fieldSel = row.querySelector('.fnf-field');
        if (fieldSel){ fieldSel.value=f.field; fieldSel.dispatchEvent(new Event('change')); }
        setTimeout(function(){
            var opSel  = row.querySelector('.fnf-op');
            var valEl  = row.querySelector('.fnf-value');
            var active = row.querySelector('[name*="[active]"]');
            if (opSel)  opSel.value  = f.op||'contains';
            if (valEl)  valEl.value  = f.value||'';
            if (active) active.checked = f.active==='1'||f.active===1||f.active===true;
        }, 10);
    });
    setTimeout(function(){
        var form = document.querySelector('.fn-filter-bar'); if(form) form.submit();
    }, 60);
}
function deleteSaved(id, rowEl){
    fetch('{{ url("saved-filters") }}/'+id, {
        method:'DELETE', headers:{'X-CSRF-TOKEN':CSRF,'Content-Type':'application/json'}
    }).then(function(r){ return r.json(); }).then(function(){
        rowEl.remove();
        var msg = document.createElement('div');
        msg.textContent = 'Filtr byl smazán.';
        msg.style.cssText = 'background:#4a1a1a;color:#bb7d7d;padding:6px 12px;border-radius:6px;font-size:14px;margin-top:6px;';
        document.getElementById('fnf-save-wrap').appendChild(msg);
        setTimeout(function(){ msg.remove(); }, 3000);
    });
}

})();
</script>
