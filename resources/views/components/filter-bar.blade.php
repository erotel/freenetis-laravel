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
    <strong style="font-size:13px">Pokročilé filtry</strong>
    <button type="button" class="m-btn" style="font-size:12px;padding:3px 10px" id="fnf-add">+ Přidat filtr</button>
    <a href="{{ $action }}" class="m-btn" style="font-size:12px;padding:3px 10px">Vynulovat</a>
</div>

<div id="fnf-rows">
@foreach($rows as $i => $row)
<div class="fnf-row" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px" data-index="{{ $i }}">
    <input type="checkbox" name="filters[{{ $i }}][active]" value="1"
           {{ !empty($row['active']) ? 'checked' : '' }}
           title="Aktivní" style="cursor:pointer">
    <select name="filters[{{ $i }}][field]" class="m-form-select fnf-field" style="width:160px;font-size:12px">
        <option value="">— pole —</option>
        @foreach($fields as $f)
        <option value="{{ $f['key'] }}" data-type="{{ $f['type'] }}"
                @if(!empty($f['values'])) data-values="{{ json_encode(array_map(fn($l,$v)=>['v'=>(string)$v,'l'=>$l],$f['values'],array_keys($f['values']))) }}" @endif
                {{ ($row['field'] ?? '') === $f['key'] ? 'selected' : '' }}>
            {{ $f['label'] }}
        </option>
        @endforeach
    </select>
    <select name="filters[{{ $i }}][op]" class="m-form-select fnf-op" style="width:130px;font-size:12px">
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
    <select name="filters[{{ $i }}][value]" class="m-form-select fnf-value" style="width:180px;font-size:12px">
        @foreach($fvals as $v => $l)
        <option value="{{ $v }}" {{ ($row['value'] ?? '') === (string)$v ? 'selected' : '' }}>{{ $l }}</option>
        @endforeach
    </select>
    @elseif($ftype2 === 'date')
    <input type="date" name="filters[{{ $i }}][value]" class="m-form-input fnf-value"
           value="{{ $row['value'] ?? '' }}" style="width:150px;font-size:12px">
    @elseif($ftype2 === 'number')
    <input type="number" name="filters[{{ $i }}][value]" class="m-form-input fnf-value"
           value="{{ $row['value'] ?? '' }}" step="any" style="width:120px;font-size:12px">
    @else
    <input type="text" name="filters[{{ $i }}][value]" class="m-form-input fnf-value"
           value="{{ $row['value'] ?? '' }}" style="width:180px;font-size:12px">
    @endif
    <button type="button" class="fnf-remove" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:16px;line-height:1;padding:0 4px" title="Odebrat">×</button>
</div>
@endforeach
</div>

<div style="margin-top:8px">
    <button type="submit" class="m-btn m-btn-primary" style="font-size:12px;padding:4px 14px">Filtrovat</button>
</div>
</form>

<script>
(function(){
var FIELDS = @json($fieldDefs);
var OPS    = @json($opsByType);

function fieldByKey(key){ return FIELDS.find(function(f){return f.key===key;})||null; }

function buildOps(type, currentOp){
    var ops = OPS[type]||OPS['text'];
    return ops.map(function(o){
        return '<option value="'+o.v+'"'+(o.v===currentOp?' selected':'')+'>'+o.l+'</option>';
    }).join('');
}

function buildValue(field, currentVal, name){
    if(!field) return '<input type="text" name="'+name+'" class="m-form-input fnf-value" value="'+escH(currentVal)+'" style="width:180px;font-size:12px">';
    if(field.type==='select'&&field.values){
        var opts=field.values.map(function(o){
            return '<option value="'+escH(o.v)+'"'+(o.v===currentVal?' selected':'')+'>'+escH(o.l)+'</option>';
        }).join('');
        return '<select name="'+name+'" class="m-form-select fnf-value" style="width:180px;font-size:12px">'+opts+'</select>';
    }
    if(field.type==='date')
        return '<input type="date" name="'+name+'" class="m-form-input fnf-value" value="'+escH(currentVal)+'" style="width:150px;font-size:12px">';
    if(field.type==='number')
        return '<input type="number" name="'+name+'" class="m-form-input fnf-value" value="'+escH(currentVal)+'" step="any" style="width:120px;font-size:12px">';
    return '<input type="text" name="'+name+'" class="m-form-input fnf-value" value="'+escH(currentVal)+'" style="width:180px;font-size:12px">';
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
        +'<select name="filters['+idx+'][field]" class="m-form-select fnf-field" style="width:160px;font-size:12px">'+fieldOpts+'</select>'
        +'<select name="filters['+idx+'][op]" class="m-form-select fnf-op" style="width:130px;font-size:12px">'+buildOps('text','contains')+'</select>'
        +'<input type="text" name="filters['+idx+'][value]" class="m-form-input fnf-value" style="width:180px;font-size:12px">'
        +'<button type="button" class="fnf-remove" style="background:none;border:none;cursor:pointer;color:#c0392b;font-size:16px;line-height:1;padding:0 4px" title="Odebrat">×</button>'
        +'</div>';

    var container = document.getElementById('fnf-rows');
    container.insertAdjacentHTML('beforeend', html);
    rebindRow(container.lastElementChild);
});
})();
</script>
