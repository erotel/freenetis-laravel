@extends('layouts.app')
@section('title', 'Hromadné notifikace — výběr zprávy')
@section('menu') <x-freenetis-menu /> @endsection
@section('breadcrumbs')
<div id="breadcrumbs">
    <a href="{{ route('members.index') }}">Zákazníci</a> &raquo; Hromadné notifikace
</div>
@endsection
@section('content')
<div class="m-page">
<div class="m-title-row"><h2>Hromadné notifikace</h2></div>

<div class="m-card" style="max-width:480px;padding:20px 1.5rem">
    <form method="GET" action="" id="msg-select-form">
        <div class="m-form-label">Vyberte zprávu</div>
        <select class="m-form-select" name="message_id" style="width:100%;margin-bottom:16px" required>
            <option value="">— vyberte zprávu —</option>
            @foreach($messages as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <button type="submit" class="m-btn m-btn-primary">Pokračovat</button>
    </form>
</div>
</div>

<script>
document.getElementById('msg-select-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var id = this.querySelector('[name=message_id]').value;
    if (id) window.location = '{{ url("notifications/members") }}/' + id;
});
</script>
@endsection
