<form method="POST" action="{{ $action }}">
    @csrf
    @if($method === 'PUT') @method('PUT') @endif

    <table class="extended" cellspacing="0">
        <tr>
            <th>Typ spouštění</th>
            <td>
                <select name="type" id="rule-type" onchange="updateAttributeHelp()">
                    @foreach($typeLabels as $val => $label)
                        <option value="{{ $val }}"
                            {{ old('type', $rule?->type) == $val ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </td>
        </tr>
        <tr>
            <th>Atribut</th>
            <td>
                <input type="text" name="attribute"
                       value="{{ old('attribute', $rule?->attribute) }}"
                       style="width:100px" id="rule-attribute">
                <small id="attribute-help" style="color:#888;"></small>
            </td>
        </tr>
        <tr>
            <th>Přesměrování</th>
            <td>
                <input type="checkbox" name="redirection_enabled" value="1"
                    {{ old('redirection_enabled', $rule?->redirection_enabled ?? 0) ? 'checked' : '' }}>
            </td>
        </tr>
        <tr>
            <th>E-mail</th>
            <td>
                <input type="checkbox" name="email_enabled" value="1"
                    {{ old('email_enabled', $rule?->email_enabled ?? 0) ? 'checked' : '' }}>
            </td>
        </tr>
        <tr>
            <th>SMS</th>
            <td>
                <input type="checkbox" name="sms_enabled" value="1"
                    {{ old('sms_enabled', $rule?->sms_enabled ?? 0) ? 'checked' : '' }}>
            </td>
        </tr>
        <tr>
            <th>Nahlásit na email</th>
            <td>
                <input type="text" name="send_activation_to_email"
                       value="{{ old('send_activation_to_email', $rule?->send_activation_to_email) }}"
                       style="width:250px"
                       placeholder="admin@example.com">
                <small style="color:#888;">Při aktivaci zprávy pošle report na tuto adresu.</small>
            </td>
        </tr>
    </table>

    <div style="margin-top:1em;">
        <button type="submit">Uložit</button>
        <a href="{{ route('messages.show', request()->route('messageId')) }}" style="margin-left:1em;">Zrušit</a>
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
