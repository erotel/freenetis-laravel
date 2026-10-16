<?php

namespace App\Domains\Contracts\Repositories;

use Illuminate\Support\Facades\DB;

/** Jednorázové odkazy ke stažení podepsaných PDF (contractsdb.download_tokens). */
class DownloadTokenRepository
{
    /** @return string náhodný token (64 hex) */
    public function issue(int $contractId, string $fileType, int $ttlSeconds, ?string $bindIp, int $maxUses, ?int $addonId = null): string
    {
        $token = bin2hex(random_bytes(32));
        DB::connection('contracts')->table('download_tokens')->insert([
            'token'       => $token,
            'contract_id' => $contractId,
            'addon_id'    => $addonId,
            'file_type'   => $fileType,
            'expires_at'  => now()->addSeconds($ttlSeconds),
            'bind_ip'     => $bindIp,
            'max_uses'    => $maxUses,
            'created_at'  => now(),
        ]);
        return $token;
    }

    public function find(string $token): ?object
    {
        return DB::connection('contracts')->table('download_tokens')->where('token', $token)->first();
    }

    public function markUsed(int $id): void
    {
        DB::connection('contracts')->table('download_tokens')->where('id', $id)->update(['used_at' => now()]);
    }
}
