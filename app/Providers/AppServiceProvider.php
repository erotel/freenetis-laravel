<?php

namespace App\Providers;

use App\Auth\FreenetisUserProvider;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AclService::class);
    }

    public function boot(): void
    {
        // Register custom user provider
        Auth::provider('freenetis', function ($app, array $config) {
            return new FreenetisUserProvider(
                $app['hash'],
                $config['model']
            );
        });

        // Gate for FreenetIS ACL
        // Usage: Gate::check('freenetis', [$acoType, $axoSection, $axoValue, $memberIdParam])
        Gate::define('freenetis', function (
            User $user,
            string $acoType,
            string $axoSection,
            string $axoValue,
            ?int $memberIdParam = null
        ) {
            /** @var AclService $acl */
            $acl = app(AclService::class);

            return $acl->check(
                $user->id,
                $user->member_id,
                $acoType,
                $axoSection,
                $axoValue,
                $memberIdParam
            );
        });
    }
}
