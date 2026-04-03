<ul id="main-menu">
    @foreach($groups as $group)
        <li class="menu-group">
            <span class="menu-group-label">{{ $group['label'] }}</span>
            <ul>
                @foreach($group['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}"
                           class="{{ ($item['current'] ?? false) ? 'bold' : '' }}">{{ $item['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </li>
    @endforeach
</ul>
