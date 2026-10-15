<?php

namespace App\Domains\Members\Actions;

use App\Domains\Members\Repositories\MemberDeviceRepository;

/**
 * Úklid zařízení odcházejícího člena (setting former_member_auto_device_remove):
 * IP adresy se nejdřív zapíšou na začátek komentáře „[YYYY-MM-DD] ip1,ip2,…"
 * (stopa pro dohledání), pak se smažou zařízení, adresy a vlastnictví podsítí.
 */
class RemoveFormerMemberDevicesAction
{
    private const COMMENT_MAX = 250;

    public function __construct(private MemberDeviceRepository $devices)
    {
    }

    /**
     * @param bool $noteWhenEmpty i bez IP zapsat „[datum] (žádné)" (ruční ukončení
     *                            ano, cron v den odchodu ne)
     * @return int počet smazaných zařízení
     */
    public function handle(int $memberId, string $today, bool $noteWhenEmpty): int
    {
        $ips = $this->devices->ipAddresses($memberId);
        if ($ips !== [] || $noteWhenEmpty) {
            $this->devices->prependComment($memberId, self::commentLine($ips, $today));
        }

        return $this->devices->deleteAll($memberId);
    }

    /** „[datum] ip1,ip2 (+N další)" zkrácené na 250 znaků; bez IP „[datum] (žádné)". */
    public static function commentLine(array $ips, string $today): string
    {
        $prefix = "[{$today}] ";
        if ($ips === []) {
            return $prefix . '(žádné)';
        }

        $out   = $prefix;
        $shown = 0;
        foreach ($ips as $ip) {
            $add = ($shown ? ',' : '') . $ip;
            if (strlen($out . $add) > self::COMMENT_MAX) break;
            $out .= $add;
            $shown++;
        }

        $rest = count($ips) - $shown;
        if ($rest > 0) {
            $suffix = " (+{$rest} další)";
            if (strlen($out . $suffix) > self::COMMENT_MAX) {
                $out = rtrim(substr($out, 0, max(0, self::COMMENT_MAX - strlen($suffix))), ', ');
            }
            $out .= $suffix;
        }

        return $out;
    }
}
