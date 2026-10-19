<?php

namespace Tests\Characterization\Members;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace komentářů (CommentController) před přesunem do domény:
 * líné založení vlákna k účtu / záznamu logu, formulář (zpět na vlastníka
 * vlákna), přidání, úprava, smazání (audit, návrat na kartu člena / detail
 * logu / zpět), ACL.
 */
class CommentsTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->acl();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    /** @return array{member:int, account:int} */
    private function account(): array
    {
        $m = (int) DB::table('members')->insertGetId(['name' => 'Char Kom', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        return ['member' => $m, 'account' => (int) DB::table('accounts')->insertGetId(['member_id' => $m, 'account_attribute_id' => 221100, 'balance' => 0, 'name' => 'c'])];
    }

    private function logQueue(): int
    {
        return (int) DB::table('log_queues')->insertGetId(['type' => 3, 'state' => 0, 'created_at' => now(), 'description' => 'char']);
    }

    private function audit(): object
    {
        return DB::table('audit_logs')->where('auditable_type', 'comments')->orderByDesc('id')->first();
    }

    // ── vlákno ───────────────────────────────────────────────────────────────

    public function test_line_zalozeni_vlakna(): void
    {
        $x = $this->account();
        $res = $this->as()->get(route('comments.add-thread', ['type' => 'account', 'fkId' => $x['account']]));
        $thread = (int) DB::table('accounts')->where('id', $x['account'])->value('comments_thread_id');
        $this->assertGreaterThan(0, $thread);
        $res->assertRedirect(route('comments.add', $thread));
        $this->assertSame('account', DB::table('comments_threads')->where('id', $thread)->value('type'));
        $this->as()->get(route('comments.add-thread', ['type' => 'account', 'fkId' => $x['account']]))->assertRedirect(route('comments.add', $thread));
        $this->assertSame($thread, (int) DB::table('accounts')->where('id', $x['account'])->value('comments_thread_id'), 'podruhé stejné');

        $lq = $this->logQueue();
        $this->as()->get(route('comments.add-thread', ['type' => 'log_queue', 'fkId' => $lq]));
        $lqThread = (int) DB::table('log_queues')->where('id', $lq)->value('comments_thread_id');
        $this->assertSame('log_queue', DB::table('comments_threads')->where('id', $lqThread)->value('type'));

        $this->as()->get(route('comments.add-thread', ['type' => 'device', 'fkId' => 1]))->assertNotFound();
        $this->as()->get(route('comments.add-thread', ['type' => 'account', 'fkId' => 999999999]))->assertNotFound();
        $this->as()->get(route('comments.add-thread', ['type' => 'log_queue', 'fkId' => 999999999]))->assertNotFound();
    }

    // ── přidání, úprava, smazání ─────────────────────────────────────────────

    public function test_komentar_k_uctu(): void
    {
        $x = $this->account();
        $this->as()->get(route('comments.add-thread', ['type' => 'account', 'fkId' => $x['account']]));
        $thread = (int) DB::table('accounts')->where('id', $x['account'])->value('comments_thread_id');

        $form = $this->as()->get(route('comments.add', $thread))->assertOk()->assertViewIs('comments.form');
        $this->assertSame(['create', $thread, route('members.show', $x['member']), null],
            [$form->viewData('action'), (int) $form->viewData('thread')->id, $form->viewData('backUrl'), $form->viewData('comment')]);

        $this->as()->post(route('comments.store', $thread), ['text' => '  poznámka  '])
            ->assertRedirect(route('members.show', $x['member']))->assertSessionHas('success', 'Komentář byl přidán.');
        $c = DB::table('comments')->where('comments_thread_id', $thread)->first();
        $this->assertSame(['poznámka', $this->adminId], [$c->text, (int) $c->user_id]);
        $a = $this->audit();
        $this->assertSame(['created', (int) $c->id, ['thread_id' => $thread, 'text' => 'poznámka']], [$a->action, (int) $a->auditable_id, json_decode($a->new_values, true)]);
        $this->as()->post(route('comments.store', $thread), ['text' => ''])->assertSessionHasErrors('text');

        $edit = $this->as()->get(route('comments.edit', $c->id))->assertOk();
        $this->assertSame(['edit', (int) $c->id], [$edit->viewData('action'), (int) $edit->viewData('comment')->id]);
        $this->as()->put(route('comments.update', $c->id), ['text' => ' nová '])
            ->assertRedirect(route('members.show', $x['member']))->assertSessionHas('success', 'Komentář byl upraven.');
        $this->assertSame('nová', DB::table('comments')->where('id', $c->id)->value('text'));
        $a = $this->audit();
        $this->assertSame(['updated', ['text' => 'poznámka'], ['text' => 'nová']], [$a->action, json_decode($a->old_values, true), json_decode($a->new_values, true)]);

        $this->as()->delete(route('comments.destroy', $c->id))
            ->assertRedirect(route('members.show', $x['member']))->assertSessionHas('success', 'Komentář byl smazán.');
        $this->assertFalse(DB::table('comments')->where('id', $c->id)->exists());
        $a = $this->audit();
        $this->assertSame(['deleted', ['thread_id' => $thread, 'text' => 'nová']], [$a->action, json_decode($a->old_values, true)]);

        $this->as()->get(route('comments.add', 999999999))->assertNotFound();
        $this->as()->get(route('comments.edit', 999999999))->assertNotFound();
        $this->as()->delete(route('comments.destroy', 999999999))->assertNotFound();
    }

    public function test_navrat_log_a_ostatni(): void
    {
        $lq = $this->logQueue();
        $this->as()->get(route('comments.add-thread', ['type' => 'log_queue', 'fkId' => $lq]));
        $thread = (int) DB::table('log_queues')->where('id', $lq)->value('comments_thread_id');
        $this->assertSame(route('log_queues.show', $lq), $this->as()->get(route('comments.add', $thread))->viewData('backUrl'));
        $this->as()->post(route('comments.store', $thread), ['text' => 'x'])->assertRedirect(route('log_queues.show', $lq));

        // vlákno bez vlastníka / jiného typu → zpět
        $orphan = (int) DB::table('comments_threads')->insertGetId(['type' => 'jine']);
        $this->assertNull($this->as()->get(route('comments.add', $orphan))->viewData('backUrl'));
        $this->as()->from('/odkud')->post(route('comments.store', $orphan), ['text' => 'x'])->assertRedirect('/odkud');
        $accOrphan = (int) DB::table('comments_threads')->insertGetId(['type' => 'account']);
        $this->as()->post(route('comments.store', $accOrphan), ['text' => 'x'])->assertRedirect(route('members.show', 0));
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_prava(): void
    {
        $x = $this->account();
        $thread = (int) DB::table('comments_threads')->insertGetId(['type' => 'account']);
        DB::table('accounts')->where('id', $x['account'])->update(['comments_thread_id' => $thread]);
        $c = (int) DB::table('comments')->insertGetId(['comments_thread_id' => $thread, 'user_id' => $this->adminId, 'text' => 't', 'datetime' => now()]);

        $this->acl(['new_all/Members_Controller/comment', 'edit_all/Members_Controller/comment', 'delete_all/Members_Controller/comment']);
        $this->as()->get(route('comments.add-thread', ['type' => 'account', 'fkId' => $x['account']]))->assertForbidden();
        $this->as()->get(route('comments.add', $thread))->assertForbidden();
        $this->as()->post(route('comments.store', $thread), ['text' => 'x'])->assertForbidden();
        $this->as()->get(route('comments.edit', $c))->assertForbidden();
        $this->as()->put(route('comments.update', $c), ['text' => 'x'])->assertForbidden();
        $this->as()->delete(route('comments.destroy', $c))->assertForbidden();
        $this->assertSame('t', DB::table('comments')->where('id', $c)->value('text'));
    }
}
