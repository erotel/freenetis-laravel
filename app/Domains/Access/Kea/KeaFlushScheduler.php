<?php

namespace App\Domains\Access\Kea;

use Illuminate\Contracts\Foundation\Application;

/**
 * Odložený flush Kea host-cache: jeden request (uložení zařízení s víc IP,
 * hromadná změna člena…) může změnit rezervace v několika subnetech — flush
 * stačí jednou, až na konci (terminating = po odeslání odpovědi, uživatel na
 * Kea nečeká). Singleton per aplikace.
 */
class KeaFlushScheduler
{
    private bool $scheduled = false;

    public function __construct(private Application $app, private KeaControlClient $kea)
    {
    }

    public function schedule(): void
    {
        if ($this->scheduled) {
            return;
        }
        $this->scheduled = true;
        $this->app->terminating(function () {
            $this->scheduled = false;
            $this->kea->flushHostCache();
        });
    }

    public function isScheduled(): bool
    {
        return $this->scheduled;
    }
}
