@extends('layouts.app')

@section('title', 'Smlouva — ' . $member->name)

@section('menu')
<x-freenetis-menu />
@endsection

@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Členové</a> &raquo;
    <a href="{{ route('members.show', $member->id) }}">{{ $member->name }}</a> &raquo;
    Smlouva
</div>
@endsection

@section('content')
<div class="member-page">

{{-- Titulek --}}
<div class="member-title-row">
    <h2>Smlouva — {{ $member->name }}</h2>
</div>

@php
    // Řádný člen (typ 90) smlouvu nemá — dostávají ji jen zákazníci.
    $canHaveContract = (int) $member->type !== \App\Helpers\MemberType::REGULAR;
@endphp
@if(!$contract)
<div class="m-card" style="margin-bottom:16px">
    <div style="color:#888;font-size:16px;padding:4px 0">Žádná smlouva pro tohoto člena.</div>
    @if($canEdit && $canHaveContract)
    <div style="margin-top:12px">
        <form method="POST" action="{{ route('contracts.create', $member->id) }}">
            @csrf
            <button type="submit" class="m-btn m-btn-success"
                    onclick="return confirm('Vytvořit smlouvu pro {{ addslashes($member->name) }}?')">
                + Vytvořit smlouvu
            </button>
        </form>
    </div>
    @elseif(!$canHaveContract)
    <div style="margin-top:8px;font-size:14px;color:#888">Řádný člen (typ 90) smlouvu nemá.</div>
    @endif
</div>
@else

{{-- Akce --}}
<div class="m-actions">
    <a class="m-btn" href="{{ route('members.show', $member->id) }}">← Zpět na člena</a>
    @if($canEdit && in_array($contract->status, ['draft','otp_sent','otp_verified']))
    <form method="POST" action="{{ route('contracts.send-link', $member->id) }}" style="display:inline">
        @csrf
        <button type="submit" class="m-btn">Odeslat odkaz pro podpis</button>
    </form>
    @endif
    @if($canEdit && $contract->status === 'draft')
    <form method="POST" action="{{ route('contracts.refresh', $member->id) }}" style="display:inline">
        @csrf
        <button type="submit" class="m-btn"
                onclick="return confirm('Přepsat údaje ve smlouvě aktuálními údaji člena (adresa, kontakty, VS, tarif)?')"
                title="Přepsat snapshot v smlouvě aktuálními údaji ze člena (např. po opravě čísla popisného)">
            ↻ Aktualizovat údaje ze člena
        </button>
    </form>
    @endif
    @if($canEdit && in_array($contract->status, ['draft','otp_sent','otp_verified']))
    <form method="POST" action="{{ route('contracts.cancel', $member->id) }}" style="display:inline">
        @csrf
        <button type="submit" class="m-btn m-btn-danger"
                onclick="return confirm('Zrušit nepodepsanou smlouvu {{ addslashes($contract->contract_no) }}? Poté můžete vytvořit novou.')">
            ✕ Zrušit smlouvu
        </button>
    </form>
    @endif
    @if(in_array($contract->status, ['signed','terminated']) && $contract->pdf_path)
    <a class="m-btn" href="{{ route('contracts.download', $contract->id) }}">Stáhnout PDF</a>
    @endif
    @if($canEdit && $contract->status === 'canceled' && $canHaveContract)
    <form method="POST" action="{{ route('contracts.create', $member->id) }}" style="display:inline">
        @csrf
        <button type="submit" class="m-btn m-btn-success"
                onclick="return confirm('Vytvořit novou smlouvu pro {{ addslashes($member->name) }}? (Předchozí zůstane jako zrušená v historii.)')">
            + Vytvořit novou smlouvu
        </button>
    </form>
    @endif
</div>

@if(session('sign_link'))
<div style="margin-bottom:16px;padding:12px 14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;font-size:16px;">
    <strong>Podpisový odkaz (platný 7 dní):</strong><br>
    <a href="{{ session('sign_link') }}" target="_blank" rel="noopener" style="word-break:break-all;color:#92400e">
        {{ session('sign_link') }}
    </a>
</div>
@endif

{{-- Stav smlouvy --}}
@php
    $statusColors = [
        'draft'        => ['bg' => '#fef3c7', 'border' => '#fcd34d', 'text' => '#92400e'],
        'otp_sent'     => ['bg' => '#fef3c7', 'border' => '#fcd34d', 'text' => '#92400e'],
        'otp_verified' => ['bg' => '#dbeafe', 'border' => '#93c5fd', 'text' => '#1e3a8a'],
        'signed'       => ['bg' => '#dcfce7', 'border' => '#86efac', 'text' => '#14532d'],
        'canceled'     => ['bg' => '#fee2e2', 'border' => '#fca5a5', 'text' => '#7f1d1d'],
        'terminated'   => ['bg' => '#f3f4f6', 'border' => '#d1d5db', 'text' => '#374151'],
    ];
    $sc = $statusColors[$contract->status] ?? ['bg' => '#f3f4f6', 'border' => '#d1d5db', 'text' => '#374151'];
@endphp

<div class="m-section">Informace o smlouvě</div>
<div class="m-card" style="margin-bottom:16px">
    <div class="m-field">
        <span class="m-field-label">Stav</span>
        <span class="m-field-value">
            <span style="display:inline-block;padding:3px 12px;border-radius:12px;font-size:16px;font-weight:600;background:{{ $sc['bg'] }};color:{{ $sc['text'] }};border:1px solid {{ $sc['border'] }}">
                {{ $contract->statusLabel() }}
            </span>
        </span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Číslo smlouvy</span>
        <span class="m-field-value" style="font-family:monospace;font-size:17px">{{ $contract->contract_no }}</span>
    </div>
    <div class="m-field">
        <span class="m-field-label">Vytvořeno</span>
        <span class="m-field-value">{{ $contract->created_at ? \Carbon\Carbon::parse($contract->created_at)->format('d.m.Y H:i') : '—' }}</span>
    </div>
    @if($contract->signed_at)
    <div class="m-field">
        <span class="m-field-label">Podepsáno</span>
        <span class="m-field-value" style="color:#16a34a;font-weight:600">
            {{ $contract->signed_at->format('d.m.Y H:i') }}
        </span>
    </div>
    @endif
    @if($contract->phone)
    <div class="m-field">
        <span class="m-field-label">Telefon</span>
        <span class="m-field-value">{{ $contract->phone }}</span>
    </div>
    @endif
</div>

{{-- Smluvní strany --}}
@if($contract->parties->isNotEmpty())
<div class="m-section">Smluvní strana</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($contract->parties as $party)
    <div class="m-field"><span class="m-field-label">Jméno</span><span class="m-field-value">{{ $party->full_name }}</span></div>
    @if($party->street)
    <div class="m-field"><span class="m-field-label">Adresa</span><span class="m-field-value">{{ $party->street }}, {{ $party->service_zip }} {{ $party->town }}</span></div>
    @endif
    @if($party->variable_symbol)
    <div class="m-field"><span class="m-field-label">VS</span><span class="m-field-value" style="font-family:monospace">{{ $party->variable_symbol }}</span></div>
    @endif
    @if($party->phone)
    <div class="m-field"><span class="m-field-label">Telefon</span><span class="m-field-value">{{ $party->phone }}</span></div>
    @endif
    @if($party->email)
    <div class="m-field"><span class="m-field-label">E-mail</span><span class="m-field-value">{{ $party->email }}</span></div>
    @endif
    @if($party->speed_name)
    <div class="m-field"><span class="m-field-label">Tarif</span><span class="m-field-value">{{ $party->speed_name }}</span></div>
    @endif
    <div class="m-field"><span class="m-field-label">Cena</span><span class="m-field-value">{{ number_format($party->price, 0, ',', ' ') }} Kč/měs.</span></div>
    @if($party->ico)
    <div class="m-field"><span class="m-field-label">IČO</span><span class="m-field-value">{{ $party->ico }}</span></div>
    @endif
    @endforeach
</div>
@endif

{{-- Náhled PDF smlouvy — vždy pro nepodepsané, ať admin před odesláním odkazu
     zákazníkovi vidí, jak PDF vypadá. Pro nepodepsané generuje preview na základě
     aktuálního contract_parties snapshotu; pro podepsané ukazuje finální uložené PDF. --}}
@if(in_array($contract->status, ['draft','otp_sent','otp_verified']))
<div class="m-section">Náhled PDF smlouvy</div>
<div class="m-card" style="margin-bottom:16px;padding:8px">
    <div style="font-size:14px;color:#6b7280;margin-bottom:8px">
        Zkontrolujte údaje v PDF <strong>před odesláním odkazu zákazníkovi</strong>.
        Pokud něco nesedí, opravte v editaci člena a klikněte
        „↻ Aktualizovat údaje ze člena" v horní liště.
    </div>
    <iframe src="{{ route('contracts.preview', $contract->id) }}"
            style="width:100%;height:600px;border:1px solid #e5e7eb;border-radius:4px;background:#fff"
            title="Náhled smlouvy">
    </iframe>
</div>
@endif

{{-- Podpisový formulář (iframe) pro nepodepsané smlouvy — jen po odeslání odkazu --}}
@if(in_array($contract->status, ['draft','otp_sent','otp_verified']) && session('sign_link'))
<div class="m-section">Podpisový formulář</div>
<div class="m-card" style="margin-bottom:16px;padding:0">
    {{-- Sandbox záměrně bez: obsah iframu je náš vlastní sign SPA (contracts.sign),
         embed same-origin. Sandbox s `allow-same-origin` + `allow-scripts` triggeroval
         v Chromu blokaci vnitřního PDF <iframe> (preview) chybou "load from frame with
         URL chrome-error://chromewebdata/". Ochrana adminské stránky proti clickjackingu
         zůstává přes X-Frame-Options SAMEORIGIN default v SecurityHeaders. --}}
    <iframe src="{{ session('sign_link') }}"
            style="width:100%;height:600px;border:none;border-radius:6px"
            title="Podpis smlouvy">
    </iframe>
</div>
@endif

{{-- Dodatek --}}
@if($contract->status === 'signed')
@php $addonStatus = app(\App\Services\ContractService::class)->getAddonStatus($contract); @endphp
<div class="m-section">Dodatek ke smlouvě</div>
<div class="m-card" style="margin-bottom:16px">
    @if($addonStatus === 'none')
        <div style="font-size:16px;color:#888;padding:4px 0">Žádný dodatek.</div>
        @if($canEdit)
        <div style="margin-top:12px">
            <form method="POST" action="{{ route('contracts.addon.create', $member->id) }}">
                @csrf
                <button type="submit" class="m-btn m-btn-success"
                        onclick="return confirm('Vytvořit dodatek ke smlouvě {{ addslashes($contract->contract_no) }}?')">
                    + Vytvořit dodatek
                </button>
            </form>
        </div>
        @endif
    @elseif($addonStatus === 'pending')
        <div class="m-field">
            <span class="m-field-label">Stav</span>
            <span class="m-field-value">
                <span style="display:inline-block;padding:3px 12px;border-radius:12px;font-size:16px;font-weight:600;background:#fef3c7;color:#92400e;border:1px solid #fcd34d">
                    Čeká na podpis
                </span>
            </span>
        </div>
        @if($canEdit)
        <div class="m-field">
            <span class="m-field-label">Akce</span>
            <span class="m-field-value" style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="POST" action="{{ route('contracts.addon.send-link', $member->id) }}" style="display:inline">
                    @csrf
                    <button type="submit" class="m-btn">Odeslat odkaz pro podpis dodatku</button>
                </form>
                <form method="POST" action="{{ route('contracts.addon.delete', $contract->id) }}" style="display:inline">
                    @csrf @method('DELETE')
                    <button type="submit" class="m-btn m-btn-danger"
                            onclick="return confirm('Smazat nepodepsaný dodatek ke smlouvě {{ addslashes($contract->contract_no) }}?')">Smazat dodatek</button>
                </form>
            </span>
        </div>
        @endif
        @if(session('addon_link'))
        <div style="margin-top:10px;padding:12px 14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;font-size:16px;">
            <strong>Odkaz pro podpis dodatku (platný 7 dní):</strong><br>
            <a href="{{ session('addon_link') }}" target="_blank" rel="noopener" style="word-break:break-all;color:#92400e">
                {{ session('addon_link') }}
            </a>
        </div>
        @endif
    @else {{-- signed --}}
        <div class="m-field">
            <span class="m-field-label">Stav</span>
            <span class="m-field-value">
                <span style="display:inline-block;padding:3px 12px;border-radius:12px;font-size:16px;font-weight:600;background:#dcfce7;color:#14532d;border:1px solid #86efac">
                    Podepsáno
                </span>
            </span>
        </div>
        @if($contract->addon_signed_at)
        <div class="m-field">
            <span class="m-field-label">Datum podpisu</span>
            <span class="m-field-value" style="color:#16a34a;font-weight:600">
                {{ $contract->addon_signed_at->format('d.m.Y H:i') }}
            </span>
        </div>
        @endif
        @if($contract->addon_pdf_path)
        <div class="m-field">
            <span class="m-field-label">PDF</span>
            <span class="m-field-value">
                <a class="m-btn" href="{{ route('contracts.addon.download', $contract->id) }}">Stáhnout PDF dodatku</a>
            </span>
        </div>
        @endif
    @endif
</div>
@endif

{{-- Ukončení smlouvy (výpověď) — jen pro podepsané smlouvy --}}
@if($canEdit && $contract->status === 'signed')
<div class="m-section">Ukončení smlouvy</div>
<div class="m-card" style="margin-bottom:16px">
    <div style="font-size:14px;color:#6b7280;margin-bottom:12px">
        Označí smlouvu jako <strong>ukončenou</strong> (např. po výpovědi zákazníka).
        Smlouva zůstane v systému jako právní dokument (PDF), jen změní stav na „Ukončená".
    </div>
    <form method="POST" action="{{ route('contracts.terminate', $member->id) }}"
          onsubmit="return confirm('Označit smlouvu {{ addslashes($contract->contract_no) }} jako ukončenou?')">
        @csrf
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div>
                <label class="m-form-label" for="termination_date" style="font-size:14px;color:#6b7280">Datum ukončení</label>
                <input class="m-form-input" type="date" id="termination_date" name="termination_date"
                       value="{{ date('Y-m-d') }}" style="max-width:180px">
            </div>
            <div style="flex:1;min-width:200px">
                <label class="m-form-label" for="reason" style="font-size:14px;color:#6b7280">Důvod / poznámka (nepovinné)</label>
                <input class="m-form-input" type="text" id="reason" name="reason"
                       maxlength="254" placeholder="např. výpověď zákazníka">
            </div>
            <button type="submit" class="m-btn m-btn-danger">Označit jako ukončenou</button>
        </div>
    </form>
</div>
@endif

{{-- Historie událostí --}}
@if($contract->events->isNotEmpty())
<div class="m-section">Historie</div>
<div class="m-card" style="margin-bottom:16px">
    @foreach($contract->events as $event)
    <div class="m-field" style="align-items:flex-start;flex-direction:column;gap:3px;">
        <div style="display:flex;gap:10px;align-items:center">
            <span style="font-size:14px;color:#888;min-width:130px">
                {{ \Carbon\Carbon::parse($event->created_at)->format('d.m.Y H:i') }}
            </span>
            <span style="font-size:16px;font-weight:600">{{ $event->eventLabel() }}</span>
        </div>
        @php $meta = $event->meta(); @endphp
        @if(!empty($meta))
        <div style="font-size:14px;color:#888;margin-left:140px">
            @foreach($meta as $k => $v)
                @if(!is_array($v) && $v !== null && $v !== '')
                <span>{{ $k }}: {{ $v }}</span>{{ !$loop->last ? ' · ' : '' }}
                @endif
            @endforeach
        </div>
        @endif
    </div>
    @endforeach
</div>
@endif

@endif {{-- end $contract exists --}}

</div>
@endsection
