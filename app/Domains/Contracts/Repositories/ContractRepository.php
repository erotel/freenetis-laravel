<?php

namespace App\Domains\Contracts\Repositories;

use App\Helpers\MemberType;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/** Smlouvy (contractsdb.contracts). */
class ContractRepository
{
    /** Poslední smlouva člena (jeden člen = jedna smlouva; zrušené návrhy se mažou). */
    public function forMember(int $memberId): ?Contract
    {
        return Contract::where('member_id', $memberId)->orderByDesc('id')->first();
    }

    /**
     * Číslo smlouvy `SML-<rok>-<ID člena na 6 cifer>` (konvence z importu).
     * Obsazené legacy smlouvou jiného člena → suffix -2, -3 … (UNIQUE uq_contract_no).
     */
    public function nextContractNo(int $memberId): string
    {
        $base = sprintf('SML-%s-%06d', date('Y'), $memberId);
        if (!Contract::where('contract_no', $base)->exists()) {
            return $base;
        }
        for ($i = 2; ; $i++) {
            $candidate = $base . '-' . $i;
            if (!Contract::where('contract_no', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    /** id bývalých členů/zákazníků (15/16) — jejich smlouvy se v seznamu a odznaku skrývají. @return int[] */
    public function formerMemberIds(): array
    {
        return DB::connection('mysql')
            ->table('members')
            ->whereIn('type', [MemberType::FORMER, MemberType::FORMER_CUSTOMER])
            ->pluck('id')
            ->all();
    }
}
