<?php

namespace App\Domains\Access\Support;

/**
 * Rozparsování option82 (relay agent information), které Kea ukládá k leasu při
 * `store-extended-info: true` do `user-context.ISC.relay-agent-info.sub-options`
 * jako hex TLV řetězec ("0x01<len><circuit>02<len><remote>…"). Čisté funkce bez DB.
 *
 * Proč ne z flex-id / client-id leasu: ten je concat(remote, circuit) a délka
 * remote-id se liší podle výrobce, takže ho zpětně rozdělit nejde. TLV nese obě
 * části zvlášť.
 */
class RelayAgentInfo
{
    public const SUB_CIRCUIT = 1;
    public const SUB_REMOTE  = 2;

    /**
     * @return array{circuit:?string, remote:?string} surové bajty sub-options 1 a 2
     *         (null = sub-option chybí nebo vstup není platné TLV)
     */
    public function parseSubOptions(?string $hex): array
    {
        $out = ['circuit' => null, 'remote' => null];
        if ($hex === null) {
            return $out;
        }
        $hex = preg_replace('/^0x/i', '', trim($hex));
        if ($hex === '' || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            return $out;
        }
        $bin = hex2bin($hex);
        $len = strlen($bin);
        $i = 0;
        while ($i + 2 <= $len) {
            $code = ord($bin[$i]);
            $l    = ord($bin[$i + 1]);
            if ($i + 2 + $l > $len) {
                return ['circuit' => null, 'remote' => null]; // useknuté TLV → nevěřit ničemu
            }
            $val = substr($bin, $i + 2, $l);
            if ($code === self::SUB_CIRCUIT && $out['circuit'] === null) {
                $out['circuit'] = $val;
            } elseif ($code === self::SUB_REMOTE && $out['remote'] === null) {
                $out['remote'] = $val;
            }
            $i += 2 + $l;
        }
        return $out;
    }

    /** Bajty → formát, jaký do `line_id_seen` píše FreeRADIUS ('0x' + malá hex). */
    public static function toSeenHex(?string $bin): string
    {
        return ($bin === null || $bin === '') ? '' : '0x' . bin2hex($bin);
    }
}
