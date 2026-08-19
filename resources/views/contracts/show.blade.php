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
    <div class="m-field"><span class="m-field-label">Adresa</span><span class="m-field-value">{{ $party->street }}, {{ $party->town }}</span></div>
    @endif
    <div class="m-field"><span class="m-field-label">Místo připojení</span><span class="m-field-value">{{ $party->service_full_address ?: '—' }}</span></div>
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

    @if($canEdit && $contract->status === 'draft')
    @php $editParty = $contract->parties->sortByDesc('id')->first(); @endphp
    <div class="m-field" style="align-items:flex-start">
        <span class="m-field-label">Upravit místo připojení</span>
        <span class="m-field-value" style="flex:1">
            <form method="POST" action="{{ route('contracts.service-address', $member->id) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                @csrf
                <input type="text" name="service_full_address" maxlength="255"
                       value="{{ $editParty->service_full_address }}"
                       class="m-form-input" style="flex:1;min-width:240px"
                       placeholder="např. Háj 322, 79804 Kralice na Hané">
                <button type="submit" class="m-btn">Uložit místo připojení</button>
            </form>
            <div class="m-form-hint" style="margin-top:4px">Předvyplněno z adresy prvního zařízení člena (jinak z adresy člena). Lze ručně přepsat.</div>
        </span>
    </div>
    @endif
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

{{-- Dodatek – umístění AP --}}
@if($contract->status === 'signed')
@php $addonStatus = app(\App\Services\ContractService::class)->getAddonStatus($contract); @endphp
<div class="m-section">Dodatek ke smlouvě – umístění AP na nemovitosti</div>
<div class="m-card" style="margin-bottom:16px">
    <div style="font-size:14px;color:#6b7280;margin-bottom:12px">
        Dodatek pro majitele nemovitosti, kde je umístěn přístupový bod (AP) — zvýhodněný (nulový) tarif.
        Změnu tarifu nebo doplňkové služby řeš v sekcích níže.
    </div>
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

{{-- Dodatky – změna tarifu --}}
@if($contract->status === 'signed')
<div class="m-section">Dodatky – změna tarifu</div>
<div class="m-card" style="margin-bottom:16px">
    <div style="font-size:14px;color:#6b7280;margin-bottom:12px">
        Vyber nový tarif — dodatek je jen návrh. Nová rychlost i cena naskočí zákazníkovi
        <strong>až po podpisu</strong> dodatku (do té doby má původní tarif).
    </div>
    @if($canEdit && $canEditQos)
    <form method="POST" action="{{ route('contracts.tariff_addon.create', $member->id) }}"
          style="margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        @csrf
        <div>
            <label for="speed_class_id" style="display:block;font-size:13px;color:#6b7280;margin-bottom:4px">Nový tarif</label>
            <select id="speed_class_id" name="speed_class_id" class="m-form-select" required style="min-width:280px">
                @foreach($speedClasses as $sc)
                    <option value="{{ $sc->id }}" {{ (int) $member->speed_class_id === (int) $sc->id ? 'selected' : '' }}>
                        {{ $sc->name }}@if($sc->price !== null) ({{ number_format((float) $sc->price, 0, ',', ' ') }} Kč)@endif
                    </option>
                @endforeach
            </select>
        </div>
        <button class="m-btn m-btn-primary" type="submit">Vytvořit dodatek – změna tarifu</button>
    </form>
    @elseif($canEdit)
    <div style="color:#9ca3af;font-size:13px">Na změnu tarifu nemáš oprávnění (qos_ceil).</div>
    @endif
    @if(session('tariff_addon_link'))
    <div class="m-alert" style="background:#fef3c7;border:1px solid #fcd34d;padding:10px;border-radius:6px;margin-bottom:12px">
        Odkaz k podpisu (pošli zákazníkovi, když nedorazil e-mailem):<br>
        <a href="{{ session('tariff_addon_link') }}" target="_blank" rel="noopener" style="word-break:break-all;color:#92400e">
            {{ session('tariff_addon_link') }}
        </a>
    </div>
    @endif
    @forelse($tariffAddons as $a)
    <div style="border-top:1px solid #eee;padding:10px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
        <div style="flex:1;min-width:260px;font-size:14px">
            <span style="color:#6b7280">Dodatek č. {{ $a->addon_no ?? '—' }}:</span>
            <strong>{{ $a->old_speed_name ?: '—' }}</strong> ({{ $a->priceLabel($a->old_price) }})
            &rarr; <strong>{{ $a->new_speed_name ?: '—' }}</strong> ({{ $a->priceLabel($a->new_price) }})
            @if($a->new_price_after_discount !== null)
                <span style="color:#6b7280">, zvýhodněná {{ $a->priceLabel($a->new_price_after_discount) }}</span>
            @endif
            <br>
            <span style="color:#6b7280">účinnost {{ $a->effective_date?->format('d.m.Y') }} · {{ $a->statusLabel() }}</span>
        </div>
        @if($a->status === 'signed')
        <a class="m-btn" href="{{ route('contracts.tariff_addon.download', $a->id) }}">Stáhnout PDF</a>
        @else
        <a class="m-btn" href="{{ route('contracts.tariff_addon.preview', $a->id) }}" target="_blank" rel="noopener">Náhled PDF</a>
        @if($canEdit)
        <form method="POST" action="{{ route('contracts.tariff_addon.send-link', $a->id) }}" style="display:inline">
            @csrf
            <button class="m-btn m-btn-primary" type="submit">Poslat odkaz k podpisu</button>
        </form>
        <form method="POST" action="{{ route('contracts.tariff_addon.delete', $a->id) }}" style="display:inline"
              onsubmit="return confirm('Smazat tento dodatek?')">
            @csrf @method('DELETE')
            <button class="m-btn" type="submit">Smazat</button>
        </form>
        @endif
        @endif
    </div>
    @empty
    <div style="color:#9ca3af;font-size:14px">Zatím žádný dodatek změny tarifu.</div>
    @endforelse
</div>
@endif

{{-- Dodatky – dodatečné služby (např. veřejná IP) --}}
@if($contract->status === 'signed')
<div class="m-section">Dodatky – dodatečné služby</div>
<div class="m-card" style="margin-bottom:16px">
    <div style="font-size:14px;color:#6b7280;margin-bottom:12px">
        Dodatek zaznamená přidání nebo zrušení doplňkové služby (např. veřejná IP) s účinností od 1. dne dalšího měsíce.
        Službu přiřaď členovi předem v <a href="{{ route('members_fees.by_member', $member->id) }}">poplatcích člena</a>; dodatek přebírá její název a cenu.
    </div>
    @if($canEdit)
        @if(!empty($assignableServices))
        <form method="POST" action="{{ route('contracts.service_addon.create', $member->id) }}" style="margin-bottom:14px">
            @csrf
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                <div>
                    <label class="m-form-label" for="svc_fee_id" style="font-size:14px;color:#6b7280">Služba</label>
                    <select class="m-form-input" id="svc_fee_id" name="fee_id" style="min-width:220px">
                        @foreach($assignableServices as $s)
                        <option value="{{ $s['fee_id'] }}">{{ $s['name'] !== '' ? $s['name'] : 'Dodatečná služba' }} — {{ number_format($s['fee'], 0, ',', ' ') }} Kč</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="m-form-label" for="svc_action" style="font-size:14px;color:#6b7280">Akce</label>
                    <select class="m-form-input" id="svc_action" name="action" style="min-width:140px">
                        <option value="add">Přidání</option>
                        <option value="remove">Zrušení</option>
                    </select>
                </div>
                <button class="m-btn m-btn-primary" type="submit">Vytvořit dodatek</button>
            </div>
        </form>
        @else
        <div style="color:#9ca3af;font-size:14px;margin-bottom:12px">
            Člen nemá žádnou aktivní dodatečnou službu. Nejprve ji přiřaď v <a href="{{ route('members_fees.by_member', $member->id) }}">poplatcích člena</a>.
        </div>
        @endif
    @endif
    @if(session('service_addon_link'))
    <div class="m-alert" style="background:#fef3c7;border:1px solid #fcd34d;padding:10px;border-radius:6px;margin-bottom:12px">
        Odkaz k podpisu (pošli zákazníkovi, když nedorazil e-mailem):<br>
        <a href="{{ session('service_addon_link') }}" target="_blank" rel="noopener" style="word-break:break-all;color:#92400e">
            {{ session('service_addon_link') }}
        </a>
    </div>
    @endif
    @forelse($serviceAddons as $a)
    <div style="border-top:1px solid #eee;padding:10px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
        <div style="flex:1;min-width:260px;font-size:14px">
            <span style="color:#6b7280">Dodatek č. {{ $a->addon_no ?? '—' }}:</span>
            <strong>{{ $a->serviceActionLabel() }}</strong> — {{ $a->service_name ?: '—' }} ({{ $a->priceLabel($a->service_price) }} / měsíc)
            <br>
            <span style="color:#6b7280">účinnost {{ $a->effective_date?->format('d.m.Y') }} · {{ $a->statusLabel() }}</span>
        </div>
        @if($a->status === 'signed')
        <a class="m-btn" href="{{ route('contracts.service_addon.download', $a->id) }}">Stáhnout PDF</a>
        @else
        <a class="m-btn" href="{{ route('contracts.service_addon.preview', $a->id) }}" target="_blank" rel="noopener">Náhled PDF</a>
        @if($canEdit)
        @if($a->service_action === 'add')
        <form method="POST" action="{{ route('contracts.service_addon.send-link', $a->id) }}" style="display:inline">
            @csrf
            <button class="m-btn m-btn-primary" type="submit">Poslat odkaz k podpisu</button>
        </form>
        @else
        <form method="POST" action="{{ route('contracts.service_addon.issue-removal', $a->id) }}" style="display:inline"
              onsubmit="return confirm('Vydat dodatek o zrušení služby bez podpisu zákazníka? Bude odeslán e-mailem.')">
            @csrf
            <button class="m-btn m-btn-primary" type="submit">Vydat (bez podpisu)</button>
        </form>
        @endif
        <form method="POST" action="{{ route('contracts.service_addon.delete', $a->id) }}" style="display:inline"
              onsubmit="return confirm('Smazat tento dodatek?')">
            @csrf @method('DELETE')
            <button class="m-btn" type="submit">Smazat</button>
        </form>
        @endif
        @endif
    </div>
    @empty
    <div style="color:#9ca3af;font-size:14px">Zatím žádný dodatek dodatečné služby.</div>
    @endforelse
</div>
@endif

{{-- Dodatky – přípojná místa (placené povolené podsítě) --}}
@if($contract->status === 'signed')
<div class="m-section">Dodatky – přípojná místa</div>
<div class="m-card" style="margin-bottom:16px">
    <div style="font-size:14px;color:#6b7280;margin-bottom:12px">
        Dodatek zaznamená přidání nebo zrušení placeného přípojného místa s účinností od 1. dne dalšího měsíce.
        Místo označ jako placené v <a href="{{ route('allowed_subnets.by_member', $member->id) }}">povolených podsítích člena</a>; dodatek přebírá jeho podsíť, rychlost a cenu.
    </div>
    @if($canEdit)
        @if(!empty($assignablePlaces))
        <form method="POST" action="{{ route('contracts.connection_point.create', $member->id) }}" style="margin-bottom:14px">
            @csrf
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                <div>
                    <label class="m-form-label" for="cp_id" style="font-size:14px;color:#6b7280">Placené místo (rychlost + cena)</label>
                    <select class="m-form-input" id="cp_id" name="allowed_subnet_id" style="min-width:240px" onchange="cpSyncAddress()">
                        @foreach($assignablePlaces as $p)
                        <option value="{{ $p['id'] }}" data-address="{{ $p['address'] }}">{{ $p['name'] !== '' ? $p['name'] : 'Přípojné místo' }}{{ $p['speed'] !== '' ? ' ('.$p['speed'].')' : '' }} — {{ number_format($p['fee'], 0, ',', ' ') }} Kč</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="m-form-label" for="cp_address" style="font-size:14px;color:#6b7280">Adresa připojení (ze zařízení, lze upravit)</label>
                    <input class="m-form-input" id="cp_address" name="address" value="{{ $assignablePlaces[0]['address'] ?? '' }}" required placeholder="ulice číslo, obec PSČ" style="min-width:240px">
                </div>
                <div>
                    <label class="m-form-label" for="cp_action" style="font-size:14px;color:#6b7280">Akce</label>
                    <select class="m-form-input" id="cp_action" name="action" style="min-width:140px">
                        <option value="add">Přidání</option>
                        <option value="remove">Zrušení</option>
                    </select>
                </div>
                <button class="m-btn m-btn-primary" type="submit">Vytvořit dodatek</button>
            </div>
        </form>
        <script>
        function cpSyncAddress() {
            var sel = document.getElementById('cp_id');
            var addr = document.getElementById('cp_address');
            if (!sel || !addr) return;
            var opt = sel.options[sel.selectedIndex];
            addr.value = opt ? (opt.getAttribute('data-address') || '') : '';
        }
        </script>
        @else
        <div style="color:#9ca3af;font-size:14px;margin-bottom:12px">
            Člen nemá žádné placené přípojné místo. Nejprve ho označ jako placené v <a href="{{ route('allowed_subnets.by_member', $member->id) }}">povolených podsítích člena</a>.
        </div>
        @endif
    @endif
    @if(session('connection_point_addon_link'))
    <div class="m-alert" style="background:#fef3c7;border:1px solid #fcd34d;padding:10px;border-radius:6px;margin-bottom:12px">
        Odkaz k podpisu (pošli zákazníkovi, když nedorazil e-mailem):<br>
        <a href="{{ session('connection_point_addon_link') }}" target="_blank" rel="noopener" style="word-break:break-all;color:#92400e">
            {{ session('connection_point_addon_link') }}
        </a>
    </div>
    @endif
    @forelse($connectionPointAddons as $a)
    <div style="border-top:1px solid #eee;padding:10px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
        <div style="flex:1;min-width:260px;font-size:14px">
            <span style="color:#6b7280">Dodatek č. {{ $a->addon_no ?? '—' }}:</span>
            <strong>{{ $a->serviceActionLabel() }}</strong> — {{ $a->service_name ?: '—' }}{{ $a->place_speed_name ? ' ('.$a->place_speed_name.')' : '' }} ({{ $a->priceLabel($a->service_price) }} / měsíc)
            <br>
            <span style="color:#6b7280">účinnost {{ $a->effective_date?->format('d.m.Y') }} · {{ $a->statusLabel() }}</span>
        </div>
        @if($a->status === 'signed')
        <a class="m-btn" href="{{ route('contracts.connection_point.download', $a->id) }}">Stáhnout PDF</a>
        @else
        <a class="m-btn" href="{{ route('contracts.connection_point.preview', $a->id) }}" target="_blank" rel="noopener">Náhled PDF</a>
        @if($canEdit)
        @if($a->service_action === 'add')
        <form method="POST" action="{{ route('contracts.connection_point.send-link', $a->id) }}" style="display:inline">
            @csrf
            <button class="m-btn m-btn-primary" type="submit">Poslat odkaz k podpisu</button>
        </form>
        @else
        <form method="POST" action="{{ route('contracts.connection_point.issue-removal', $a->id) }}" style="display:inline"
              onsubmit="return confirm('Vydat dodatek o zrušení přípojného místa bez podpisu zákazníka? Bude odeslán e-mailem.')">
            @csrf
            <button class="m-btn m-btn-primary" type="submit">Vydat (bez podpisu)</button>
        </form>
        @endif
        <form method="POST" action="{{ route('contracts.connection_point.delete', $a->id) }}" style="display:inline"
              onsubmit="return confirm('Smazat tento dodatek?')">
            @csrf @method('DELETE')
            <button class="m-btn" type="submit">Smazat</button>
        </form>
        @endif
        @endif
    </div>
    @empty
    <div style="color:#9ca3af;font-size:14px">Zatím žádný dodatek přípojného místa.</div>
    @endforelse
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

{{-- Historie událostí — jen administrativně (zákazník audit log nevidí) --}}
@if($isAdmin && $contract->events->isNotEmpty())
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
