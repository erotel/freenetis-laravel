<?php

namespace App\Providers;

use App\Auth\FreenetisUserProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\AclService;
use App\View\Components\FreenetisMenu;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
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

        // Register Blade components
        Blade::component('freenetis-menu', FreenetisMenu::class);

        // Custom Kohana-style pagination
        \Illuminate\Pagination\Paginator::defaultView('vendor.pagination.kohana');
        \Illuminate\Pagination\Paginator::defaultSimpleView('vendor.pagination.kohana');

        // Connection request banner — share with all views
        View::composer('layouts.app', function ($view) {
            $banner = null;
            if (auth()->check() && Setting::get('connection_request_enable')) {
                $ip = request()->ip();
                $row = DB::selectOne("
                    SELECT s.subnet_id FROM (
                        SELECT s.id AS subnet_id
                        FROM subnets s
                        WHERE inet_aton(s.netmask) & inet_aton(?) = inet_aton(s.network_address)
                    ) s
                    LEFT JOIN ip_addresses ip
                        ON ip.subnet_id = s.subnet_id
                        AND inet_aton(ip.ip_address) = inet_aton(?)
                    WHERE ? NOT IN (
                        SELECT cr.ip_address FROM connection_requests cr WHERE cr.state = 0
                    )
                    GROUP BY s.subnet_id
                    HAVING COUNT(ip.id) = 0
                ", [$ip, $ip, $ip]);
                if ($row) {
                    $banner = ['subnet_id' => $row->subnet_id, 'ip' => $ip];
                }
            }
            $view->with('connectionRequestBanner', $banner);
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
