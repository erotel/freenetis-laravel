@if($errors->any())
    <div class="message error">
        @foreach($errors->all() as $e)
            <div>{{ $e }}</div>
        @endforeach
    </div>
@endif

<table class="extended" cellspacing="0">
    <tbody>
        <tr>
            <th><label for="name">Jméno <span class="required">*</span></label></th>
            <td>
                <input type="text" id="name" name="name" maxlength="50"
                       value="{{ old('name', $name ?? '') }}" required>
                @error('name') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="qos_ceil">Max. rychlost (Ceil) <span class="required">*</span></label></th>
            <td>
                <input type="text" id="qos_ceil" name="qos_ceil"
                       value="{{ old('qos_ceil', $qos_ceil ?? '') }}"
                       placeholder="250/50" required>
                <small>Download/Upload v Mbps (např. <code>250/50</code>).
                    Stejná hodnota pro oba směry: <code>250</code>.
                    Podporované přípony: G, M (výchozí), K.</small>
                @error('qos_ceil') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="qos_rate">Min. rychlost (Rate) <span class="required">*</span></label></th>
            <td>
                <input type="text" id="qos_rate" name="qos_rate"
                       value="{{ old('qos_rate', $qos_rate ?? '') }}"
                       placeholder="50/10" required>
                <small>Download/Upload v Mbps (např. <code>50/10</code>).
                    Garantovaná minimální rychlost.</small>
                @error('qos_rate') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
    </tbody>
</table>
