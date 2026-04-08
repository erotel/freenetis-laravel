<form method="POST" action="{{ $action }}">
    @csrf
    @if($method === 'PUT') @method('PUT') @endif

    <table class="extended" cellspacing="0">
        <tr>
            <th>Název</th>
            <td>
                <input type="text" name="name" value="{{ old('name', $message?->name) }}" style="width:300px">
                @error('name') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th>Zrušení členem</th>
            <td>
                <select name="self_cancel">
                    <option value="0" {{ old('self_cancel', $message?->self_cancel) == 0 ? 'selected' : '' }}>Zakázáno</option>
                    <option value="1" {{ old('self_cancel', $message?->self_cancel) == 1 ? 'selected' : '' }}>Člen může zrušit</option>
                    <option value="2" {{ old('self_cancel', $message?->self_cancel) == 2 ? 'selected' : '' }}>IP adresa může zrušit</option>
                </select>
            </td>
        </tr>
        <tr>
            <th>Ignorovat whitelist</th>
            <td>
                <input type="checkbox" name="ignore_whitelist" value="1"
                    {{ old('ignore_whitelist', $message?->ignore_whitelist) ? 'checked' : '' }}>
            </td>
        </tr>
        <tr>
            <th style="vertical-align:top; padding-top:6px;">Text přesměrování<br><small>(HTML)</small></th>
            <td>
                <textarea name="text" rows="6" style="width:500px">{{ old('text', $message?->text) }}</textarea>
            </td>
        </tr>
        <tr>
            <th style="vertical-align:top; padding-top:6px;">Text emailu<br><small>(HTML)</small></th>
            <td>
                <textarea name="email_text" rows="6" style="width:500px">{{ old('email_text', $message?->email_text) }}</textarea>
            </td>
        </tr>
        <tr>
            <th style="vertical-align:top; padding-top:6px;">Text SMS<br><small>(max 760 znaků)</small></th>
            <td>
                <textarea name="sms_text" rows="3" style="width:500px" maxlength="760">{{ old('sms_text', $message?->sms_text) }}</textarea>
                @error('sms_text') <span style="color:red">{{ $message }}</span> @enderror
            </td>
        </tr>
    </table>

    <div style="margin-top:1em;">
        <button type="submit">Uložit</button>
        <a href="{{ route('messages.index') }}" style="margin-left:1em;">Zrušit</a>
    </div>
</form>
