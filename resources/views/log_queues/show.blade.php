@extends('layouts.app')

@section('title', 'Chyba / log #' . $log->id)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('log_queues.index') }}">Chyby a logy</a> &raquo;
        {{ $log->typeName() }} ({{ $log->id }})
    </div>
@endsection

@section('content')
    <h2>Chyba / log #{{ $log->id }}</h2>

    @if(session('success'))
        <div class="message_ok"><p>{{ session('success') }}</p></div>
    @endif
    @if(session('error'))
        <div class="message_error"><p>{{ session('error') }}</p></div>
    @endif

    <p>
        @if($canEdit && $log->state == \App\Models\LogQueue::STATE_NEW)
            <form method="POST" action="{{ route('log_queues.close', $log->id) }}" style="display:inline">
                @csrf
                <button type="submit">Uzavřít</button>
            </form>
        @endif
    </p>

    <table class="extended" cellspacing="0">
        <tbody>
            <tr>
                <th>ID</th>
                <td>{{ $log->id }}</td>
            </tr>
            <tr>
                <th>Typ</th>
                <td>
                    <b style="color:white; padding:2px 5px; border-radius:3px; background-color:{{ $log->typeColor() }}">
                        {{ $log->typeName() }}
                    </b>
                </td>
            </tr>
            <tr>
                <th>Zaznamenáno</th>
                <td>{{ $log->created_at }}</td>
            </tr>
            <tr>
                <th>Popis</th>
                <td style="white-space:pre-wrap">{{ $log->description }}</td>
            </tr>
            @if($log->exception_backtrace)
                <tr>
                    <th>{{ $log->type == \App\Models\LogQueue::TYPE_INFO ? 'Zpráva' : 'Výjimka' }}</th>
                    <td>
                        <pre style="font-size:12px; background:#f5f5f5; padding:8px; overflow-x:auto; white-space:pre-wrap; word-break:break-all">{{ $log->exception_backtrace }}</pre>
                    </td>
                </tr>
            @endif
            <tr>
                <th>Stav</th>
                <td>
                    @if($log->state == \App\Models\LogQueue::STATE_NEW)
                        <b style="color:#c00">Nový</b>
                    @else
                        <b>Uzavřený</b>
                    @endif
                </td>
            </tr>
            @if($log->closed_by_user_id)
                <tr>
                    <th>Uzavřel</th>
                    <td>
                        {{ $closedByUser ? $closedByUser->name . ' ' . $closedByUser->surname : '#' . $log->closed_by_user_id }}
                        ({{ $log->closed_at }})
                    </td>
                </tr>
            @endif
        </tbody>
    </table>

    <br>
    <h3>Komentáře</h3>

    @if($canAddComment)
        @if($log->comments_thread_id)
            <p><a href="{{ route('comments.add', $log->comments_thread_id) }}">Přidat komentář</a></p>
        @else
            <p><a href="{{ route('comments.add-thread', ['type' => 'log_queue', 'fkId' => $log->id]) }}">Přidat komentář</a></p>
        @endif
    @endif

    @if($comments->isNotEmpty())
        <table class="extended" cellspacing="0">
            <thead>
                <tr>
                    <th>Komentář</th>
                    <th>Uživatel</th>
                    <th>Čas</th>
                    <th>Akce</th>
                </tr>
            </thead>
            <tbody>
                @foreach($comments as $comment)
                    <tr>
                        <td style="white-space:pre-wrap">{{ $comment->text }}</td>
                        <td>{{ $comment->user_name }}</td>
                        <td>{{ $comment->datetime }}</td>
                        <td>
                            @if($canEditComment)
                                <a href="{{ route('comments.edit', $comment->id) }}">Upravit</a>
                            @endif
                            @if($canDeleteComment)
                                @if($canEditComment) | @endif
                                <form method="POST" action="{{ route('comments.destroy', $comment->id) }}" style="display:inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;color:#00c;text-decoration:underline"
                                            onclick="return confirm('Smazat komentář?')">Smazat</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Žádné komentáře.</p>
    @endif
@endsection
