<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstrap pro čerstvou instalaci.
 *
 * Předpokládá: bootstrap.sql.gz už importovaný (schema + ACL/enum_types/messages
 * lookup data). Po této instalaci je systém přihlasitelný adminovým loginem
 * a ostatní setup (členové, ceník, FIO tokeny) se dělá přes UI.
 *
 * Idempotentní — pokud už admin/organizace existují, neukončí se chybou,
 * ale skipne to (aby se dalo spustit znovu).
 */
class FreenetisInstall extends Command
{
    protected $signature = 'freenetis:install
                            {--login=         : Login admina (default: ptá se)}
                            {--password=      : Heslo admina (default: ptá se)}
                            {--name=          : Křestní jméno (default: ptá se)}
                            {--surname=       : Příjmení (default: ptá se)}
                            {--email=         : Kontaktní e-mail admina (default: ptá se)}
                            {--org-name=      : Název organizace (default: ptá se)}';

    protected $description = 'Vytvoří admin účet + organizaci pro čerstvou FreenetIS instalaci';

    private const SYSADMIN_GROUP_ID = 32;
    private const ACCOUNT_ATTR_CREDIT = 221100;

    public function handle(): int
    {
        $this->info('═════════════════════════════════════════');
        $this->info('  FreenetIS — bootstrap admin účet');
        $this->info('═════════════════════════════════════════');

        // 1) Idempotentní guard — pokud už máme aspoň 1 sysadmina, skip
        $existingAdmins = DB::table('groups_aro_map')
            ->where('group_id', self::SYSADMIN_GROUP_ID)
            ->count();

        if ($existingAdmins > 0) {
            $this->warn('System administrators už existují (' . $existingAdmins . '). Install neproběhne — pro reset smaž ručně řádky z groups_aro_map / users / members.');
            return self::SUCCESS;
        }

        // 2) Vstupy
        $orgName  = $this->getInput('org-name', 'Název organizace (např. PVfree.net z.s.)');
        $login    = $this->getInput('login', 'Login admina');
        $name     = $this->getInput('name', 'Křestní jméno admina');
        $surname  = $this->getInput('surname', 'Příjmení admina');
        $email    = $this->getInput('email', 'Kontaktní e-mail');
        $password = $this->option('password') ?: $this->secret('Heslo admina (min. 8 znaků)');

        if (strlen($password) < 8) {
            $this->error('Heslo musí mít aspoň 8 znaků.');
            return self::FAILURE;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("'$email' není validní e-mail.");
            return self::FAILURE;
        }

        if (DB::table('users')->where('login', $login)->exists()) {
            $this->error("Uživatel s loginem '$login' už existuje.");
            return self::FAILURE;
        }

        // 3) Souhrn + confirm (pokud interaktivní)
        $this->newLine();
        $this->info('── Souhrn ──────────────────────────────────');
        $this->line('  Organizace: ' . $orgName);
        $this->line('  Admin:      ' . $name . ' ' . $surname);
        $this->line('  Login:      ' . $login);
        $this->line('  E-mail:     ' . $email);
        $this->newLine();

        if (!$this->option('no-interaction') && !$this->confirm('Pokračovat?', true)) {
            $this->warn('Zrušeno uživatelem.');
            return self::FAILURE;
        }

        // 4) Reset AUTO_INCREMENT v hlavních tabulkách (mysqldump zachoval counter
        //    z dev DB, takže by členové začínali od ~9000+). Bezpečné — DB je prázdná.
        foreach (['members', 'users', 'accounts', 'contacts', 'address_points'] as $t) {
            DB::statement("ALTER TABLE `$t` AUTO_INCREMENT = 1");
        }

        // 5) Vytvoření v transakci
        DB::transaction(function () use ($orgName, $login, $name, $surname, $email, $password) {
            // 4a) Country / Town / Address point — placeholdery pro FK na members.address_point_id.
            //     Schema má NOT NULL na country_id + town_id, takže je nutné mít aspoň 1 řádek
            //     v každé. V UI se to později opraví / doplní.
            if (!DB::table('countries')->where('id', 1)->exists()) {
                DB::table('countries')->insert([
                    'id'           => 1,
                    'country_name' => 'Česká republika',
                    'country_iso'  => 'CZE',
                    'country_code' => '420',
                    'enabled'      => 1,
                ]);
                $this->info('  ✓ Country #1 (Česká republika)');
            }
            if (!DB::table('towns')->where('id', 1)->exists()) {
                DB::table('towns')->insert([
                    'id'       => 1,
                    'zip_code' => '00000',
                    'town'     => 'Nezadáno',
                ]);
                $this->info('  ✓ Town #1 placeholder');
            }
            if (!DB::table('address_points')->where('id', 1)->exists()) {
                DB::table('address_points')->insert([
                    'id'         => 1,
                    'name'       => 'Default — nastav přes admin UI',
                    'country_id' => 1,
                    'town_id'    => 1,
                ]);
                $this->info('  ✓ Address point #1 placeholder');
            }

            // 4b) Organizace (member_id=1)
            $orgExists = DB::table('members')->where('id', 1)->exists();
            if (!$orgExists) {
                DB::table('members')->insert([
                    'id'                 => 1,
                    'name'               => $orgName,
                    'address_point_id'   => 1,
                    'type'               => 90,                // 90 = OWNER / organization v PVfree konvenci
                    'leaving_date'       => '9999-12-31',
                    'entrance_date'      => now()->toDateString(),
                    'registration'       => 1,
                    'locked'             => 0,
                ]);
                $this->info('  ✓ Organizace (member_id=1) vytvořena');
            } else {
                $this->line('  · Organizace (member_id=1) už existuje, neměním');
            }

            // 4c) Member admina
            $memberId = DB::table('members')->insertGetId([
                'name'               => $name . ' ' . $surname,
                'address_point_id'   => 1,
                'type'               => 1,                    // 1 = REGULAR member
                'leaving_date'       => '9999-12-31',
                'entrance_date'      => now()->toDateString(),
                'registration'       => 1,
                'locked'             => 0,
            ]);
            $this->info("  ✓ Member admina (#$memberId)");

            // 4d) Kreditní účet admina
            DB::table('accounts')->insert([
                'member_id'             => $memberId,
                'name'                  => 'Účet kreditu — ' . $name . ' ' . $surname,
                'account_attribute_id'  => self::ACCOUNT_ATTR_CREDIT,
                'balance'               => 0,
            ]);

            // 4e) User admina
            $userId = DB::table('users')->insertGetId([
                'member_id'             => $memberId,
                'login'                 => $login,
                'password'              => Hash::make($password),
                'name'                  => $name,
                'surname'               => $surname,
                'type'                  => 1,                  // 1 = MAIN_USER
                'application_password'  => '',
                'settings'              => '',
            ]);
            $this->info("  ✓ User admina '$login' (#$userId)");

            // 4f) Kontakt — e-mail
            $contactId = DB::table('contacts')->insertGetId([
                'type'  => 20,                                 // 20 = email v PVfree enum
                'value' => $email,
            ]);
            DB::table('users_contacts')->insert([
                'user_id'    => $userId,
                'contact_id' => $contactId,
            ]);

            // 4g) Zařadit admina do System administrators (group_id = 32)
            DB::table('groups_aro_map')->insert([
                'group_id' => self::SYSADMIN_GROUP_ID,
                'aro_id'   => $userId,
            ]);
            $this->info("  ✓ User zařazen do skupiny System administrators (id 32)");

            // 4h) Základní config
            $this->upsertConfig('title', $orgName);
            $this->upsertConfig('email_default_email', $email);
            $this->upsertConfig('forgotten_password', '1');
        });

        $this->newLine();
        $this->info('═════════════════════════════════════════');
        $this->info('  ✓ Admin účet vytvořen');
        $this->info('═════════════════════════════════════════');
        $this->line("  Login:    $login");
        $this->line("  URL:      " . config('app.url') . '/login');
        $this->newLine();
        $this->line('Další kroky v admin UI:');
        $this->line('  - Nastavení → vyplnit FIO API tokeny, SMS, mail, …');
        $this->line('  - Přístupová práva → nastavit další ACL skupiny dle potřeby');
        $this->line('  - Vytvořit první členy přes Uživatelé → Přidat člena');

        return self::SUCCESS;
    }

    /**
     * Vrátí hodnotu z option, jinak interaktivně se zeptá.
     */
    private function getInput(string $option, string $prompt): string
    {
        $val = $this->option($option);
        if ($val !== null && $val !== '') {
            return (string) $val;
        }
        if ($this->option('no-interaction')) {
            $this->error("V no-interaction módu musíš zadat --$option");
            exit(self::FAILURE);
        }
        return (string) $this->ask($prompt);
    }

    private function upsertConfig(string $name, string $value): void
    {
        DB::table('config')->updateOrInsert(
            ['name' => $name],
            ['value' => $value],
        );
    }
}
