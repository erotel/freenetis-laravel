<?php

namespace Tests\Characterization\Catalog;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/** Společný základ charakterizace malých správcovských seznamů (ACL mock, admin). */
abstract class CatalogTestCase extends DatabaseTestCase
{
    protected int $adminId;
    protected string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->tag = 'Ct' . substr(md5(uniqid('', true)), 0, 8);
        $this->acl();
    }

    protected function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => (int) $u === $this->adminId && !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    protected function as(?int $userId = null)
    {
        return $this->actingAs(User::find($userId ?? $this->adminId));
    }
}
