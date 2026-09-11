<?php

/**
 * Kea DHCP control — konfigurace pro komunikaci FreenetIS → Kea uzly.
 * Soubor je per-instance (mimo git): endpointy a creds se liší dev/produkce.
 */
return [
    // --- Host-cache flush po změně line-id rezervací (varianta A) ---
    // Kea si cachuje výsledek RADIUS lookupu (libdhcp_host_cache). Když se první
    // dotaz vyhodnotí špatně (okno cutoveru, rozjetá replikace, oprava dat),
    // negativní/pool verdikt v cache přežije reboot i renew zákazníka, dokud se
    // cache ručně nevyčistí. Proto po každé změně rezervací (reconcileFromSeen)
    // pošleme plošný `cache-clear` na control API všech Kea uzlů. Stejné endpointy
    // používá i čtení leasů (lease4-get-by-hw-address) při registraci přípojky.
    // Viz [[project_lineid_tr101_gotcha]], [[project_kea_ipoe_fiber]].

    // Produkce: Kea běží na samostatných uzlech → HTTP control API (basic auth).
    // Čárkou oddělené base-URL endpointů (v4 8000), např.
    // "http://10.133.230.22:8000,http://10.133.230.23:8000". Prázdné = HTTP
    // vypnuté (spadne na lokální socket níže, PoC/dev).
    'control_nodes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('KEA_CONTROL_NODES', ''))
    ))),
    'control_user'     => env('KEA_CONTROL_USER', 'ha'),
    'control_password' => env('KEA_CONTROL_PASSWORD', ''),
    'control_timeout'  => (int) env('KEA_CONTROL_TIMEOUT', 3),

    // Fallback pro lokální Keu (PoC/dev, Kea na stejném hostu jako FreenetIS):
    // unix control socket. Použije se jen když control_nodes je prázdné.
    'control_socket' => env('KEA_CONTROL_SOCKET', '/var/run/kea/kea4-ctrl.sock'),
];
