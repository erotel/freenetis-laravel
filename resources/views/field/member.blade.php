@extends('field.layout')

@section('title', $member->name)

@php
    use App\Helpers\MemberType;
    $statusLabels = [
        'ok'          => 'V pořádku',
        'overdue'     => 'Po splatnosti',
        'suspended'   => 'Pozastaveno',
        'blocked'     => 'Blokace platby',
        'terminating' => 'Před ukončením',
    ];
    $expPast = $expirationDate && \Carbon\Carbon::parse($expirationDate)->isPast();
@endphp

@section('content')
<a href="{{ route('field.search') }}" class="f-back">‹ Hledat</a>

{{-- HLAVIČKA --}}
<div class="f-card">
    <div style="display:flex;align-items:center;gap:12px">
        <span class="f-dot {{ $status }}" style="width:16px;height:16px"></span>
        <div style="flex:1;min-width:0">
            <div style="font-size:21px;font-weight:700;line-height:1.2">{{ $member->name }}</div>
            <div style="font-size:14px;color:var(--muted)">
                {{ MemberType::label((int) $member->type) }}
                @if($variableSymbols->isNotEmpty()) · VS {{ $variableSymbols->implode(', ') }} @endif
                · #{{ $member->id }}
            </div>
        </div>
    </div>
    <div style="margin-top:10px;font-size:13px;color:var(--muted)">Stav: {{ $statusLabels[$status] ?? $status }}</div>
</div>

{{-- KONTAKT --}}
<div class="f-card">
    <h2 class="f-section-title">Kontakt</h2>
    @forelse($phones as $phone)
        <a class="f-link" href="tel:{{ preg_replace('/[^+0-9]/', '', $phone) }}">
            <span class="f-ic">📞</span><span>{{ $phone }}</span>
        </a>
    @empty
    @endforelse
    @foreach($emails as $email)
        <a class="f-link" href="mailto:{{ $email }}">
            <span class="f-ic">✉️</span><span style="overflow:hidden;text-overflow:ellipsis">{{ $email }}</span>
        </a>
    @endforeach
    @if($address)
        <a class="f-link" href="https://maps.google.com/?q={{ urlencode($address) }}" target="_blank" rel="noopener">
            <span class="f-ic">📍</span><span>{{ $address }}</span>
        </a>
    @endif
    @if($phones->isEmpty() && $emails->isEmpty() && !$address)
        <div class="f-empty">Žádné kontaktní údaje.</div>
    @endif
</div>

{{-- FINANCE --}}
<div class="f-card">
    <h2 class="f-section-title">Finance</h2>
    @if($balance !== null)
        <div class="f-kv">
            <span class="k">Saldo</span>
            <span class="v {{ $balance < 0 ? 'red' : 'green' }}">{{ number_format($balance, 0, ',', ' ') }} Kč</span>
        </div>
    @endif
    <div class="f-kv">
        <span class="k">Zaplaceno do</span>
        <span class="v {{ $expPast ? 'red' : '' }}">
            {{ $expirationDate ? \Carbon\Carbon::parse($expirationDate)->format('d.m.Y') : '—' }}
        </span>
    </div>
    @if($activeMemberFee && $activeMemberFee->fee)
        <div class="f-kv">
            <span class="k">Měsíční poplatek</span>
            <span class="v">{{ number_format((float) $activeMemberFee->fee->fee, 0, ',', ' ') }} Kč</span>
        </div>
    @endif
</div>

{{-- ZAŘÍZENÍ --}}
<div class="f-card">
    <h2 class="f-section-title">Zařízení</h2>
    @forelse($devices as $device)
        @php
            $devIps = $device->ifaces->flatMap(fn($i) => $i->ipAddresses)->pluck('ip_address')->filter()->unique();
        @endphp
        <a class="f-row" href="{{ route('field.device', $device->id) }}">
            <span class="f-row-main">
                <div class="f-row-title">{{ $device->name }}</div>
                <div class="f-row-sub">{{ $devIps->isNotEmpty() ? $devIps->implode(', ') : 'bez IP' }}</div>
            </span>
            <span class="f-chevron">›</span>
        </a>
    @empty
        <div class="f-empty">Žádná zařízení.</div>
    @endforelse
</div>

{{-- AKCE --}}
@if($canAddNote)
<div class="f-card">
    <h2 class="f-section-title">Akce</h2>
    <button type="button" class="f-btn f-btn-ghost" onclick="document.getElementById('f-note').style.display='block';this.style.display='none';document.getElementById('f-note-text').focus()">
        ＋ Poznámka
    </button>
    <form id="f-note" method="POST" action="{{ route('field.member.note', $member->id) }}" style="display:none;margin-top:12px">
        @csrf
        <textarea id="f-note-text" name="text" class="f-textarea" placeholder="Text poznámky…" maxlength="5000" required></textarea>
        <div style="display:flex;gap:10px;margin-top:10px">
            <button type="submit" class="f-btn" style="flex:1">Uložit</button>
            <button type="button" class="f-btn f-btn-ghost" style="flex:0 0 auto;padding-left:20px;padding-right:20px"
                    onclick="document.getElementById('f-note').reset();document.getElementById('f-note').style.display='none'">Zrušit</button>
        </div>
    </form>
</div>
@endif
@endsection
