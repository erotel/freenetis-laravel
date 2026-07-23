<?php

namespace App\Services;

use App\Models\Street;
use App\Models\Town;
use Illuminate\Support\Facades\DB;

/**
 * Najde nebo vytvoří město/ulici podle dat z ARES.
 *
 * ARES (ekonomické subjekty) je autoritativní zdroj adres sídel, takže když město
 * nebo ulice v DB chybí, můžeme je bezpečně automaticky založit — místo aby admin
 * musel ručně přeskakovat do správy měst/ulic a formulář nešel uložit.
 *
 * Pozn.: tabulky nemají DB unique constraint (jen aplikační), proto matchujeme
 * v kódu (exact) a případně zakládáme. `streets.street` je varchar(30) → ořez.
 */
class AddressResolverService
{
    /**
     * @return array{
     *   town_id: int|null, town_name: string|null,
     *   street_id: int|null, street_name: string|null,
     *   town_created: bool, street_created: bool
     * }
     */
    public function resolveOrCreate(string $townName, string $zip, string $streetName): array
    {
        $townName   = trim($townName);
        $zip        = preg_replace('/\D/', '', $zip);
        $streetName = trim($streetName);

        $out = [
            'town_id' => null, 'town_name' => null,
            'street_id' => null, 'street_name' => null,
            'town_created' => false, 'street_created' => false,
        ];

        if ($townName === '') {
            return $out;
        }

        $town = $this->findTown($townName, $zip);
        if (!$town) {
            // ARES je důvěryhodný zdroj — chybějící město založíme.
            $townId = DB::table('towns')->insertGetId([
                'town'     => mb_substr($townName, 0, 50),
                'zip_code' => $zip !== '' ? mb_substr($zip, 0, 10) : '',
                'quarter'  => null,
            ]);
            $town = (object) ['id' => $townId, 'town' => $townName];
            $out['town_created'] = true;
        }

        $out['town_id']   = $town->id;
        $out['town_name'] = $town->town;

        if ($streetName !== '') {
            // street je varchar(30) — ořízneme PŘED hledáním i zápisem, ať se
            // dlouhý název (uložený oříznutě) při dalším lookupu spároval a
            // nezakládal duplikát.
            $streetTrunc = mb_substr($streetName, 0, 30);

            $street = DB::table('streets')
                ->where('town_id', $town->id)
                ->where('street', $streetTrunc)
                ->first(['id', 'street']);

            if (!$street) {
                $streetId = DB::table('streets')->insertGetId([
                    'town_id' => $town->id,
                    'street'  => $streetTrunc,
                ]);
                $street = (object) ['id' => $streetId, 'street' => $streetTrunc];
                $out['street_created'] = true;
            }

            $out['street_id']   = $street->id;
            $out['street_name'] = $street->street;
        }

        return $out;
    }

    /**
     * Najdi nebo vytvoř address_point pro danou trojici (město / ulice / číslo).
     * Adresní body se sdílí (víc členů/zařízení míří na stejné umístění), takže
     * shodnou adresu znovu nevytváříme — vrátíme existující id. Vrací null, když
     * není zadané město.
     *
     * country_id = 1 kvůli konzistenci se zbytkem systému (legacy konvence, viz
     * MemberController / RegistrationController), i když CZ má v countries jiné id.
     */
    public function resolveAddressPoint(?int $townId, ?int $streetId, ?string $streetNumber): ?int
    {
        if (!$townId) {
            return null;
        }

        $streetNumber = ($streetNumber !== null && trim($streetNumber) !== '')
            ? mb_substr(trim($streetNumber), 0, 50)
            : null;

        $existing = DB::table('address_points')
            ->where('town_id', $townId)
            ->where(fn($q) => $streetId ? $q->where('street_id', $streetId) : $q->whereNull('street_id'))
            ->where(fn($q) => $streetNumber !== null ? $q->where('street_number', $streetNumber) : $q->whereNull('street_number'))
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        return (int) DB::table('address_points')->insertGetId([
            'town_id'       => $townId,
            'street_id'     => $streetId,
            'street_number' => $streetNumber,
            'country_id'    => 1,
        ]);
    }

    /**
     * Najdi existující město: přesná shoda názvu + PSČ, pak přesný název (jiné PSČ),
     * ať se nezaloží duplikát existujícího města jen kvůli odlišnému zápisu PSČ.
     */
    private function findTown(string $townName, string $zip): ?object
    {
        if ($zip !== '') {
            $town = DB::table('towns')
                ->where('town', $townName)
                ->where('zip_code', $zip)
                ->first(['id', 'town']);
            if ($town) {
                return $town;
            }
        }

        return DB::table('towns')
            ->where('town', $townName)
            ->first(['id', 'town']);
    }
}
