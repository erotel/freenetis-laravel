@extends('layouts.app')
@section('title', 'GPON – Mapa ONT')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('gpon.index') }}">GPON</a> › Mapa</div>
@endsection
@section('content')
<div class="m-page">

<div class="m-title-row">
    <h2>Mapa ONT</h2>
    <a class="m-btn" href="{{ route('gpon.index') }}">← Zpět</a>
</div>

<div class="m-card" style="padding:0;overflow:hidden">
    <div id="gpon-map" style="height:600px;width:100%"></div>
</div>

<div style="margin-top:10px;font-size:13px;color:var(--fn-text-muted)">
    Zobrazeno {{ $onts->count() }} ONT s GPS souřadnicemi.
    <span style="margin-left:16px">
        <span style="display:inline-block;width:12px;height:12px;background:#16a34a;border-radius:50%;vertical-align:middle"></span> Registrovaná
    </span>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

@php
$ontsJson = $onts->map(fn($o) => [
    'id'       => $o->id,
    'serial'   => $o->serial,
    'house_no' => $o->house_no,
    'name'     => $o->user_name,
    'port'     => $o->gpon_port,
    'lat'      => (float) $o->gps_lat,
    'lng'      => (float) $o->gps_lng,
    'url'      => route('gpon.show', $o->id),
])->values();
@endphp

<script>
(function () {
    var onts = @json($ontsJson);

    if (!onts.length) {
        document.getElementById('gpon-map').innerHTML =
            '<div style="padding:40px;text-align:center;color:#888">Žádná ONT s GPS souřadnicemi.</div>';
        return;
    }

    var avgLat = onts.reduce(function(s, o) { return s + o.lat; }, 0) / onts.length;
    var avgLng = onts.reduce(function(s, o) { return s + o.lng; }, 0) / onts.length;

    var map = L.map('gpon-map').setView([avgLat, avgLng], 14);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        maxZoom: 19
    }).addTo(map);

    var greenIcon = L.divIcon({
        className: '',
        html: '<div style="width:14px;height:14px;background:#16a34a;border:2px solid #fff;border-radius:50%;box-shadow:0 1px 4px rgba(0,0,0,.4)"></div>',
        iconSize: [14, 14],
        iconAnchor: [7, 7],
    });

    onts.forEach(function(o) {
        var popup = '<strong>' + (o.house_no || o.serial) + '</strong>';
        if (o.name) popup += '<br>' + o.name;
        if (o.port) popup += '<br><span style="color:#888;font-size:12px">' + o.port + '</span>';
        popup += '<br><a href="' + o.url + '" style="font-size:12px">Detail →</a>';

        L.marker([o.lat, o.lng], { icon: greenIcon })
            .addTo(map)
            .bindPopup(popup);
    });
})();
</script>

</div>
@endsection
