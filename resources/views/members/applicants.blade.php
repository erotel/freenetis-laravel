@extends('layouts.app')
@section('title', 'Čekatelé')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
    <div id="breadcrumbs"><a href="{{ route('members.index') }}">Členové</a> » Čekatelé</div>
@endsection
@section('content')
    <h2>Čekatelé</h2>

    @if($pending->isEmpty())
        <p>Žádní čekatelé.</p>
    @else
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Jméno</th>
                    <th>Typ</th>
                    <th>Datum vstupu</th>
                    <th>Přihláška</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pending as $m)
                    <tr>
                        <td>{{ $m->id }}</td>
                        <td><a href="{{ route('members.show', $m->id) }}">{{ $m->name }}</a></td>
                        <td>
                            @if($m->type == 17)
                                <span style="background:#e8f4e8; padding:2px 8px; border-radius:4px; font-size:0.85em;">Čekající člen</span>
                            @else
                                <span style="background:#e8f0ff; padding:2px 8px; border-radius:4px; font-size:0.85em;">Čekající zákazník</span>
                            @endif
                        </td>
                        <td>{{ $m->entrance_date }}</td>
                        <td>
                            @if($m->registration)
                                <span style="color:green;">✓ podepsána</span>
                            @else
                                <span style="color:red;">✗ nepodepsána</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('members.show', $m->id) }}">Detail</a>
                            | <a href="{{ route('members.edit', $m->id) }}">Upravit</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
