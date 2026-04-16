<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-group">
        <label class="m-form-label" for="fee_id">Tarif <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" id="fee_id" name="fee_id">
            <option value="">— vyberte tarif —</option>
            @foreach($typeLabels as $typeId => $typeLabel)
                @php $group = $fees->where('type_id', $typeId); @endphp
                @if($group->isNotEmpty())
                    <optgroup label="{{ $typeLabel }}">
                        @foreach($group as $fee)
                            <option value="{{ $fee->id }}" @selected(old('fee_id', $memberFee?->fee_id) == $fee->id)>
                                {{ $fee->name ?: ('Tarif #' . $fee->id) }}
                                ({{ number_format($fee->fee, 2, ',', ' ') }} Kč)
                            </option>
                        @endforeach
                    </optgroup>
                @endif
            @endforeach
        </select>
        @error('fee_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="activation_date">Datum aktivace <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" id="activation_date" name="activation_date"
                   value="{{ old('activation_date', $memberFee?->activation_date?->format('Y-m-d') ?? date('Y-m-d')) }}">
            @error('activation_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="deactivation_date">Datum deaktivace</label>
            <input class="m-form-input" type="date" id="deactivation_date" name="deactivation_date"
                   value="{{ old('deactivation_date', $memberFee && $memberFee->deactivation_date?->year < 9999 ? $memberFee->deactivation_date->format('Y-m-d') : '') }}">
            <div class="m-form-hint">Prázdné = neomezené trvání</div>
            @error('deactivation_date') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="comment">Komentář</label>
        <textarea class="m-form-input" id="comment" name="comment" rows="3" maxlength="1000">{{ old('comment', $memberFee?->comment) }}</textarea>
        @error('comment') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
</div>
