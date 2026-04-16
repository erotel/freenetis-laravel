<div class="m-card" style="margin-bottom:16px;max-width:480px">
    <div class="m-form-group">
        <label class="m-form-label" for="type_id">Typ <span style="color:#c0392b">*</span></label>
        <select class="m-form-select" id="type_id" name="type_id">
            @foreach($typeLabels as $typeId => $typeLabel)
                <option value="{{ $typeId }}" @selected(old('type_id', $fee?->type_id) == $typeId)>
                    {{ $typeLabel }}
                </option>
            @endforeach
        </select>
        @error('type_id') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="name">Název</label>
        <input class="m-form-input" type="text" id="name" name="name"
               value="{{ old('name', $fee?->name) }}" maxlength="100">
        @error('name') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-group">
        <label class="m-form-label" for="fee">Poplatek (Kč) <span style="color:#c0392b">*</span></label>
        <input class="m-form-input" type="number" id="fee" name="fee" step="0.01" min="0"
               value="{{ old('fee', $fee?->fee) }}" style="max-width:140px">
        @error('fee') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
    </div>
    <div class="m-form-row">
        <div class="m-form-group">
            <label class="m-form-label" for="from">Platnost od <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" id="from" name="from"
                   value="{{ old('from', $fee?->from?->format('Y-m-d') ?? date('Y-m-d')) }}">
            @error('from') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
        <div class="m-form-group">
            <label class="m-form-label" for="to">Platnost do <span style="color:#c0392b">*</span></label>
            <input class="m-form-input" type="date" id="to" name="to"
                   value="{{ old('to', $fee && $fee->to?->year < 9999 ? $fee->to->format('Y-m-d') : '9999-12-31') }}">
            @error('to') <div class="m-form-hint" style="color:#c0392b">{{ $message }}</div> @enderror
        </div>
    </div>
</div>
