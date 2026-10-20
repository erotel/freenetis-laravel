<?php

namespace App\View\Queries;

use App\Domains\Contracts\Queries\ContractListQuery;
use App\Helpers\MemberType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Počty do odznaků hlavního menu (read model napříč doménami). Každý počet
 * se počítá až když je položka pro uživatele viditelná (menu volá líně).
 */
class MenuBadgesQuery
{
    /** Neidentifikované platby: převod z 684000 bez člena. */
    public function unidentifiedPayments(): int
    {
        return (int) DB::table('transfers as t')
            ->join('bank_transfers as bt', 'bt.transfer_id', '=', 't.id')
            ->join('accounts as a', 'a.id', '=', 't.origin_id')
            ->whereNull('t.member_id')
            ->where('a.account_attribute_id', 684000)
            ->count();
    }

    public function draftOutgoingPayments(): int
    {
        return (int) DB::table('outgoing_payments')->where('status', 'draft')->count();
    }

    /** Žadatelé, čekající člen a zákazník (1, 17, 18). */
    public function applicants(): int
    {
        return (int) DB::table('members')->whereIn('type', [1, 17, 18])->count();
    }

    public function customers(): int
    {
        return (int) DB::table('members')->where('type', MemberType::CUSTOMER)->count();
    }

    public function regularMembers(): int
    {
        return (int) DB::table('members')->where('type', MemberType::REGULAR)->count();
    }

    /**
     * Kandidáti na ukončení — jen aktivní (2/90): bývalí by se flagu měli zbavit
     * při ukončení, ale filtr brání „ghost“ počtu při nesouladu.
     */
    public function pendingTermination(): int
    {
        return (int) DB::table('members')->where('pending_termination', 1)->whereIn('type', [2, 90])->count();
    }

    /** Nové chyby a varování v logu. */
    public function logErrors(): int
    {
        return (int) DB::table('log_queues')->whereIn('type', [0, 1])->where('state', 0)->count();
    }

    public function unsentEmails(): int
    {
        return (int) DB::table('email_queues')->where('state', 0)->count();
    }

    public function unsentSms(): int
    {
        return (int) DB::table('sms_messages')->where('type', 1)->where('state', 1)->count();
    }

    public function pendingConnectionRequests(): int
    {
        return (int) DB::table('connection_requests')->where('state', 0)->count();
    }

    public function unsignedContracts(): int
    {
        return app(ContractListQuery::class)->countUnsigned();
    }

    /** DHCP servery (gateway IP s DHCP), které se přes 5 minut neozvaly. */
    public function dhcpServerErrors(): int
    {
        return (int) DB::table('devices as d')
            ->join('ifaces as i', 'i.device_id', '=', 'd.id')
            ->join('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
            ->where('ip.gateway', 1)
            ->where('ip.dhcp', 1)
            ->whereNotNull('d.access_time')
            ->where('d.access_time', '!=', '0000-00-00 00:00:00')
            ->where('d.access_time', '<', now()->subMinutes(5))
            ->distinct('d.id')
            ->count('d.id');
    }

    /** Otevřené kritické line-id anomálie (guard: tabulka nemusí existovat). */
    public function criticalLineIdAnomalies(): int
    {
        return Schema::hasTable('line_id_anomalies')
            ? (int) DB::table('line_id_anomalies')->whereNull('resolved_at')->where('severity', 'critical')->count()
            : 0;
    }
}
