@extends('layouts.app')

@section('title', 'Bílá listina' . ($memberName ? ' — ' . $memberName : ''))

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('member_whitelists.index') }}">Bílá listina</a>
        @if($memberName)
            &raquo; {{ $memberName }}
        @endif
    </div>
@endsection

@section('content')
    <h2>Bílá listina{{ $memberName ? ' — ' . $memberName : '' }}</h2>

    @if(session('success'))
        <div class="message_ok"><p>{{ session('success') }}</p></div>
    @endif

    @if($memberId && $canAdd)
    <p><a href="{{ route('member_whitelists.create', $memberId) }}">+ Přidat záznam</a></p>
    @endif

    <table class="extended" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Člen</th>
                <th>Permanentní</th>
                <th>Od</th>
                <th>Do</th>
                <th>Aktivní</th>
                <th>Komentář</th>
                <th>Akce</th>
            </tr>
        </thead>
        <tbody>
            @forelse($whitelists as $wl)
                @php
                    $today   = now()->toDateString();
                    $active  = $wl->since <= $today && ($wl->until === '9999-12-31' || $wl->until >= $today);
                    $untilDisplay = $wl->until === '9999-12-31' ? 'trvale' : $wl->until;
                @endphp
                <tr>
                    <td>{{ $wl->id }}</td>
                    <td><a href="{{ route('members.show', $wl->member_id) }}">{{ $wl->member_name }}</a></td>
                    <td>{{ $wl->permanent ? 'Ano' : 'Ne' }}</td>
                    <td>{{ $wl->since }}</td>
                    <td>{{ $untilDisplay }}</td>
                    <td>
                        @if($active)
                            <span style="color:green">Ano</span>
                        @else
                            <span style="color:#aaa">Ne</span>
                        @endif
                    </td>
                    <td>{{ $wl->comment ?? '—' }}</td>
                    <td>
                        @if($canEdit)
                            <a href="{{ route('member_whitelists.edit', $wl->id) }}">Upravit</a>
                        @endif
                        @if($canDelete)
                            @if($canEdit) | @endif
                            <form method="POST" action="{{ route('member_whitelists.destroy', $wl->id) }}" style="display:inline">
                                @csrf @method('DELETE')
                                <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;color:#c00;text-decoration:underline"
                                        onclick="return confirm('Smazat whitelist záznam?')">Smazat</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center">Žádné záznamy.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:10px">{{ $whitelists->links() }}</div>
@endsection
