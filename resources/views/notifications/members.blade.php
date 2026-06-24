@extends('layouts.app')
@section('title', 'Hromadné notifikace — ' . $message->name)
@section('menu') <x-freenetis-menu /> @endsection

@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Zákazníci</a> &raquo; Hromadné notifikace
</div>
@endsection

@section('content')
<style>
    .fn-row-notifoff > td { background: #fff8e1; }
    .fn-row-notifoff:hover > td { background: #fff3c0; }
</style>
<div class="m-page">
<div class="m-title-row">
    <h2>Hromadné notifikace</h2>
</div>
<div class="m-subtitle">Zpráva: <strong>{{ $message->name }}</strong></div>

{{-- Info box — kontextový podle toho, jaké kanály má zpráva vyplněné --}}
@php
    $activateParts = [];
    if ($message->text)       $activateParts[] = 'nastaví přesměrování';
    if ($message->email_text) $activateParts[] = 'odešle e-mail';
    if ($message->sms_text)   $activateParts[] = 'odešle SMS';
@endphp
<div class="m-alert m-alert-info" style="margin-bottom:16px">
    <strong>Akce:</strong>
    <span class="m-badge m-badge-green" style="font-size:13px">Aktivovat</span>
    — {{ implode(' / ', $activateParts) ?: 'tato zpráva nemá vyplněný žádný kanál' }} &nbsp;
    <span class="m-badge m-badge-gray" style="font-size:13px">Beze změny</span> — nic se nezmění
    @if($message->text)
    &nbsp;<span class="m-badge m-badge-amber" style="font-size:13px">Deaktivovat</span> — odstraní přesměrování
    @endif
</div>

<form method="POST" action="{{ route('notifications.members.notify', $message->id) }}" id="notify-form">
@csrf
{{-- Akce se posílají v jednom JSON inputu, ne v N×3 per-row hidden inputech.
     Důvod: PHP `max_input_vars=1000` by jinak na ~333. členovi spadl a backend
     by dostal oříznutý seznam (bug se projevoval jako 264 hlášených → 120
     odeslaných e-mailů). Per-row inputy zůstávají v DOMu pro UI state, ale
     submit handler je disabluje a místo nich posílá tenhle JSON. --}}
<input type="hidden" name="bulk_payload" id="bulk-payload" value="">

{{-- Komentář --}}
<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <div class="m-form-label">Komentář (nahradí <code>{comment}</code> ve zprávě)</div>
    <textarea class="m-form-input" name="comment" rows="2" style="width:100%;box-sizing:border-box"></textarea>
</div>

{{-- Filtr + hromadné akce --}}
<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <strong>Filtr (omezuje hromadné akce + co je vidět v tabulce):</strong>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:10px;align-items:end">
        <div>
            <div class="m-form-label">Typ</div>
            <select class="m-form-select" id="filter-type" style="width:170px">
                <option value="">Všichni</option>
                @foreach(\App\Helpers\MemberType::labels() as $tval => $tlabel)
                <option value="{{ $tval }}">{{ $tlabel }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <div class="m-form-label">Kredit</div>
            <select class="m-form-select" id="filter-balance" style="width:170px">
                <option value="">Vše</option>
                <option value="neg">Záporný (&lt; 0)</option>
                <option value="pos">Kladný / nulový</option>
            </select>
        </div>
        <div>
            <div class="m-form-label">Whitelist</div>
            <select class="m-form-select" id="filter-whitelist" style="width:140px">
                <option value="">Vše</option>
                <option value="0">Bez whitelistu</option>
                <option value="1">Na whitelistu</option>
            </select>
        </div>
        <div>
            <div class="m-form-label">Přerušení</div>
            <select class="m-form-select" id="filter-interrupted" style="width:140px">
                <option value="">Vše</option>
                <option value="0">Aktivní</option>
                <option value="1">Přerušené</option>
            </select>
        </div>
        <div>
            <div class="m-form-label">Souhlas s oznámením</div>
            <select class="m-form-select" id="filter-notif-off" style="width:170px">
                <option value="">Vše</option>
                <option value="0">Bez omezení</option>
                <option value="1">Aspoň 1 kanál vypnut</option>
            </select>
        </div>
        <div>
            <button type="button" class="m-btn" id="filter-clear">Zrušit filtr</button>
        </div>
        <div style="margin-left:auto;color:#666">
            Viditelných: <strong id="filter-count">{{ count($members) }}</strong> / {{ count($members) }}
        </div>
    </div>

    <div style="border-top:1px solid #eee;margin:14px 0 10px"></div>

    <strong>Nastavit hodnotu jen pro vyfiltrované řádky:</strong>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:10px;align-items:end">
        @if($message->text)
        <div>
            <div class="m-form-label">Přesměrování</div>
            <select class="m-form-select" id="bulk-redir" style="width:140px">
                <option value="{{ $KEEP }}">Beze změny</option>
                <option value="{{ $ACTIVATE }}">Aktivovat</option>
                <option value="{{ $DEACTIVATE }}">Deaktivovat</option>
            </select>
        </div>
        @endif
        @if($message->email_text)
        <div>
            <div class="m-form-label">E-mail</div>
            <select class="m-form-select" id="bulk-email" style="width:140px">
                <option value="{{ $KEEP }}">Beze změny</option>
                <option value="{{ $ACTIVATE }}">Aktivovat</option>
            </select>
        </div>
        @endif
        @if($message->sms_text)
        <div>
            <div class="m-form-label">SMS</div>
            <select class="m-form-select" id="bulk-sms" style="width:140px">
                <option value="{{ $KEEP }}">Beze změny</option>
                <option value="{{ $ACTIVATE }}">Aktivovat</option>
            </select>
        </div>
        @endif
        <div>
            <button type="button" class="m-btn m-btn-primary" id="bulk-apply">Použít na vyfiltrované</button>
        </div>
    </div>
</div>

{{-- Tabulka členů --}}
<div class="m-card" style="padding:0;overflow-x:auto;margin-bottom:16px">
<table class="m-table" style="margin-bottom:0;min-width:1000px">
    <thead>
        <tr>
            <th style="text-align:left">Jméno</th>
            <th>Typ</th>
            <th>Stav (kredit)</th>
            <th>Přerušení</th>
            <th style="white-space:nowrap">Whitelist</th>
            <th style="white-space:nowrap">Oznámení</th>
            @if($message->text)       <th style="white-space:nowrap">Přesměrování</th> @endif
            @if($message->email_text) <th style="white-space:nowrap">E-mail</th> @endif
            @if($message->sms_text)   <th style="white-space:nowrap">SMS</th> @endif
        </tr>
    </thead>
    <tbody>
    @foreach($members as $m)
    @php
        $balance    = $m->credit_balance;
        $typeLabels = \App\Helpers\MemberType::labels();
        $typeLabel  = $typeLabels[$m->type] ?? 'Typ '.$m->type;
        $badgeClass = match($m->type) {
            2, 18   => 'm-badge-blue',
            90, 3   => 'm-badge-green',
            15, 16  => 'm-badge-gray',
            1, 17   => 'm-badge-amber',
            default => 'm-badge-gray',
        };
        $defaultRedir = $m->has_redirection ? $ACTIVATE : $KEEP;
        $balanceNeg   = ($m->credit_balance !== null && $m->credit_balance < 0) ? '1' : '0';
        $notifRedirOff = !$m->notification_by_redirection;
        $notifEmailOff = !$m->notification_by_email;
        $notifSmsOff   = !$m->notification_by_sms;
        $anyOff        = $notifRedirOff || $notifEmailOff || $notifSmsOff;
    @endphp
    <tr class="fn-row {{ $anyOff ? 'fn-row-notifoff' : '' }}"
        data-member-id="{{ $m->id }}"
        data-type="{{ $m->type }}"
        data-balance-neg="{{ $balanceNeg }}"
        data-whitelisted="{{ $m->whitelisted ? 1 : 0 }}"
        data-interrupted="{{ $m->interrupted ? 1 : 0 }}"
        data-notif-off="{{ $anyOff ? 1 : 0 }}"
        data-notif-redir-off="{{ $notifRedirOff ? 1 : 0 }}"
        data-notif-email-off="{{ $notifEmailOff ? 1 : 0 }}"
        data-notif-sms-off="{{ $notifSmsOff ? 1 : 0 }}"
        data-email-contacts="{{ (int) $m->email_contacts_count }}"
        data-phone-contacts="{{ (int) $m->phone_contacts_count }}">
        <td style="text-align:left">
            <a class="m-link" href="{{ route('members.show', $m->id) }}">{{ $m->name }}</a>
        </td>
        <td><span class="m-badge {{ $badgeClass }}" style="font-size:13px">{{ $typeLabel }}</span></td>
        <td style="font-family:monospace;font-size:14px;text-align:right">
            @if($balance !== null)
                <span style="color:{{ $balance >= 0 ? '#27ae60' : '#c0392b' }}">
                    {{ number_format($balance, 2, ',', ' ') }} Kč
                </span>
            @else
                <span style="color:#aaa">—</span>
            @endif
        </td>
        <td style="text-align:center">
            @if($m->interrupted)
                <span class="m-tag m-tag-amber">Ano</span>
            @else
                <span style="color:#aaa">—</span>
            @endif
        </td>
        <td style="text-align:center">
            @if($m->whitelisted)
                <span class="m-tag m-tag-green">Ano</span>
            @else
                <span style="color:#aaa">—</span>
            @endif
        </td>
        <td style="text-align:center;white-space:nowrap">
            @if(!$anyOff)
                <span style="color:#aaa">—</span>
            @else
                <span title="Tyto kanály člen odmítl">
                    @if($notifRedirOff) <span class="m-tag m-tag-amber" style="font-size:11px">⊘ redir</span> @endif
                    @if($notifEmailOff) <span class="m-tag m-tag-amber" style="font-size:11px">⊘ e-mail</span> @endif
                    @if($notifSmsOff)   <span class="m-tag m-tag-amber" style="font-size:11px">⊘ SMS</span> @endif
                </span>
            @endif
        </td>
        @if($message->text)
        <td style="text-align:center">
            <input type="hidden" class="fn-redir" name="redirection[{{ $m->id }}]" value="{{ $defaultRedir }}">
            <span class="fn-redir-label">
                @if($defaultRedir === $ACTIVATE)
                    <span class="m-tag m-tag-green">Aktivovat</span>
                @else
                    <span style="color:#aaa">—</span>
                @endif
            </span>
        </td>
        @endif
        @if($message->email_text)
        <td style="text-align:center">
            <input type="hidden" class="fn-email" name="email[{{ $m->id }}]" value="{{ $KEEP }}">
            <span class="fn-email-label"><span style="color:#aaa">—</span></span>
        </td>
        @endif
        @if($message->sms_text)
        <td style="text-align:center">
            <input type="hidden" class="fn-sms" name="sms[{{ $m->id }}]" value="{{ $KEEP }}">
            <span class="fn-sms-label"><span style="color:#aaa">—</span></span>
        </td>
        @endif
    </tr>
    @endforeach
    </tbody>
</table>
</div>

<div class="m-actions">
    <button type="submit" class="m-btn m-btn-primary" id="submit-btn">Provést notifikace</button>
    <a class="m-btn" href="{{ route('members.index') }}">Zrušit</a>
</div>

</form>
</div>

<script>
(function () {
    var ACTIVATE   = {{ $ACTIVATE }};
    var KEEP       = {{ $KEEP }};
    var DEACTIVATE = {{ $DEACTIVATE }};

    var fType        = document.getElementById('filter-type');
    var fBalance     = document.getElementById('filter-balance');
    var fWhitelist   = document.getElementById('filter-whitelist');
    var fInterrupted = document.getElementById('filter-interrupted');
    var fNotifOff    = document.getElementById('filter-notif-off');
    var fCount       = document.getElementById('filter-count');
    var fClear       = document.getElementById('filter-clear');

    function rowMatchesFilter(tr) {
        var t = fType?.value || '';
        var b = fBalance?.value || '';
        var w = fWhitelist?.value || '';
        var i = fInterrupted?.value || '';
        var n = fNotifOff?.value || '';
        if (t !== '' && tr.dataset.type !== t) return false;
        if (b === 'neg' && tr.dataset.balanceNeg !== '1') return false;
        if (b === 'pos' && tr.dataset.balanceNeg !== '0') return false;
        if (w !== '' && tr.dataset.whitelisted !== w) return false;
        if (i !== '' && tr.dataset.interrupted !== i) return false;
        if (n !== '' && tr.dataset.notifOff !== n) return false;
        return true;
    }

    function applyFilterVisibility() {
        var visible = 0;
        document.querySelectorAll('.fn-row').forEach(function (tr) {
            var ok = rowMatchesFilter(tr);
            tr.style.display = ok ? '' : 'none';
            if (ok) visible++;
        });
        if (fCount) fCount.textContent = visible;
    }

    [fType, fBalance, fWhitelist, fInterrupted, fNotifOff].forEach(function (el) {
        el?.addEventListener('change', applyFilterVisibility);
    });
    fClear?.addEventListener('click', function () {
        [fType, fBalance, fWhitelist, fInterrupted, fNotifOff].forEach(function (el) { if (el) el.value = ''; });
        applyFilterVisibility();
    });

    function labelHtml(val) {
        if (val === String(ACTIVATE))   return '<span class="m-tag m-tag-green">Aktivovat</span>';
        if (val === String(DEACTIVATE)) return '<span class="m-tag m-tag-amber">Deaktivovat</span>';
        return '<span style="color:#aaa">—</span>';
    }

    // Bulk-apply: nastaví hidden input + překreslí label, jen na vyfiltrované řádky.
    document.getElementById('bulk-apply')?.addEventListener('click', function () {
        var redir = document.getElementById('bulk-redir')?.value;
        var email = document.getElementById('bulk-email')?.value;
        var sms   = document.getElementById('bulk-sms')?.value;
        document.querySelectorAll('.fn-row').forEach(function (tr) {
            if (!rowMatchesFilter(tr)) return;
            if (redir) {
                var r = tr.querySelector('.fn-redir');
                var rl = tr.querySelector('.fn-redir-label');
                if (r) r.value = redir;
                if (rl) rl.innerHTML = labelHtml(redir);
            }
            if (email) {
                var e = tr.querySelector('.fn-email');
                var el = tr.querySelector('.fn-email-label');
                if (e) e.value = email;
                if (el) el.innerHTML = labelHtml(email);
            }
            if (sms) {
                var s = tr.querySelector('.fn-sms');
                var sl = tr.querySelector('.fn-sms-label');
                if (s) s.value = sms;
                if (sl) sl.innerHTML = labelHtml(sms);
            }
        });
    });

    // Před submitnutím sečíst kolik akcí se odešle a požádat o potvrzení.
    // Pozor: musíme vybrat KONKRÉTNÍ formulář ID, jinak document.querySelector('form')
    // chytne první form v DOMu (logout v layoutu) a handler se nikdy nespustí.
    document.getElementById('notify-form').addEventListener('submit', function (ev) {
        var counts = {
            redirMembers:  0,
            redirDMembers: 0,
            emailMembers:  0, emailMessages: 0,
            smsMembers:    0, smsMessages:   0,
            optOutRedir:   0, optOutEmail:   0, optOutSms: 0,
        };
        document.querySelectorAll('.fn-row').forEach(function (tr) {
            var redir = parseInt(tr.querySelector('.fn-redir')?.value || '0', 10);
            var email = parseInt(tr.querySelector('.fn-email')?.value || '0', 10);
            var sms   = parseInt(tr.querySelector('.fn-sms')?.value   || '0', 10);
            var emailContacts = parseInt(tr.dataset.emailContacts || '0', 10);
            var phoneContacts = parseInt(tr.dataset.phoneContacts || '0', 10);

            if (redir === ACTIVATE) {
                counts.redirMembers++;
                if (tr.dataset.notifRedirOff === '1') counts.optOutRedir++;
            }
            if (redir === DEACTIVATE) counts.redirDMembers++;
            if (email === ACTIVATE) {
                counts.emailMembers++;
                counts.emailMessages += emailContacts;
                if (tr.dataset.notifEmailOff === '1') counts.optOutEmail++;
            }
            if (sms === ACTIVATE) {
                counts.smsMembers++;
                counts.smsMessages += phoneContacts;
                if (tr.dataset.notifSmsOff === '1') counts.optOutSms++;
            }
        });

        var total = counts.redirMembers + counts.redirDMembers + counts.emailMembers + counts.smsMembers;
        if (total === 0) {
            ev.preventDefault();
            alert('Není nastavena žádná akce — vyber filtr a klikni na „Použít na vyfiltrované".');
            return;
        }

        var lines = ['Opravdu provést tyto notifikace?', ''];
        if (counts.redirMembers)  lines.push('• Aktivovat přesměrování:   ' + counts.redirMembers + ' členů');
        if (counts.redirDMembers) lines.push('• Deaktivovat přesměrování: ' + counts.redirDMembers + ' členů');
        if (counts.emailMembers) {
            lines.push('• Odeslat e-mail:  ' + counts.emailMessages + ' zpráv (' + counts.emailMembers + ' členů)');
        }
        if (counts.smsMembers) {
            lines.push('• Odeslat SMS:     ' + counts.smsMessages + ' zpráv (' + counts.smsMembers + ' členů)');
        }
        var optOutTotal = counts.optOutRedir + counts.optOutEmail + counts.optOutSms;
        if (optOutTotal > 0) {
            lines.push('');
            lines.push('⚠ POZOR: u některých členů kanál odhlášen:');
            if (counts.optOutRedir) lines.push('   • Přesměrování: ' + counts.optOutRedir + ' členů odhlásilo');
            if (counts.optOutEmail) lines.push('   • E-mail:        ' + counts.optOutEmail + ' členů odhlásilo');
            if (counts.optOutSms)   lines.push('   • SMS:           ' + counts.optOutSms + ' členů odhlásilo');
        }
        lines.push('');
        lines.push('E-maily a SMS půjdou do fronty a budou odesílány po dávkách (default 100/min).');

        if (!confirm(lines.join('\n'))) {
            ev.preventDefault();
            return;
        }

        // Sbal akce do jednoho JSON inputu (bulk_payload) a per-row inputy zahoď
        // ze submitu (disabled). 1 input bez ohledu na počet členů → drží to i
        // notifikaci na 2000+ lidí bez ohledu na PHP `max_input_vars`.
        var payload = { redirection: {}, email: [], sms: [] };
        document.querySelectorAll('.fn-row').forEach(function (tr) {
            var memberId = parseInt(tr.dataset.memberId, 10);
            var r = parseInt(tr.querySelector('.fn-redir')?.value || '0', 10);
            var e = parseInt(tr.querySelector('.fn-email')?.value || '0', 10);
            var s = parseInt(tr.querySelector('.fn-sms')?.value   || '0', 10);
            if (r === ACTIVATE || r === DEACTIVATE) payload.redirection[memberId] = r;
            if (e === ACTIVATE) payload.email.push(memberId);
            if (s === ACTIVATE) payload.sms.push(memberId);
        });
        document.getElementById('bulk-payload').value = JSON.stringify(payload);
        document.querySelectorAll('.fn-redir, .fn-email, .fn-sms').forEach(function (inp) {
            inp.disabled = true;
        });
    });
})();
</script>
@endsection
