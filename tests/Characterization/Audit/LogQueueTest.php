<?php

namespace Tests\Characterization\Audit;

use App\Models\LogQueue;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace fronty systémových záznamů (LogQueueController) před
 * přesunem do domény Audit: seznam s filtry typu / stavu, detail
 * (kdo uzavřel, komentáře), uzavření jednoho a hromadné (jen nové,
 * podle filtru typu), ACL.
 */
class LogQueueTest extends DatabaseTestCase
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

    private function log(int $type, int $state = LogQueue::STATE_NEW): int
    {
        return (int) DB::table('log_queues')->insertGetId(['type' => $type, 'state' => $state, 'created_at' => now(), 'description' => 'char']);
    }

    public function test_seznam_a_detail(): void
    {
        $a = $this->log(3);
        $b = $this->log(1, LogQueue::STATE_CLOSED);
        DB::table('log_queues')->where('id', $b)->update(['closed_by_user_id' => $this->adminId, 'closed_at' => now()]);

        $ids = fn (array $q) => collect($this->as()->get(route('log_queues.index', $q))->assertOk()->viewData('logs')->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
        $all = $ids([]);
        $this->assertSame([$b, $a], array_values(array_intersect($all, [$a, $b])), 'nejnovější první');
        $this->assertContains($a, $ids(['type' => 3]));
        $this->assertNotContains($b, $ids(['type' => 3]));
        $this->assertNotContains($a, $ids(['state' => LogQueue::STATE_CLOSED]));
        $row = collect($this->as()->get(route('log_queues.index', ['state' => LogQueue::STATE_CLOSED]))->viewData('logs')->items())->firstWhere('id', $b);
        $admin = User::find($this->adminId);
        $this->assertSame(trim($admin->name . ' ' . $admin->surname), trim($row->closed_by_name));

        $thread = (int) DB::table('comments_threads')->insertGetId(['type' => 'log_queue']);
        DB::table('log_queues')->where('id', $b)->update(['comments_thread_id' => $thread]);
        DB::table('comments')->insert([
            ['comments_thread_id' => $thread, 'user_id' => $this->adminId, 'text' => 'druhy', 'datetime' => '2099-01-02 10:00:00'],
            ['comments_thread_id' => $thread, 'user_id' => $this->adminId, 'text' => 'prvni', 'datetime' => '2099-01-01 10:00:00'],
        ]);
        $show = $this->as()->get(route('log_queues.show', $b))->assertOk()->assertViewIs('log_queues.show');
        $this->assertSame(['prvni', 'druhy'], $show->viewData('comments')->pluck('text')->all());
        $this->assertSame($this->adminId, (int) $show->viewData('closedByUser')->id);
        $this->assertTrue($show->viewData('canAddComment') && $show->viewData('canEdit'));
        $this->assertNull($this->as()->get(route('log_queues.show', $a))->viewData('closedByUser'));
        $this->as()->get(route('log_queues.show', 999999999))->assertNotFound();
    }

    public function test_uzavreni(): void
    {
        $a = $this->log(3);
        $this->as()->post(route('log_queues.close', $a))->assertRedirect(route('log_queues.show', $a))->assertSessionHas('success', 'Záznam byl uzavřen.');
        $r = DB::table('log_queues')->where('id', $a)->first();
        $this->assertSame([LogQueue::STATE_CLOSED, $this->adminId], [(int) $r->state, (int) $r->closed_by_user_id]);
        $this->assertNotNull($r->closed_at);
        $this->as()->post(route('log_queues.close', $a))->assertSessionHas('error', 'Záznam je již uzavřen.');
        $this->as()->post(route('log_queues.close', 999999999))->assertNotFound();

        $t3 = $this->log(3);
        $t1 = $this->log(1);
        $newOfType3 = DB::table('log_queues')->where('state', LogQueue::STATE_NEW)->where('type', 3)->count();
        $this->as()->post(route('log_queues.close-all'), ['type' => '3', 'state' => '0'])
            ->assertRedirect(route('log_queues.index', ['type' => '3', 'state' => '0']))->assertSessionHas('success', "Uzavřeno {$newOfType3} záznamů.");
        $this->assertSame([LogQueue::STATE_CLOSED, LogQueue::STATE_NEW], [(int) DB::table('log_queues')->where('id', $t3)->value('state'), (int) DB::table('log_queues')->where('id', $t1)->value('state')]);
    }

    public function test_prava(): void
    {
        $a = $this->log(3);
        $this->acl(['edit_all/Log_queues_Controller/log_queue']);
        $this->assertFalse($this->as()->get(route('log_queues.index'))->viewData('canEdit'));
        $this->as()->post(route('log_queues.close', $a))->assertForbidden();
        $this->as()->post(route('log_queues.close-all'))->assertForbidden();
        $this->acl(['view_all/Log_queues_Controller/log_queue']);
        $this->as()->get(route('log_queues.index'))->assertForbidden();
        $this->as()->get(route('log_queues.show', $a))->assertForbidden();
    }
}
