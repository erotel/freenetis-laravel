<table class="extended" cellspacing="0">
    <tbody>
        <tr>
            <th><label for="fee_id">Tarif <span class="required">*</span></label></th>
            <td>
                <select id="fee_id" name="fee_id">
                    <option value="">— vyberte tarif —</option>
                    @foreach($typeLabels as $typeId => $typeLabel)
                        @php $group = $fees->where('type_id', $typeId); @endphp
                        @if($group->isNotEmpty())
                            <optgroup label="{{ $typeLabel }}">
                                @foreach($group as $fee)
                                    <option value="{{ $fee->id }}"
                                        @selected(old('fee_id', $memberFee?->fee_id) == $fee->id)>
                                        {{ $fee->name ?: ('Tarif #' . $fee->id) }}
                                        ({{ number_format($fee->fee, 2, ',', ' ') }} Kč)
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                    @endforeach
                </select>
                @error('fee_id') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="activation_date">Datum aktivace <span class="required">*</span></label></th>
            <td>
                <input type="date" id="activation_date" name="activation_date"
                       value="{{ old('activation_date', $memberFee?->activation_date?->format('Y-m-d') ?? date('Y-m-d')) }}">
                @error('activation_date') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="deactivation_date">Datum deaktivace</label></th>
            <td>
                <input type="date" id="deactivation_date" name="deactivation_date"
                       value="{{ old('deactivation_date', $memberFee && $memberFee->deactivation_date?->year < 9999 ? $memberFee->deactivation_date->format('Y-m-d') : '') }}">
                <small>Ponechte prázdné pro neomezené trvání</small>
                @error('deactivation_date') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
        <tr>
            <th><label for="comment">Komentář</label></th>
            <td>
                <textarea id="comment" name="comment" maxlength="1000">{{ old('comment', $memberFee?->comment) }}</textarea>
                @error('comment') <span class="error">{{ $message }}</span> @enderror
            </td>
        </tr>
    </tbody>
</table>
