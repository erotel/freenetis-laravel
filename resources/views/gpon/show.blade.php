@extends('layouts.app')
@section('title', 'ONT – ' . $ont->serial)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs"><a href="{{ route('gpon.index') }}">GPON</a> › {{ $ont->serial }}</div>
@endsection
@section('content')
<div class="m-page">

<div class="m-title-row">
    <h2>ONT detail</h2>
    <a class="m-btn" href="{{ route('gpon.index') }}">← Zpět</a>
</div>

@if(session('success'))
    <div class="m-alert m-alert-success" style="margin-bottom:16px">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="m-alert m-alert-danger" style="margin-bottom:16px">{{ session('error') }}</div>
@endif

<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start">

{{-- Základní info --}}
<div class="m-card" style="flex:1;min-width:280px;max-width:520px">
    <div class="m-card-title">Informace o ONT</div>
    <table style="width:100%;border-collapse:collapse;font-size:14px">
        <tr><td style="padding:5px 0;color:var(--fn-text-muted);width:140px">Serial</td>
            <td style="font-family:monospace">{{ $ont->serial }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">GPON port</td>
            <td>{{ $ont->gpon_port }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Port num</td>
            <td>{{ $ont->port_num }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Port index</td>
            <td>{{ $ont->port_index ?? '—' }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">ONT ID</td>
            <td>{{ $ont->ont_id }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Service port</td>
            <td>{{ $ont->service_port }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">VLAN</td>
            <td>{{ $ont->vlan }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">OLT IP</td>
            <td>{{ $ont->olt_ip ?? '—' }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Stav</td>
            <td>
                @if($ont->reg_status === 'new')
                    <span class="m-tag" style="background:#fef3c7;color:#92400e">Nová</span>
                @elseif($ont->reg_status === 'registered')
                    <span class="m-tag m-tag-green">Registrovaná</span>
                @else
                    <span class="m-tag" style="background:#f3f4f6;color:#6b7280">Odebraná</span>
                @endif
            </td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Č. domu</td>
            <td>{{ $ont->house_no ?: '—' }}</td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Přidal</td>
            <td>
                @if($ont->addedBy)
                    <a href="{{ route('users.show', $ont->addedBy->id) }}">{{ $ont->addedBy->login }}</a>
                @else
                    {{ $ont->user_name ?? '—' }}
                @endif
            </td></tr>
        <tr><td style="padding:5px 0;color:var(--fn-text-muted);vertical-align:top">Zákazník</td>
            <td>
                {{-- Zobrazení --}}
                <div id="member-display" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    @if($ont->member)
                        <a href="{{ route('members.show', $ont->member_id) }}">
                            {{ $ont->member->name ?? ('Zákazník #' . $ont->member_id) }}
                        </a>
                    @else
                        <span id="member-display-text" style="color:var(--fn-text-muted)">—</span>
                    @endif
                    <button type="button" class="m-btn" style="font-size:11px;padding:2px 8px"
                            onclick="memberEditShow()">Upravit</button>
                </div>
                {{-- Inline edit --}}
                <form id="member-edit-form" method="POST"
                      action="{{ route('gpon.update-member', $ont->id) }}"
                      style="display:none;margin-top:6px">
                    @csrf
                    <select name="member_id" class="m-form-select" style="max-width:260px;font-size:13px;margin-bottom:6px">
                        <option value="">— žádný zákazník —</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}" {{ $ont->member_id == $c->id ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                    <div style="display:flex;gap:6px">
                        <button type="submit" class="m-btn m-btn-primary" style="font-size:12px;padding:3px 10px">Uložit</button>
                        <button type="button" class="m-btn" style="font-size:12px;padding:3px 10px"
                                onclick="memberEditHide()">Zrušit</button>
                    </div>
                </form>
            </td></tr>
        @if($ont->device)
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Zařízení</td>
            <td><a href="{{ route('devices.show', $ont->device_id) }}">{{ $ont->device->name ?? ('Zařízení #' . $ont->device_id) }}</a></td></tr>
        @endif
        <tr><td style="padding:5px 0;color:var(--fn-text-muted)">Vytvořeno</td>
            <td>{{ $ont->created_at?->format('d.m.Y H:i') ?? '—' }}</td></tr>
    </table>
</div>

{{-- Live SNMP data --}}
<div style="flex:1;min-width:260px;max-width:420px">
@if($ont->reg_status === 'registered')

<div class="m-card">
    <div class="m-card-title">Stav ONT (live)</div>

    @if($details)
    {{-- Online badge --}}
    <div style="margin-bottom:14px">
        @if($details['online'])
            <span class="m-tag m-tag-green" style="font-size:13px">● Online</span>
        @else
            <span class="m-tag" style="background:#fee2e2;color:#b91c1c;font-size:13px">● Offline</span>
        @endif
    </div>

    {{-- Metrics grid --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
        @php
            $rxVal  = $details['rx_power'];
            $txVal  = $details['tx_power'];
            $rxColor = ($rxVal !== 'DOWN' && (float)$rxVal >= -27) ? '#16a34a' : '#dc2626';
            $txColor = ($txVal !== 'DOWN' && (float)$txVal >= -27) ? '#16a34a' : '#dc2626';
        @endphp
        <div class="m-metric" style="border-radius:6px;padding:10px 12px">
            <div style="font-size:11px;color:var(--fn-text-muted);margin-bottom:2px">Rx výkon</div>
            <div style="font-size:18px;font-weight:700;color:{{ $rxVal === 'DOWN' ? '#dc2626' : $rxColor }}">
                {{ $rxVal }}{{ $rxVal !== 'DOWN' ? ' dBm' : '' }}
            </div>
        </div>
        <div class="m-metric" style="border-radius:6px;padding:10px 12px">
            <div style="font-size:11px;color:var(--fn-text-muted);margin-bottom:2px">Tx výkon</div>
            <div style="font-size:18px;font-weight:700;color:{{ $txVal === 'DOWN' ? '#dc2626' : $txColor }}">
                {{ $txVal }}{{ $txVal !== 'DOWN' ? ' dBm' : '' }}
            </div>
        </div>
        <div class="m-metric" style="border-radius:6px;padding:10px 12px">
            <div style="font-size:11px;color:var(--fn-text-muted);margin-bottom:2px">Teplota</div>
            <div class="m-metric-value" style="font-size:18px;font-weight:700">
                {{ $details['temperature'] }}{{ $details['temperature'] !== 'DOWN' ? ' °C' : '' }}
            </div>
        </div>
        <div class="m-metric" style="border-radius:6px;padding:10px 12px">
            <div style="font-size:11px;color:var(--fn-text-muted);margin-bottom:2px">Vzdálenost</div>
            <div class="m-metric-value" style="font-size:18px;font-weight:700">
                {{ $details['distance'] }}{{ $details['distance'] !== 'DOWN' ? ' m' : '' }}
            </div>
        </div>
    </div>

    {{-- Detail fields --}}
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <tr>
            <td style="padding:4px 0;color:var(--fn-text-muted);width:110px">Napětí</td>
            <td>{{ $details['voltage'] }}{{ $details['voltage'] !== 'DOWN' ? ' V' : '' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0;color:var(--fn-text-muted)">Proud</td>
            <td>{{ $details['current'] }}{{ $details['current'] !== 'DOWN' ? ' mA' : '' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0;color:var(--fn-text-muted)">ETH status</td>
            <td>
                @if($details['eth_status'] === 'UP')
                    <span class="m-tag m-tag-green">UP</span>
                @else
                    <span class="m-tag" style="background:#fee2e2;color:#b91c1c">DOWN</span>
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding:4px 0;color:var(--fn-text-muted)">Duplex</td>
            <td>{{ $details['eth_duplex'] }}</td>
        </tr>
        <tr>
            <td style="padding:4px 0;color:var(--fn-text-muted)">Rychlost</td>
            <td>{{ $details['eth_speed'] }}</td>
        </tr>
    </table>

    @else
    <p style="color:var(--fn-text-muted);font-size:13px;margin:0">SNMP data nejsou dostupná.</p>
    @endif
</div>
@endif
</div>

{{-- Akce --}}
<div style="display:flex;flex-direction:column;gap:16px;min-width:240px">

    @if($ont->reg_status === 'new' || $ont->reg_status === 'removed')
    <div class="m-card">
        <div class="m-card-title">Registrovat ONT</div>
        <form method="POST" action="{{ route('gpon.register', $ont->id) }}">
            @csrf
            <div class="m-form-group">
                <label class="m-form-label">Číslo domu</label>
                <input class="m-form-input" type="text" name="house_no" value="{{ $ont->house_no }}" maxlength="32" placeholder="např. 123">
            </div>

            {{-- Member autocomplete --}}
            <div class="m-form-group" style="position:relative">
                <label class="m-form-label">Hledat člena</label>
                <input id="gpon-member-search" class="m-form-input" type="text"
                       autocomplete="off" placeholder="Hledat člena..."
                       value="{{ $ont->member ? $ont->member->name : '' }}">
                <input type="hidden" name="member_id" id="gpon-member-id"
                       value="{{ $ont->member_id }}">
                <div id="gpon-member-dropdown"
                     style="display:none;position:absolute;top:100%;left:0;right:0;z-index:100;
                            background:var(--fn-card-bg,#fff);border:1px solid var(--fn-border,#e5e7eb);
                            border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);max-height:220px;overflow-y:auto"></div>
                <div class="m-form-hint">Nebo vyplňte jméno níže bez propojení se členem.</div>
            </div>

            <div class="m-form-group">
                <label class="m-form-label">Jméno uživatele <span style="font-weight:400;color:var(--fn-text-muted)">(bez propojení se členem)</span></label>
                <input class="m-form-input" type="text" name="user_name" id="gpon-user-name"
                       value="{{ $ont->user_name }}" maxlength="128" placeholder="Příjmení Jméno">
            </div>

            <div class="m-actions" style="margin-top:12px">
                <button class="m-btn m-btn-success" type="submit">Registrovat</button>
                <button type="button" id="gpon-clear-member" class="m-btn"
                        style="font-size:12px;display:{{ $ont->member_id ? 'inline-flex' : 'none' }}">
                    × Odebrat propojení se členem
                </button>
            </div>
        </form>
    </div>

    <script>
    (function () {
        const searchInput  = document.getElementById('gpon-member-search');
        const memberIdInput = document.getElementById('gpon-member-id');
        const dropdown     = document.getElementById('gpon-member-dropdown');
        const userNameInput = document.getElementById('gpon-user-name');
        const clearBtn     = document.getElementById('gpon-clear-member');
        let debounceTimer;

        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            const q = this.value.trim();
            if (q.length < 2) { dropdown.style.display = 'none'; return; }
            debounceTimer = setTimeout(() => fetchMembers(q), 250);
        });

        searchInput.addEventListener('blur', function () {
            setTimeout(() => { dropdown.style.display = 'none'; }, 200);
        });

        clearBtn.addEventListener('click', function () {
            memberIdInput.value = '';
            searchInput.value = '';
            clearBtn.style.display = 'none';
        });

        function fetchMembers(q) {
            fetch('/new/search/ajax?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    const members = data.filter(r => r.url && r.url.includes('/members/'));
                    renderDropdown(members);
                })
                .catch(() => { dropdown.style.display = 'none'; });
        }

        function renderDropdown(items) {
            if (!items.length) { dropdown.style.display = 'none'; return; }
            dropdown.innerHTML = items.map(item => {
                const idMatch = item.url.match(/\/members\/(\d+)/);
                const id = idMatch ? idMatch[1] : '';
                return `<div class="gpon-ac-item" data-id="${id}" data-name="${escHtml(item.title)}"
                             style="padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--fn-border,#f3f4f6)">
                            <strong>${escHtml(item.title)}</strong>
                            ${item.detail ? '<span style="color:#888;margin-left:6px">' + escHtml(item.detail) + '</span>' : ''}
                        </div>`;
            }).join('');
            dropdown.style.display = 'block';
            dropdown.querySelectorAll('.gpon-ac-item').forEach(el => {
                el.addEventListener('mousedown', function () {
                    memberIdInput.value = this.dataset.id;
                    searchInput.value   = this.dataset.name;
                    userNameInput.value = '';
                    clearBtn.style.display = 'inline-flex';
                    dropdown.style.display = 'none';
                });
            });
        }

        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    })();
    </script>
    @endif

    @if($ont->reg_status === 'registered')
    <div class="m-card">
        <div class="m-card-title">Odebrat ONT</div>
        <p class="m-form-hint">ONT bude odebrána z OLT a označena jako odebraná.</p>
        <form method="POST" action="{{ route('gpon.remove', $ont->id) }}"
              onsubmit="return confirm('Opravdu odebrat ONT {{ $ont->serial }}?')">
            @csrf
            <button class="m-btn" style="background:#fee2e2;color:#b91c1c;border-color:#fca5a5" type="submit">Odebrat ONT</button>
        </form>
    </div>
    @endif

</div>
</div>


<script>
function memberEditShow() {
    document.getElementById('member-display').style.display = 'none';
    document.getElementById('member-edit-form').style.display = 'block';
}
function memberEditHide() {
    document.getElementById('member-edit-form').style.display = 'none';
    document.getElementById('member-display').style.display = 'flex';
}
</script>
</div>
@endsection
