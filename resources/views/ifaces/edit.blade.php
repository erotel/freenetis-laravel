@extends('layouts.app')

@section('title', 'Upravit rozhraní: ' . $iface->name)

@section('menu')
    <x-freenetis-menu />
@endsection

@section('breadcrumbs')
    <div id="breadcrumbs">
        <a href="{{ route('devices.index') }}">Zařízení</a> &raquo;
        <a href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a> &raquo;
        <a href="{{ route('ifaces.show', $iface->id) }}">Rozhraní {{ $iface->name }}</a> &raquo;
        Upravit
    </div>
@endsection

@section('content')
    <h2>Upravit rozhraní: {{ $iface->name }}</h2>

    @if(session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif

    <form method="POST" action="{{ route('ifaces.update', $iface->id) }}" class="form">
        @csrf
        @method('PUT')

        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th>Typ</th>
                    <td>{{ $iface->typeLabelAttribute }}</td>
                </tr>
                <tr>
                    <th>Zařízení</th>
                    <td><a href="{{ route('devices.show', $iface->device_id) }}">{{ $iface->device->name }}</a></td>
                </tr>
                <tr>
                    <th><label for="name">Název <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="name" name="name"
                               value="{{ old('name', $iface->name) }}" maxlength="254">
                        @error('name') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="mac">MAC adresa</label></th>
                    <td>
                        <input type="text" id="mac" name="mac"
                               value="{{ old('mac', $iface->mac) }}" maxlength="20"
                               placeholder="aa:bb:cc:dd:ee:ff">
                        <br><small>Formát: aa:bb:cc:dd:ee:ff</small>
                        @error('mac') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="comment">Komentář</label></th>
                    <td>
                        <textarea id="comment" name="comment" maxlength="254">{{ old('comment', $iface->comment) }}</textarea>
                        @error('comment') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        {{-- Existing IP addresses --}}
        @if($iface->ipAddresses->count() > 0)
            <h3>IP adresy</h3>
            <table class="extended" cellspacing="0">
                <thead>
                    <tr>
                        <th>IP adresa</th>
                        <th>Podsíť</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($iface->ipAddresses as $n => $ip)
                        <input type="hidden" name="ip_id_{{ $n }}" value="{{ $ip->id }}">
                        <tr>
                            <td>
                                <input type="text" name="ip_address_{{ $n }}"
                                       value="{{ old("ip_address_{$n}", $ip->ip_address) }}"
                                       placeholder="10.0.0.1">
                                @error("ip_address_{$n}") <span class="error">{{ $message }}</span> @enderror
                            </td>
                            <td>
                                <select name="ip_subnet_{{ $n }}">
                                    <option value="">— vyberte podsíť —</option>
                                    @foreach($subnets as $subnet)
                                        <option value="{{ $subnet->id }}"
                                            @selected(old("ip_subnet_{$n}", $ip->subnet_id) == $subnet->id)>
                                            {{ $subnet->label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error("ip_subnet_{$n}") <span class="error">{{ $message }}</span> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{-- Add new IP --}}
        <h3>Přidat IP adresu</h3>
        <table class="extended" cellspacing="0">
            <tbody>
                <tr>
                    <th><label for="new_ip_address">IP adresa</label></th>
                    <td>
                        <input type="text" id="new_ip_address" name="new_ip_address"
                               value="{{ old('new_ip_address') }}" placeholder="10.0.0.1">
                        @error('new_ip_address') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
                <tr>
                    <th><label for="new_ip_subnet">Podsíť</label></th>
                    <td>
                        <select id="new_ip_subnet" name="new_ip_subnet">
                            <option value="">— vyberte podsíť —</option>
                            @foreach($subnets as $subnet)
                                <option value="{{ $subnet->id }}"
                                    @selected(old('new_ip_subnet') == $subnet->id)>
                                    {{ $subnet->label }}
                                </option>
                            @endforeach
                        </select>
                        @error('new_ip_subnet') <span class="error">{{ $message }}</span> @enderror
                    </td>
                </tr>
            </tbody>
        </table>

        <p><input type="submit" value="Uložit"></p>
    </form>

    <script>
    const subnetData = @json($subnetData ?? []);

    function ipToLong(ip) {
        return ip.split('.').reduce((acc, oct) => (acc << 8) + parseInt(oct), 0) >>> 0;
    }

    function findSubnetForIp(ip) {
        const ipLong = ipToLong(ip);
        return subnetData.find(s => {
            const network = ipToLong(s.network);
            const mask = ipToLong(s.mask);
            return (ipLong & mask) === (network & mask);
        });
    }

    function setupIpAutocomplete(ipInput, subnetSelect) {
        if (!ipInput) return;

        const allFreeIps = [];
        subnetData.forEach(s => s.freeIps.forEach(ip => allFreeIps.push(ip)));

        const listId = 'datalist_' + ipInput.name.replace(/[^a-z0-9]/gi, '_');
        let datalist = document.getElementById(listId);
        if (!datalist) {
            datalist = document.createElement('datalist');
            datalist.id = listId;
            document.body.appendChild(datalist);
        }
        ipInput.setAttribute('list', listId);
        ipInput.setAttribute('autocomplete', 'off');

        ipInput.addEventListener('input', function () {
            const val = this.value;
            datalist.innerHTML = '';
            if (val.length < 3) return;
            allFreeIps.filter(ip => ip.startsWith(val)).slice(0, 10).forEach(ip => {
                const opt = document.createElement('option');
                opt.value = ip;
                datalist.appendChild(opt);
            });
        });

        function autofillSubnet() {
            const ip = ipInput.value;
            if (!/^\d+\.\d+\.\d+\.\d+$/.test(ip)) return;
            const subnet = findSubnetForIp(ip);
            if (subnet && subnetSelect) {
                subnetSelect.value = subnet.id;
            }
        }

        ipInput.addEventListener('change', autofillSubnet);
        ipInput.addEventListener('blur', autofillSubnet);
    }

    // Existing IP address fields
    document.querySelectorAll('input[name^="ip_address_"]').forEach(ipInput => {
        const n = ipInput.name.replace('ip_address_', '');
        const subnetSelect = document.querySelector('select[name="ip_subnet_' + n + '"]');
        setupIpAutocomplete(ipInput, subnetSelect);
    });

    // New IP address field
    setupIpAutocomplete(
        document.getElementById('new_ip_address'),
        document.getElementById('new_ip_subnet')
    );
    </script>
@endsection
