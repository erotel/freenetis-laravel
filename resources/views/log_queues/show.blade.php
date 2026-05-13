@extends('layouts.app')
@section('title', 'Chyba / log #' . $log->id)
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('log_queues.index') }}">Chyby a logy</a> &raquo;
    {{ $log->typeName() }} (#{{ $log->id }})
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Chyba / log #{{ $log->id }}</h2></div>

@if($canEdit && $log->state == \App\Models\LogQueue::STATE_NEW)
<div class="m-actions">
    <form method="POST" action="{{ route('log_queues.close', $log->id) }}" style="display:inline">
        @csrf
        <button class="m-btn m-btn-primary" type="submit">Uzavřít</button>
    </form>
</div>
@endif

<div class="m-grid2">
    <div class="m-card">
        <div class="m-card-title">Informace o záznamu</div>
        <div class="m-field"><span class="m-field-label">ID</span><span class="m-field-value">{{ $log->id }}</span></div>
        <div class="m-field">
            <span class="m-field-label">Typ</span>
            <span class="m-field-value">
                <span style="background:{{ $log->typeColor() }};color:#fff;font-size:13px;padding:2px 7px;border-radius:10px">
                    {{ $log->typeName() }}
                </span>
            </span>
        </div>
        <div class="m-field"><span class="m-field-label">Zaznamenáno</span><span class="m-field-value" style="font-size:14px">{{ $log->created_at }}</span></div>
        <div class="m-field">
            <span class="m-field-label">Stav</span>
            <span class="m-field-value">
                @if($log->state == \App\Models\LogQueue::STATE_NEW)
                    <span class="m-tag m-tag-red">Nový</span>
                @else
                    <span class="m-tag m-tag-gray">Uzavřený</span>
                @endif
            </span>
        </div>
        @if($log->closed_by_user_id)
        <div class="m-field">
            <span class="m-field-label">Uzavřel</span>
            <span class="m-field-value" style="font-size:14px">
                {{ $closedByUser ? $closedByUser->name . ' ' . $closedByUser->surname : '#' . $log->closed_by_user_id }}
                ({{ $log->closed_at }})
            </span>
        </div>
        @endif
    </div>
    <div></div>
</div>

<div class="m-section">Popis</div>
<div class="m-card" style="margin-bottom:16px">
    <pre style="font-size:16px;margin:0;white-space:pre-wrap;word-break:break-word">{{ $log->description }}</pre>
</div>

@if($log->exception_backtrace)
<div class="m-section">{{ $log->type == \App\Models\LogQueue::TYPE_INFO ? 'Zpráva' : 'Výjimka' }}</div>
<div class="m-card" style="margin-bottom:16px;padding:10px">
    <pre style="font-size:14px;margin:0;overflow-x:auto;white-space:pre-wrap;word-break:break-all;background:var(--fn-quote-bg);color:var(--fn-text);padding:8px;border-radius:4px">{{ $log->exception_backtrace }}</pre>
</div>
@endif

<div class="m-section">
    Komentáře
    @if($canAddComment)
    @if($log->comments_thread_id)
    <a class="m-link-sm" href="{{ route('comments.add', $log->comments_thread_id) }}" style="margin-left:10px">+ Přidat komentář</a>
    @else
    <a class="m-link-sm" href="{{ route('comments.add-thread', ['type' => 'log_queue', 'fkId' => $log->id]) }}" style="margin-left:10px">+ Přidat komentář</a>
    @endif
    @endif
</div>

@if($comments->isNotEmpty())
<div class="m-card" style="padding:0">
<table class="m-table" style="margin-bottom:0">
    <thead>
        <tr><th>Komentář</th><th style="width:120px">Uživatel</th><th style="width:140px">Čas</th><th style="width:80px">Akce</th></tr>
    </thead>
    <tbody>
        @foreach($comments as $comment)
        <tr>
            <td style="white-space:pre-wrap;font-size:16px">{{ $comment->text }}</td>
            <td style="font-size:14px">{{ $comment->user_name }}</td>
            <td style="font-size:14px">{{ $comment->datetime }}</td>
            <td>
                <div style="display:flex;gap:6px">
                    @if($canEditComment) <a class="m-link-sm" href="{{ route('comments.edit', $comment->id) }}">Upravit</a> @endif
                    @if($canDeleteComment)
                    <form method="POST" action="{{ route('comments.destroy', $comment->id) }}" style="display:inline">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;cursor:pointer;padding:0;font-size:14px;color:#c0392b"
                                onclick="return confirm('Smazat komentář?')">Smazat</button>
                    </form>
                    @endif
                </div>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
</div>
@else
<div class="m-alert m-alert-info">Žádné komentáře.</div>
@endif

</div>
@endsection
