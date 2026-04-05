<table class="extended" cellspacing="0">
    <tbody>
        <tr>
            <th><label for="type_id">Typ <span class="required">*</span></label></th>
            <td>
                <select id="type_id" name="type_id">
                    @foreach($typeLabels as $typeId => $typeLabel)
                        <option value="{{ $typeId }}" @selected(old('type_id', $fee?->type_id) == $typeId)>
                            {{ $typeLabel }}
                        </option>
                    @endforeach
                </select>
                @error('type_id') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="name">Název</label></th>
            <td>
                <input type="text" id="name" name="name"
                       value="{{ old('name', $fee?->name) }}" maxlength="100">
                @error('name') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="fee">Poplatek (Kč) <span class="required">*</span></label></th>
            <td>
                <input type="number" id="fee" name="fee" step="0.01" min="0"
                       value="{{ old('fee', $fee?->fee) }}">
                @error('fee') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="from">Platnost od <span class="required">*</span></label></th>
            <td>
                <input type="date" id="from" name="from"
                       value="{{ old('from', $fee?->from?->format('Y-m-d') ?? date('Y-m-d')) }}">
                @error('from') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="to">Platnost do <span class="required">*</span></label></th>
            <td>
                <input type="date" id="to" name="to"
                       value="{{ old('to', $fee && $fee->to?->year < 9999 ? $fee->to->format('Y-m-d') : '9999-12-31') }}">
                @error('to') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
    </tbody>
</table>
