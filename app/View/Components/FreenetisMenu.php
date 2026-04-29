<?php

namespace App\View\Components;

use App\Models\Setting;
use App\Services\AclService;
use App\Services\ContractService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Component;

class FreenetisMenu extends Component
{
    public array $groups;

    public function __construct(AclService $acl)
    {
        $user = auth()->user();
        $userId = $user?->id ?? 0;
        $currentPath = request()->path();

        // Řadový člen/zákazník — zobrazit pouze "Můj profil"
        $isAdmin = $acl->hasAccess($userId, 'view_all', 'Members_Controller', 'members');
        if (!$isAdmin) {
            $this->groups = [
                ['name' => 'home', 'label' => 'Domů', 'items' => [
                    ['url' => route('members.show', $user?->member_id ?? 1), 'label' => 'Můj profil', 'current' => true, 'count' => null],
                ]],
            ];
            return;
        }

        // Badge counts (only query if user has access, lazy via closures resolved below)
        $countUnidentified = fn() => (int) DB::table('transfers as t')
            ->join('bank_transfers as bt', 'bt.transfer_id', '=', 't.id')
            ->join('accounts as a', 'a.id', '=', 't.origin_id')
            ->whereNull('t.member_id')
            ->where('a.account_attribute_id', 684000)
            ->count();

        $countDraftOutgoing = fn() => (int) DB::table('outgoing_payments')
            ->where('status', 'draft')
            ->count();

        $countApplicants  = fn() => (int) DB::table('members')->whereIn('type', [17, 18])->count();
        $countCustomers   = fn() => (int) DB::table('members')->where('type', 2)->count();
        $countRegular     = fn() => (int) DB::table('members')->where('type', 90)->count();
        $countLogErrors   = fn() => (int) DB::table('log_queues')->whereIn('type', [0, 1])->where('state', 0)->count();
        $countEmailUnsent            = fn() => (int) DB::table('email_queues')->where('state', 0)->count();
        $countSmsUnsent              = fn() => (int) DB::table('sms_messages')->where('type', 1)->where('state', 1)->count();
        $countConnectionRequests     = fn() => (int) DB::table('connection_requests')->where('state', 0)->count();
        $countUnsignedContracts = fn() => app(ContractService::class)->countUnsigned();

        $countDhcpErrors = fn() => (int) DB::table('devices as d')
            ->join('ifaces as i', 'i.device_id', '=', 'd.id')
            ->join('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
            ->where('ip.gateway', 1)
            ->where('ip.dhcp', 1)
            ->whereNotNull('d.access_time')
            ->where('d.access_time', '!=', '0000-00-00 00:00:00')
            ->where('d.access_time', '<', now()->subMinutes(5))
            ->distinct('d.id')
            ->count('d.id');

        $menuGroups = [
            ['name' => 'home', 'label' => 'Domů', 'items' => [
                ['url' => route('members.show', $user?->member_id ?? 1), 'path' => '', 'label' => 'Můj profil', 'acl' => null],
            ]],
            ['name' => 'members', 'label' => 'Uživatelé', 'items' => [
                ['url' => route('members.index', ['types' => '1,3,15,17,90']), 'path' => 'members', 'label' => 'Seznam členů', 'acl' => ['view_all', 'Members_Controller', 'members'], 'count' => $countRegular],
                ['url' => route('members.index', ['types' => '2,16,18']), 'path' => 'members', 'label' => 'Seznam zákazníků', 'acl' => ['view_all', 'Members_Controller', 'members'], 'count' => $countCustomers],
                ['url' => route('members.index', ['types' => '17,18']), 'path' => 'members/applicants', 'label' => 'Čekatelé', 'acl' => ['view_all', 'Members_Controller', 'members'], 'count' => $countApplicants],
                ['url' => route('users.index'), 'path' => 'users', 'label' => 'Uživatelé', 'acl' => ['view_all', 'Users_Controller', 'users']],
            ]],
            ['name' => 'network', 'label' => 'Síť', 'items' => array_filter([
                ['url' => route('devices.index'), 'path' => 'devices', 'label' => 'Zařízení', 'acl' => ['view_all', 'Devices_Controller', 'devices']],
                ['url' => route('devices.dhcp-servers'), 'path' => 'devices/dhcp-servers', 'label' => 'DHCP servery', 'acl' => ['view_all', 'Devices_Controller', 'devices'], 'count' => $countDhcpErrors],
                ['url' => route('ip_addresses.index'), 'path' => 'ip-addresses', 'label' => 'IP adresy', 'acl' => ['view_all', 'Ip_addresses_Controller', 'ip_address']],
                ['url' => route('subnets.index'), 'path' => 'subnets', 'label' => 'Subnety', 'acl' => ['view_all', 'Subnets_Controller', 'subnet']],
                ['url' => route('vlans.index'), 'path' => 'vlans', 'label' => 'VLANy', 'acl' => ['view_all', 'Vlans_Controller', 'vlan']],
                ['url' => route('public-ip-nat.index'), 'path' => 'public-ip-nat', 'label' => 'Veřejné IP (NAT)', 'acl' => ['view_all', 'Network_Controller', 'public_ip_nat']],
                ['url' => route('public-port-forwards.index'), 'path' => 'public-port-forwards', 'label' => 'Veřejné porty', 'acl' => ['view_all', 'Network_Controller', 'public_ports']],
                ['url' => route('redirects.index'), 'path' => 'redirections', 'label' => 'Přesměrování', 'acl' => ['view_all', 'Redirect_Controller', 'redirect']],
                ['url' => route('connection_requests.index'), 'path' => 'connection-requests', 'label' => 'Žádosti o připojení', 'acl' => ['view_all', 'Connection_Requests_Controller', 'request'], 'count' => $countConnectionRequests],
                Setting::get('gpon_enabled', '0') ? ['url' => route('gpon.index'), 'path' => 'gpon', 'label' => 'GPON', 'acl' => ['view_all', 'Settings_Controller', 'finance_settings']] : null,
            ])],
            ['name' => 'finance', 'label' => 'Finance', 'items' => [
                ['url' => route('accounts.index'),           'path' => 'accounts',          'label' => 'Účty',                   'acl' => ['view_all', 'Accounts_Controller', 'accounts']],
                ['url' => route('transfers.index'),          'path' => 'transfers',         'label' => 'Převody',                'acl' => ['view_all', 'Accounts_Controller', 'transfers']],
                ['url' => route('bank_accounts.index'),      'path' => 'bank-accounts',     'label' => 'Bankovní účty',          'acl' => ['view_all', 'Accounts_Controller', 'bank_accounts']],
                ['url' => route('bank_transfers.unidentified'), 'path' => 'bank-transfers/unidentified', 'label' => 'Neidentifikované', 'acl' => ['view_all', 'Accounts_Controller', 'unidentified_transfers'], 'count' => $countUnidentified],
                ['url' => route('outgoing_payments.index'),  'path' => 'outgoing-payments', 'label' => 'Odchozí platby',         'acl' => ['view_all', 'Accounts_Controller', 'bank_transfers'], 'count' => $countDraftOutgoing],
                ['url' => route('invoices.index'),           'path' => 'invoices',          'label' => 'Faktury',                'acl' => ['view_all', 'Accounts_Controller', 'invoices']],
                ['url' => route('pohoda-refund-queue.index'), 'path' => 'pohoda-refund-queue', 'label' => 'Vratné faktury',      'acl' => ['view_all', 'Accounts_Controller', 'invoices']],
            ]],
            ['name' => 'settings', 'label' => 'Administrace', 'items' => [
                ['url' => route('contracts.index'), 'path' => 'contracts', 'label' => 'Smlouvy', 'acl' => ['view_all', 'Members_Controller', 'members'], 'count' => $countUnsignedContracts],
                ['url' => route('notifications.members.select'), 'path' => 'notifications/members', 'label' => 'Hromadné notifikace', 'acl' => ['new_all', 'Notifications_Controller', 'member']],
                ['url' => route('stats.index'),              'path' => 'stats',             'label' => 'Statistiky',       'acl' => ['view_all', 'Stats_Controller', 'members_growth']],
                ['url' => route('log_queues.index'),        'path' => 'log-queues',        'label' => 'Chyby a logy',     'acl' => ['view_all', 'Log_queues_Controller', 'log_queue'],           'count' => $countLogErrors],
                ['url' => route('email_queues.unsent'),     'path' => 'email-queues',      'label' => 'E-mailová fronta', 'acl' => ['view_all', 'Email_queues_Controller', 'email_queue'],       'count' => $countEmailUnsent],
                ['url' => route('settings.index'),          'path' => 'settings',          'label' => 'Nastavení',        'acl' => ['view_all', 'Settings_Controller', 'finance_settings']],
                ['url' => route('sms_messages.index'),      'path' => 'sms-messages',      'label' => 'SMS zprávy',       'acl' => ['view_all', 'Sms_Controller', 'sms'],                        'count' => $countSmsUnsent],
                ['url' => route('messages.index'),          'path' => 'messages',          'label' => 'Zprávy',           'acl' => ['view_all', 'Messages_Controller', 'messages']],
                ['url' => route('device_templates.index'),  'path' => 'device-templates',  'label' => 'Šablony zařízení', 'acl' => ['view_all', 'Device_templates_Controller', 'device_template']],
                ['url' => route('member_whitelists.index'), 'path' => 'member-whitelists', 'label' => 'Bílá listina',     'acl' => ['view_all', 'Members_whitelists_Controller', 'whitelist']],
                ['url' => route('login_logs.index'),        'path' => 'login-logs',        'label' => 'Logy přihlášení',  'acl' => ['view_all', 'Login_logs_Controller', 'logs']],
                ['url' => route('towns.index'),             'path' => 'towns',             'label' => 'Města',            'acl' => ['view_all', 'Address_points_Controller', 'town']],
                ['url' => route('streets.index'),           'path' => 'streets',           'label' => 'Ulice',            'acl' => ['view_all', 'Address_points_Controller', 'street']],
                ['url' => route('aro-groups.index'),        'path' => 'aro-groups',        'label' => 'Přístupová práva', 'acl' => ['view_all', 'Aro_groups_Controller', 'aro_group']],
                ['url' => route('fees.index'),              'path' => 'fees',              'label' => 'Tarify',           'acl' => ['view_all', 'Fees_Controller', 'fees']],
                ['url' => route('speed_classes.index'),     'path' => 'speed-classes',     'label' => 'Třídy rychlosti',  'acl' => ['view_all', 'Speed_classes_Controller', 'speed_classes']],
                ['url' => route('enum-types.index'),        'path' => 'enum-types',        'label' => 'Typy',             'acl' => ['view_all', 'Settings_Controller', 'enum_types']],
            ]],
        ];

        $this->groups = [];

        foreach ($menuGroups as $group) {
            $visibleItems = [];

            foreach ($group['items'] as $item) {
                $hasAccess = $item['acl'] === null
                    || $acl->hasAccess($userId, ...$item['acl']);

                if ($hasAccess) {
                    $count = isset($item['count']) ? ($item['count'])() : null;
                    $visibleItems[] = array_merge($item, [
                        'current' => ($currentPath === $item['path']),
                        'count'   => $count,
                    ]);
                }
            }

            if (!empty($visibleItems)) {
                $this->groups[] = array_merge($group, ['items' => $visibleItems]);
            }
        }
    }

    public function render()
    {
        return view('components.freenetis-menu');
    }
}
