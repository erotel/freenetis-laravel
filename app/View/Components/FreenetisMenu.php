<?php

namespace App\View\Components;

use App\Services\AclService;
use Illuminate\View\Component;

class FreenetisMenu extends Component
{
    public array $groups;

    public function __construct(AclService $acl)
    {
        $user = auth()->user();
        $userId = $user?->id ?? 0;
        $currentPath = request()->path();

        $menuGroups = [
            ['name' => 'home', 'label' => 'Domů', 'items' => [
                ['url' => route('members.show', $user?->member_id ?? 1), 'path' => '', 'label' => 'Můj profil', 'acl' => null],
            ]],
            ['name' => 'members', 'label' => 'Členové', 'items' => [
                ['url' => route('members.index'), 'path' => 'members', 'label' => 'Seznam členů', 'acl' => ['view_all', 'Members_Controller', 'members']],
                ['url' => route('users.index'), 'path' => 'users', 'label' => 'Uživatelé', 'acl' => ['view_all', 'Users_Controller', 'users']],
                ['url' => url('users/' . ($user?->id ?? 0) . '/contacts'), 'path' => 'contacts', 'label' => 'Kontakty', 'acl' => ['view_all', 'Users_Controller', 'additional_contacts']],
            ]],
            ['name' => 'network', 'label' => 'Síť', 'items' => [
                ['url' => route('devices.index'), 'path' => 'devices', 'label' => 'Zařízení', 'acl' => ['view_all', 'Devices_Controller', 'devices']],
                ['url' => route('ip_addresses.index'), 'path' => 'ip-addresses', 'label' => 'IP adresy', 'acl' => ['view_all', 'Ip_addresses_Controller', 'ip_address']],
                ['url' => route('subnets.index'), 'path' => 'subnets', 'label' => 'Subnety', 'acl' => ['view_all', 'Subnets_Controller', 'subnet']],
                ['url' => route('vlans.index'), 'path' => 'vlans', 'label' => 'VLANy', 'acl' => ['view_all', 'Vlans_Controller', 'vlan']],
            ]],
            ['name' => 'finance', 'label' => 'Finance', 'items' => [
                ['url' => route('accounts.index'),           'path' => 'accounts',          'label' => 'Účty',                   'acl' => ['view_all', 'Accounts_Controller', 'accounts']],
                ['url' => route('transfers.index'),          'path' => 'transfers',         'label' => 'Převody',                'acl' => ['view_all', 'Accounts_Controller', 'transfers']],
                ['url' => route('bank_accounts.index'),      'path' => 'bank-accounts',     'label' => 'Bankovní účty',          'acl' => ['view_all', 'Accounts_Controller', 'bank_accounts']],
                ['url' => route('bank_transfers.unidentified'), 'path' => 'bank-transfers/unidentified', 'label' => 'Neidentifikované', 'acl' => ['view_all', 'Accounts_Controller', 'unidentified_transfers']],
                ['url' => route('outgoing_payments.index'),  'path' => 'outgoing-payments', 'label' => 'Odchozí platby',         'acl' => ['view_all', 'Accounts_Controller', 'bank_transfers']],
                ['url' => route('invoices.index'),           'path' => 'invoices',          'label' => 'Faktury',                'acl' => ['view_all', 'Accounts_Controller', 'invoices']],
            ]],
            ['name' => 'logs', 'label' => 'Logy', 'items' => [
                ['url' => route('login_logs.index'), 'path' => 'login-logs', 'label' => 'Logy přihlášení', 'acl' => ['view_all', 'Login_logs_Controller', 'logs']],
            ]],
            ['name' => 'settings', 'label' => 'Administrace', 'items' => [
                ['url' => route('towns.index'), 'path' => 'towns', 'label' => 'Města', 'acl' => ['view_all', 'Address_points_Controller', 'town']],
                ['url' => route('streets.index'), 'path' => 'streets', 'label' => 'Ulice', 'acl' => ['view_all', 'Address_points_Controller', 'street']],
                ['url' => url('settings'), 'path' => 'settings', 'label' => 'Nastavení', 'acl' => ['view_all', 'Settings_Controller', 'settings']],
                ['url' => route('aro-groups.index'), 'path' => 'aro-groups', 'label' => 'Přístupová práva', 'acl' => ['view_all', 'Aro_groups_Controller', 'aro_groups']],
                ['url' => route('fees.index'), 'path' => 'fees', 'label' => 'Tarify', 'acl' => ['view_all', 'Fees_Controller', 'fees']],
                ['url' => route('speed_classes.index'), 'path' => 'speed-classes', 'label' => 'Třídy rychlosti', 'acl' => ['view_all', 'Speed_classes_Controller', 'speed_classes']],
            ]],
        ];

        $this->groups = [];

        foreach ($menuGroups as $group) {
            $visibleItems = [];

            foreach ($group['items'] as $item) {
                if ($item['acl'] === null) {
                    $visibleItems[] = $item + ['current' => ($currentPath === $item['path'])];
                } else {
                    [$acoValue, $axoSection, $axoVal] = $item['acl'];
                    $hasAccess = $acl->hasAccess($userId, $acoValue, $axoSection, $axoVal);

                    if ($hasAccess) {
                        $visibleItems[] = $item + ['current' => ($currentPath === $item['path'])];
                    }
                }
            }

            if (!empty($visibleItems)) {
                $this->groups[] = $group + ['items' => $visibleItems];
            }
        }
    }

    public function render()
    {
        return view('components.freenetis-menu');
    }
}
