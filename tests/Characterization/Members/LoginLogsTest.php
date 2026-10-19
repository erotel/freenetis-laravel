<?php

namespace Tests\Characterization\Members;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace přihlášení (LoginLogController) před přesunem do domény
 * Members: uživatelé podle posledního přihlášení, historie
 * uživatele (vlastní člen view_own, jinak view_all), ACL.
 */
class LoginLogsTest extends CatalogTestCase
{
    public function test_prehled_a_historie(): void
    {
        $u = (int) DB::table('users')->insertGetId(['member_id' => 1, 'login' => 'charll_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'L', 'comment' => '']);
        DB::table('login_logs')->insert([['user_id' => $u, 'time' => '2099-01-01 10:00:00', 'IP_address' => '10.0.0.1'], ['user_id' => $u, 'time' => '2099-01-02 10:00:00', 'IP_address' => '10.0.0.2']]);

        $first = collect($this->as()->get(route('login_logs.index'))->assertOk()->viewData('users')->items())->first();
        $this->assertSame([$u, '2099-01-02 10:00:00'], [(int) $first->id, $first->last_time], 'nejnovější přihlášení první');

        $res = $this->as()->get(route('login_logs.by_user', $u))->assertOk()->assertViewIs('login_logs.show_by_user');
        $this->assertSame(['10.0.0.2', '10.0.0.1'], collect($res->viewData('logs')->items())->pluck('IP_address')->all());
        $this->assertSame($u, (int) $res->viewData('targetUser')->id);
        $this->as()->get(route('login_logs.by_user', 999999999))->assertNotFound();
    }

    public function test_prava(): void
    {
        $adminMember = (int) DB::table('users')->where('id', $this->adminId)->value('member_id');
        $same = (int) DB::table('users')->insertGetId(['member_id' => $adminMember, 'login' => 'charll_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'L', 'comment' => '']);
        $other = (int) DB::table('users')->where('member_id', '<>', $adminMember)->value('id');

        $this->acl(['view_all/Login_logs_Controller/logs']);
        $this->as()->get(route('login_logs.index'))->assertForbidden();
        $this->as()->get(route('login_logs.by_user', $same))->assertOk();
        $this->as()->get(route('login_logs.by_user', $other))->assertForbidden();
        $this->acl(['view_all/Login_logs_Controller/logs', 'view_own/Login_logs_Controller/logs']);
        $this->as()->get(route('login_logs.by_user', $same))->assertForbidden();
    }
}
