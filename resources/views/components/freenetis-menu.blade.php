<ul id="main-menu">
    @foreach($groups as $group)
        <li class="menu-group">
            <span class="menu-group-label">{{ $group['label'] }}</span>
            <ul>
                @foreach($group['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}"
                           class="{{ ($item['current'] ?? false) ? 'bold' : '' }}">{{ $item['label'] }}</a>
                        @if(!empty($item['count']))
                            <span class="menu_item_counter rbg">{{ $item['count'] }}</span>
                        @elseif(isset($item['count']) && $item['count'] === 0)
                            <span class="menu_item_counter">0</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </li>
    @endforeach
</ul>
