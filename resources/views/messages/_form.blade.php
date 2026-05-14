<form method="POST" action="{{ $action }}">
@csrf
@if($method === 'PUT') @method('PUT') @endif

<div class="m-card" style="margin-bottom:16px;max-width:900px">
    <div class="m-form-group">
        <label class="m-form-label">Název</label>
        <input class="m-form-input" type="text" name="name" value="{{ old('name', $message?->name) }}">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Zrušení členem</label>
        <select class="m-form-select" name="self_cancel">
            <option value="0" {{ old('self_cancel', $message?->self_cancel) == 0 ? 'selected' : '' }}>Zakázáno</option>
            <option value="1" {{ old('self_cancel', $message?->self_cancel) == 1 ? 'selected' : '' }}>Člen může zrušit</option>
            <option value="2" {{ old('self_cancel', $message?->self_cancel) == 2 ? 'selected' : '' }}>IP adresa může zrušit</option>
        </select>
    </div>
    <div class="m-form-group">
        <label style="display:flex;align-items:center;gap:6px;font-size:16px;cursor:pointer">
            <input type="checkbox" name="ignore_whitelist" value="1"
                {{ old('ignore_whitelist', $message?->ignore_whitelist) ? 'checked' : '' }}>
            Ignorovat whitelist
        </label>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Text přesměrování <span style="font-weight:400;color:#aaa">(HTML)</span></label>
        <textarea class="m-form-input wysiwyg" name="text" rows="12">{{ old('text', $message?->text) }}</textarea>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Text emailu <span style="font-weight:400;color:#aaa">(HTML)</span></label>
        <textarea class="m-form-input wysiwyg" name="email_text" rows="12">{{ old('email_text', $message?->email_text) }}</textarea>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Text SMS <span style="font-weight:400;color:#aaa">(max 760 znaků)</span></label>
        <textarea class="m-form-input" name="sms_text" rows="3" maxlength="760">{{ old('sms_text', $message?->sms_text) }}</textarea>
        @error('sms_text') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('messages.index') }}">Zrušit</a>
</div>
</form>

{{-- TinyMCE 7 (GPL/community license) — náhrada za TinyMCE 3 v Kohaně.
     CDN bez API klíče (jsdelivr); license_key 'gpl' potvrzuje free GPL použití
     a tím vypne premium-feature notifikace. --}}
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: 'textarea.wysiwyg',
    license_key: 'gpl',
    height: 420,
    menubar: false,
    branding: false,
    promotion: false,
    language: 'cs',
    language_url: 'https://cdn.jsdelivr.net/npm/tinymce-i18n@latest/langs7/cs.js',
    convert_urls: false,
    entity_encoding: 'raw',
    plugins: 'lists link image table code fullscreen searchreplace charmap',
    toolbar: 'undo redo | styles | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist | link image table charmap | searchreplace code fullscreen',
});
</script>
