<form method="POST" action="{{ $action }}">
@csrf
@if($method === 'PUT') @method('PUT') @endif

<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div class="m-form-group">
        <label class="m-form-label">Typ spouštění</label>
        <select class="m-form-select" name="type" id="rule-type" onchange="updateAttributeHelp()">
            @foreach($typeLabels as $val => $label)
                <option value="{{ $val }}" {{ old('type', $rule?->type) == $val ? 'selected' : '' }}>
                    {{ $label }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="rule-attribute">Atribut</label>
        <input class="m-form-input" type="text" name="attribute"
               value="{{ old('attribute', $rule?->attribute) }}"
               id="rule-attribute" style="max-width:120px;font-family:monospace">
        <div class="m-form-hint" id="attribute-help"></div>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="redirection_enabled" value="1"
                {{ old('redirection_enabled', $rule?->redirection_enabled ?? 0) ? 'checked' : '' }}>
            Přesměrování
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="email_enabled" value="1"
                {{ old('email_enabled', $rule?->email_enabled ?? 0) ? 'checked' : '' }}>
            E-mail
        </label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
            <input type="checkbox" name="sms_enabled" value="1"
                {{ old('sms_enabled', $rule?->sms_enabled ?? 0) ? 'checked' : '' }}>
            SMS
        </label>
    </div>
    <div class="m-form-group">
        <label class="m-form-label">Nahlásit na email</label>
        <input class="m-form-input" type="text" name="send_activation_to_email"
               value="{{ old('send_activation_to_email', $rule?->send_activation_to_email) }}"
               placeholder="admin@example.com">
        <div class="m-form-hint">Při aktivaci zprávy pošle report na tuto adresu.</div>
    </div>
</div>

<div class="m-actions">
    <button class="m-btn m-btn-primary" type="submit">Uložit</button>
    <a class="m-btn" href="{{ route('messages.show', request()->route('messageId')) }}">Zrušit</a>
</div>
</form>

<script>
const attributeHelp = {
    1: 'Formát DD/H (den v měsíci / hodina) — např. 26/0 = 26. den v 0:00',
    2: 'Formát D/H (den týdne 1-7 / hodina) — např. 1/8 = pondělí v 8:00',
    3: 'Formát /H (hodina) — např. /0 = každý den v 0:00',
    4: 'Formát /H (hodina, pouze pracovní dny) — např. /9 = v 9:00',
    5: 'Bez atributu — spouští každou hodinu',
    6: 'Formát /H (hodina v den stržení) — např. /2 = v den stržení v 2:00',
};
function updateAttributeHelp() {
    const type = document.getElementById('rule-type').value;
    document.getElementById('attribute-help').textContent = attributeHelp[type] || '';
}
document.addEventListener('DOMContentLoaded', updateAttributeHelp);
</script>
