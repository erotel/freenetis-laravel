<style>
#main-menu { list-style:none; margin:0; padding:0; }
#main-menu .menu-group { margin-bottom:4px; }
#main-menu .menu-group-label {
    display:block;
    font-size:10px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:#b87333;
    padding:10px 10px 4px;
}
#main-menu .menu-group > ul { list-style:none; margin:0; padding:0; }
#main-menu .menu-group > ul > li { margin:0; }
#main-menu .menu-group > ul > li > a {
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:5px 10px;
    font-size:13px;
    color:#333;
    text-decoration:none;
    border-radius:4px;
    transition:background .1s;
}
#main-menu .menu-group > ul > li > a:hover { background:#f0ede8; color:#111; }
#main-menu .menu-group > ul > li > a.bold { font-weight:600; color:#c0392b; }
.m-counter {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:18px;
    height:18px;
    padding:0 5px;
    font-size:11px;
    font-weight:600;
    border-radius:9px;
    line-height:1;
}
.m-counter-active { background:#c0392b; color:#fff; }
.m-counter-zero   { background:#ddd; color:#888; }
</style>

<ul id="main-menu">
    @foreach($groups as $group)
    <li class="menu-group">
        <span class="menu-group-label">{{ $group['label'] }}</span>
        <ul>
            @foreach($group['items'] as $item)
            <li>
                <a href="{{ $item['url'] }}"
                   class="{{ ($item['current'] ?? false) ? 'bold' : '' }}">
                    <span>{{ $item['label'] }}</span>
                    @if(!empty($item['count']))
                        <span class="m-counter m-counter-active">{{ $item['count'] }}</span>
                    @elseif(isset($item['count']) && $item['count'] === 0)
                        <span class="m-counter m-counter-zero">0</span>
                    @endif
                </a>
            </li>
            @endforeach
        </ul>
    </li>
    @endforeach
</ul>
