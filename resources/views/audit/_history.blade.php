{{--
    Znovupoužitelný výpis audit trailu (kdo/co/kdy).
    Očekává proměnnou $entries: kolekci řádků audit_logs s aliasy
    actor_name / actor_login (leftJoin users). Voláno např. z members.show.
--}}
@php
    $auditLabels = [
        'created' => 'Vytvoření', 'updated' => 'Úprava', 'deleted' => 'Smazání',
        'notified' => 'Notifikace', 'fee_deduction' => 'Stržení poplatků',
        'backcharge' => 'Dobírka', 'auto_former' => 'Auto: bývalý člen',
        'auto_pending_termination' => 'Auto: k ukončení',
        'device_removed' => 'Smazání zařízení',
    ];
    $auditColors = [
        'created' => ['#1a7f37', '#e6f4ea'],
        'updated' => ['#9a6700', '#fff8e1'],
        'deleted' => ['#b02a37', '#fdecef'],
        'device_removed' => ['#b02a37', '#fdecef'],
    ];
    // Vlastní akce (ne create/update/delete) → neutrální modrý badge.
    $auditNeutral = ['#1f5fbf', '#e8f0fe'];
    // Naformátuje hodnotu do čitelného řetězce (skalár / pole / null).
    $fmtVal = function ($v) {
        if ($v === null) return '∅';
        if (is_bool($v)) return $v ? 'ano' : 'ne';
        if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($v === '') return '(prázdné)';
        return (string) $v;
    };
@endphp

@php $collapsible = $collapsible ?? false; @endphp
@if($collapsible)
<details style="margin-bottom:16px">
    <summary style="cursor:pointer;font-weight:600;font-size:16px;color:#333;padding:8px 0;user-select:none">
        Historie změn @if($entries->isNotEmpty())<span style="color:#999;font-weight:400;font-size:14px">({{ $entries->count() }})</span>@endif
    </summary>
    <div class="m-card" style="margin-top:8px;margin-bottom:0">
@else
    @if(($showHeading ?? true))
    <div class="m-section">Historie změn</div>
    @endif
    <div class="m-card" style="margin-bottom:16px">
@endif
    @if($entries->isEmpty())
        <div style="font-size:15px;color:#888;padding:4px 0">Žádné zaznamenané změny.</div>
    @else
    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:14px">
            <thead>
                <tr style="text-align:left;color:#666;border-bottom:1px solid #eee">
                    <th style="padding:6px 8px;white-space:nowrap">Kdy</th>
                    <th style="padding:6px 8px;white-space:nowrap">Kdo</th>
                    <th style="padding:6px 8px;white-space:nowrap">IP</th>
                    <th style="padding:6px 8px;white-space:nowrap">Objekt</th>
                    <th style="padding:6px 8px;white-space:nowrap">Akce</th>
                    <th style="padding:6px 8px">Změny</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entries as $e)
                    @php
                        $old = $e->old_values ? json_decode($e->old_values, true) : [];
                        $new = $e->new_values ? json_decode($e->new_values, true) : [];
                        $keys = array_keys(($old ?? []) + ($new ?? []));
                        [$fg, $bg] = $auditColors[$e->action] ?? $auditNeutral;
                        $label = $auditLabels[$e->action] ?? $e->action;
                        $actor = trim($e->actor_name ?? '') !== ''
                            ? trim($e->actor_name) . ($e->actor_login ? " ({$e->actor_login})" : '')
                            : ($e->user_id ? "uživatel #{$e->user_id}" : 'systém / cron');
                    @endphp
                    <tr style="border-bottom:1px solid #f2f2f2;vertical-align:top">
                        <td style="padding:6px 8px;white-space:nowrap;color:#444">
                            {{ \Illuminate\Support\Carbon::parse($e->occurred_at)->format('d.m.Y H:i') }}
                        </td>
                        <td style="padding:6px 8px;white-space:nowrap">{{ $actor }}</td>
                        <td style="padding:6px 8px;white-space:nowrap;color:#888">{{ $e->ip_address ?? '—' }}</td>
                        <td style="padding:6px 8px;white-space:nowrap;color:#888">
                            {{ $e->auditable_type }}{{ $e->auditable_id ? " #{$e->auditable_id}" : '' }}
                        </td>
                        <td style="padding:6px 8px;white-space:nowrap">
                            <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600;color:{{ $fg }};background:{{ $bg }}">
                                {{ $label }}
                            </span>
                        </td>
                        <td style="padding:6px 8px">
                            @if(empty($keys))
                                <span style="color:#aaa">—</span>
                            @else
                                @foreach($keys as $k)
                                    @php
                                        $hasOld = is_array($old) && array_key_exists($k, $old);
                                        $hasNew = is_array($new) && array_key_exists($k, $new);
                                    @endphp
                                    <div style="margin:1px 0">
                                        <span style="color:#666">{{ $k }}:</span>
                                        @if(!$hasOld && $hasNew)
                                            <span style="color:#1a7f37">{{ $fmtVal($new[$k]) }}</span>
                                        @elseif($hasOld && !$hasNew)
                                            <span style="color:#b02a37;text-decoration:line-through">{{ $fmtVal($old[$k]) }}</span>
                                        @else
                                            <span style="color:#b02a37;text-decoration:line-through">{{ $fmtVal($old[$k]) }}</span>
                                            <span style="color:#999">→</span>
                                            <span style="color:#1a7f37">{{ $fmtVal($new[$k]) }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    </div>
@if($collapsible)
</details>
@endif
