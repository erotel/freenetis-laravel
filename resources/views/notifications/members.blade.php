@extends('layouts.app')
@section('title', 'Hromadné notifikace — ' . $message->name)
@section('menu') <x-freenetis-menu /> @endsection

@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Zákazníci</a> &raquo; Hromadné notifikace
</div>
@endsection

@section('content')
<div class="m-page">
<div class="m-title-row">
    <h2>Hromadné notifikace</h2>
</div>
<div class="m-subtitle">Zpráva: <strong>{{ $message->name }}</strong></div>

{{-- Info box --}}
<div class="m-alert m-alert-info" style="margin-bottom:16px">
    <strong>Akce:</strong>
    <span class="m-badge m-badge-green" style="font-size:13px">Aktivovat</span> — nastaví přesměrování / odešle e-mail / SMS &nbsp;
    <span class="m-badge m-badge-gray" style="font-size:13px">Beze změny</span> — nic se nezmění &nbsp;
    <span class="m-badge m-badge-amber" style="font-size:13px">Deaktivovat</span> — odstraní přesměrování
</div>

<form method="POST" action="{{ route('notifications.members.notify', $message->id) }}">
@csrf

{{-- Komentář --}}
<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <div class="m-form-label">Komentář (nahradí <code>{comment}</code> ve zprávě)</div>
    <textarea class="m-form-input" name="comment" rows="2" style="width:100%;box-sizing:border-box"></textarea>
</div>

{{-- Hromadné akce --}}
<div class="m-card" style="margin-bottom:16px;padding:14px 1.25rem">
    <strong>Nastavit všem:</strong>
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:10px;align-items:center">
        @if($message->text)
        <div>
            <div class="m-form-label">Přesměrování</div>
            <select class="m-form-select" id="bulk-redir">
                <option value="{{ $KEEP }}">Beze změny</option>
                <option value="{{ $ACTIVATE }}">Aktivovat</option>
                <option value="{{ $DEACTIVATE }}">Deaktivovat</option>
            </select>
        </div>
        @endif
        @if($message->email_text)
        <div>
            <div class="m-form-label">E-mail</div>
            <select class="m-form-select" id="bulk-email">
                <option value="{{ $KEEP }}">Beze změny</option>
                <option value="{{ $ACTIVATE }}">Aktivovat</option>
            </select>
        </div>
        @endif
        @if($message->sms_text)
        <div>
            <div class="m-form-label">SMS</div>
            <select class="m-form-select" id="bulk-sms">
                <option value="{{ $KEEP }}">Beze změny</option>
                <option value="{{ $ACTIVATE }}">Aktivovat</option>
            </select>
        </div>
        @endif
        <div style="padding-top:18px">
            <button type="button" class="m-btn m-btn-primary" id="bulk-apply">Použít na všechny</button>
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
    @endphp
    <tr>
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
        @if($message->text)
        <td>
            <select class="m-form-select fn-redir" name="redirection[{{ $m->id }}]" style="width:100px;font-size:14px;padding:2px 4px">
                <option value="{{ $KEEP }}"       @selected($defaultRedir === $KEEP)>Beze změny</option>
                <option value="{{ $ACTIVATE }}"   @selected($defaultRedir === $ACTIVATE)>Aktivovat</option>
                <option value="{{ $DEACTIVATE }}">Deaktivovat</option>
            </select>
        </td>
        @endif
        @if($message->email_text)
        <td>
            <select class="m-form-select fn-email" name="email[{{ $m->id }}]" style="width:100px;font-size:14px;padding:2px 4px">
                <option value="{{ $KEEP }}">Beze změny</option>
                <option value="{{ $ACTIVATE }}">Aktivovat</option>
            </select>
        </td>
        @endif
        @if($message->sms_text)
        <td>
            <select class="m-form-select fn-sms" name="sms[{{ $m->id }}]" style="width:100px;font-size:14px;padding:2px 4px">
                <option value="{{ $KEEP }}">Beze změny</option>
                <option value="{{ $ACTIVATE }}">Aktivovat</option>
            </select>
        </td>
        @endif
    </tr>
    @endforeach
    </tbody>
</table>
</div>

<div class="m-actions">
    <button type="submit" class="m-btn m-btn-primary">Provést notifikace</button>
    <a class="m-btn" href="{{ route('members.index') }}">Zrušit</a>
</div>

</form>
</div>

<script>
document.getElementById('bulk-apply')?.addEventListener('click', function() {
    var redir = document.getElementById('bulk-redir')?.value;
    var email = document.getElementById('bulk-email')?.value;
    var sms   = document.getElementById('bulk-sms')?.value;
    if (redir) document.querySelectorAll('.fn-redir').forEach(function(s){ s.value = redir; });
    if (email) document.querySelectorAll('.fn-email').forEach(function(s){ s.value = email; });
    if (sms)   document.querySelectorAll('.fn-sms').forEach(function(s){ s.value = sms; });
});
</script>
@endsection
