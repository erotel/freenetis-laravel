@if($errors->any())
<div class="m-alert m-alert-danger" style="margin-bottom:16px">
    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
</div>
@endif

<div class="m-card" style="margin-bottom:16px;max-width:520px">
    <div class="m-form-group">
        <label class="m-form-label" for="name">Jméno <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="name" name="name" maxlength="50"
               value="{{ old('name', $name ?? '') }}" required>
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="qos_ceil">Max. rychlost (Ceil) <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="qos_ceil" name="qos_ceil"
               value="{{ old('qos_ceil', $qos_ceil ?? '') }}"
               placeholder="250/50" required style="font-family:monospace">
        <div class="m-form-hint">Download/Upload v Mbps (např. <code>250/50</code>). Stejná hodnota pro oba směry: <code>250</code>. Podporované přípony: G, M (výchozí), K.</div>
        @error('qos_ceil') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="qos_rate">Min. rychlost (Rate) <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="text" id="qos_rate" name="qos_rate"
               value="{{ old('qos_rate', $qos_rate ?? '') }}"
               placeholder="50/10" required style="font-family:monospace">
        <div class="m-form-hint">Download/Upload v Mbps (např. <code>50/10</code>). Garantovaná minimální rychlost.</div>
        @error('qos_rate') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="price">Ceníková cena (Kč/měs)</label>
        <input class="m-form-input" type="text" id="price" name="price" inputmode="decimal"
               value="{{ old('price', $price ?? '') }}" placeholder="— (výchozí tarif)" style="max-width:180px">
        <div class="m-form-hint">Použije se při strhávání poplatku, když člen nemá individuální tarif. Prázdné = výchozí tarif dle typu člena. <code>0</code> = zdarma.</div>
        @error('price') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
