<?php

namespace Tests\Feature\Security;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Našeptávač horního vyhledávání (layouts/app) skládá HTML pro innerHTML z
 * loginu / kontaktu uživatele — data z veřejné registrace. highlight() musí
 * text escapovat a zvýraznit jen shodu; spouští se skutečný JS ze šablony v node.
 */
class SearchWhisperEscapingTest extends TestCase
{
    private function js(string $call): string
    {
        $blade = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $fns = '';
        foreach (['escHtml', 'highlight'] as $fn) {
            $this->assertMatchesRegularExpression("/function {$fn}\\(/", $blade, "chybí funkce {$fn}()");
            preg_match("/function {$fn}\\(.*?\\n        }\\n/s", $blade, $m);
            $fns .= $m[0] ?? '';
        }

        $node = (new \Symfony\Component\Process\ExecutableFinder())->find('node');
        if (!$node) {
            $this->markTestSkipped('node není k dispozici');
        }
        $p = new Process([$node, '-e', $fns . "\nprocess.stdout.write(String({$call}));"]);
        $p->mustRun();
        return $p->getOutput();
    }

    public function test_highlight_escapuje_html_a_zvyrazni_shodu(): void
    {
        $this->assertSame(
            '&lt;img src=x onerror=alert(1)&gt; <span style="background:#fff3cd;font-weight:600;">Jan</span>ek &amp; &quot;&#39;',
            $this->js("highlight('<img src=x onerror=alert(1)> Janek & \"\\'', 'jan')")
        );
    }

    public function test_hledany_vyraz_s_html_znaky(): void
    {
        $this->assertSame(
            'a<span style="background:#fff3cd;font-weight:600;">&lt;b&gt;</span>c',
            $this->js("highlight('a<b>c', '<b>')")
        );
        $this->assertSame('&lt;x&gt;', $this->js("highlight('<x>', '')"));
        $this->assertSame('', $this->js("highlight(null, 'x')"));
    }
}
