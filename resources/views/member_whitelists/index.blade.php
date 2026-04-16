@extends('layouts.app')
@section('title', 'Bílá listina' . ($memberName ? ' — ' . $memberName : ''))
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('member_whitelists.index') }}">Bílá listina</a>
    @if($memberName) &raquo; {{ $memberName }} @endif
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Bílá listina{{ $memberName ? ' — ' . $memberName : '' }}</h2></div>
<div class="m-subtitle">Celkem: {{ $whitelists->total() }} záznamů</div>

@if($memberId && $canAdd)
<div class="m-actions">
    <a class="m-btn m-btn-success" href="{{ route('member_whitelists.create', $memberId) }}">+ Přidat záznam</a>
</div>
@endif

<div style="margin-bottom:8px">{{ $whitelists->links() }}</div>

<div class="m-card" style="padding:0;overflow-x:auto">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr>
            <th style="width:50px">ID</th>
            <th>Člen</th>
            <th style="width:90px">Permanentní</th>
            <th style="width:100px">Od</th>
            <th style="width:100px">Do</th>
            <th style="width:70px">Aktivní</th>
            <th>Komentář</th>
            <th style="width:90px">Akce</th>
        </tr>
    </thead>
    <tbody>
        @forelse($whitelists as $wl)
        @php
            $today  = now()->toDateString();
            $active = $wl->since <= $today && ($wl->until === '9999-12-31' || $wl->until >= $today);
            $untilDisplay = $wl->until === '9999-12-31' ? 'trvale' : $wl->until;
        @endphp
        <tr>
            <td>{{ $wl->id }}</td>
            <td><a class="m-link" href="{{ route('members.show', $wl->member_id) }}">{{ $wl->member_name }}</a></td>
            <td>@if($wl->permanent)<span class="m-tag m-tag-green">Ano</span>@else —@endif</td>
            <td style="font-size:12px">{{ $wl->since }}</td>
            <td style="font-size:12px">{{ $untilDisplay }}</td>
            <td>@if($active)<span class="m-tag m-tag-green">Ano</span>@else <span class="m-tag m-tag-gray">Ne</span>@endif</td>
            <td style="font-size:12px">{{ $wl->comment ?? '—' }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    @if($canEdit) <a class="m-link-sm" href="{{ route('member_whitelists.edit', $wl->id) }}">Upravit</a> @endif
                    @if($canDelete)
                    <form method="POST" action="{{ route('member_whitelists.destroy', $wl->id) }}" style="display:inline">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:12px;color:#c0392b"
                                onclick="return confirm('Smazat whitelist záznam?')">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="8" style="text-align:center;color:#aaa;padding:2rem">Žádné záznamy.</td></tr>
        @endforelse
    </tbody>
</table>
</div>

<div style="margin-top:12px">{{ $whitelists->links() }}</div>
</div>
@endsection
