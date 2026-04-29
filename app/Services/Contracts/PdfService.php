<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use setasign\Fpdi\Tcpdf\Fpdi;

class PdfService
{
    /**
     * Generate the contract PDF. With $preview=true a watermark is applied and no evidence page.
     * For $preview=false: writes the file, signs it with the configured PFX certificate (if available),
     * and returns the absolute file path.
     */
    public function renderContractPdf(Contract $contract, bool $preview, ?Request $request = null): string
    {
        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $p     = $party?->toArray() ?? [];

        $signedAtStr = !empty($contract->signed_at)
            ? (string) $contract->signed_at
            : now()->format('Y-m-d H:i:s');

        $serviceFullRaw = trim((string) ($p['service_full_address'] ?? ''));
        if ($serviceFullRaw === ',' || $serviceFullRaw === '') {
            $serviceFullRaw = trim(($p['street'] ?? '') . ' ' . ($p['town'] ?? '') . ' ' . ($p['country'] ?? ''));
        }

        $icoOrBday = !empty($p['ico'])
            ? $p['ico']
            : (!empty($p['birthday']) ? date('d.m.Y', strtotime((string) $p['birthday'])) : '');

        $phone = (string) ($contract->phone ?: ($p['phone'] ?? '')) ?: $this->memberPhone((int) $contract->member_id);

        $repl = [
            '{{contract_no}}'          => $this->h($contract->contract_no),
            '{{full_name}}'            => $this->h($p['full_name'] ?? ''),
            '{{street}}'               => $this->h($p['street'] ?? ''),
            '{{town}}'                 => $this->h($p['town'] ?? ''),
            '{{country}}'              => $this->h($p['country'] ?? ''),
            '{{ico}}'                  => $this->h((string) $icoOrBday),
            '{{dic}}'                  => $this->h($p['dic'] ?? ''),
            '{{mal}}'                  => $this->h($p['email'] ?? ''),
            '{{speed_name}}'           => $this->h($p['speed_name'] ?? ''),
            '{{variable_symbol}}'      => $this->h($p['variable_symbol'] ?? ''),
            '{{phone_mask}}'           => $this->h($phone),
            '{{price}}'                => $this->h((string) ($p['price'] ?? '')),
            '{{dat}}'                  => $this->h($signedAtStr),
            '{{service_full_address}}' => $this->h($serviceFullRaw),
        ];

        $html = $this->resolveTemplateAssets(strtr($this->loadTemplate('contract.html'), $repl));

        $tmpDir = $this->tmpDir();
        $mpdf   = new Mpdf(['tempDir' => $tmpDir]);

        if ($preview) {
            $mpdf->SetWatermarkText('NÁHLED');
            $mpdf->showWatermarkText = true;
            $mpdf->WriteHTML($html);
            return $mpdf->Output('', Destination::STRING_RETURN);
        }

        $mpdf->WriteHTML($html);
        $mpdf->AddPage();
        $mpdf->WriteHTML($this->buildEvidenceHtml($contract, $request, false));

        $outDir   = $this->storageDir();
        $filename = "contract-{$contract->id}.pdf";
        $fullpath = $outDir . '/' . $filename;
        $unsigned = $tmpDir . '/unsigned-' . $filename;
        $mpdf->Output($unsigned, Destination::FILE);

        $signedTemp = $tmpDir . '/signed-' . $filename;
        $signed     = $this->signPdf($unsigned, $signedTemp);
        $source     = $signed ? $signedTemp : $unsigned;

        if (!@rename($source, $fullpath)) {
            copy($source, $fullpath);
            @unlink($source);
        }
        @unlink($unsigned);

        return $fullpath;
    }

    /**
     * Generate the addon PDF. Returns the absolute file path (preview returns string instead).
     */
    public function renderAddonPdf(Contract $contract, bool $preview, ?Request $request = null)
    {
        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $p     = $party?->toArray() ?? [];

        $birthday          = trim((string) ($p['birthday'] ?? ''));
        $birthdayFormatted = '';
        if ($birthday !== '') {
            $dt = \DateTime::createFromFormat('Y-m-d', $birthday);
            if ($dt !== false) $birthdayFormatted = $dt->format('d.m.Y');
        }

        $ico = trim((string) ($p['ico'] ?? ''));
        $dic = trim((string) ($p['dic'] ?? ''));
        if ($ico === '' && $dic === '') {
            $ico = $birthdayFormatted;
        }

        $phone     = (string) ($contract->phone ?? '');
        $phoneMask = $phone !== '' ? $this->maskPhone($phone) : '';
        $todayStr  = now()->setTimezone('Europe/Prague')->format('Y-m-d H:i');

        $repl = [
            '{{contract_no}}'     => $this->h($contract->contract_no),
            '{{full_name}}'       => $this->h($p['full_name'] ?? ''),
            '{{street}}'          => $this->h($p['street'] ?? ''),
            '{{town}}'            => $this->h($p['town'] ?? ''),
            '{{country}}'         => $this->h($p['country'] ?? ''),
            '{{variable_symbol}}' => $this->h($p['variable_symbol'] ?? ''),
            '{{phone_mask}}'      => $this->h($phoneMask),
            '{{today}}'           => $this->h($todayStr),
            '{{ico}}'             => $this->h($ico),
            '{{dic}}'             => $this->h($dic),
            '{{dat}}'             => $this->h(date('d.m.Y')),
        ];

        $html   = $this->resolveTemplateAssets(strtr($this->loadTemplate('addon_zero_tariff.html'), $repl));
        $tmpDir = $this->tmpDir();
        $mpdf   = new Mpdf(['tempDir' => $tmpDir]);

        if ($preview) {
            $mpdf->SetWatermarkText('NÁHLED');
            $mpdf->showWatermarkText = true;
            $mpdf->WriteHTML($html);
            return $mpdf->Output('', Destination::STRING_RETURN);
        }

        $mpdf->WriteHTML($html);
        $mpdf->AddPage();
        $mpdf->WriteHTML($this->buildAddonEvidenceHtml($phoneMask, $request));

        $outDir   = $this->storageDir();
        $filename = "addon-{$contract->id}.pdf";
        $fullpath = $outDir . '/' . $filename;
        $mpdf->Output($fullpath, Destination::FILE);

        return $fullpath;
    }

    /**
     * Generate the membership-termination PDF.
     */
    public function renderTerminationPdf(Contract $contract, ?Request $request = null): string
    {
        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $p     = $party?->toArray() ?? [];

        $repl = [
            '{{today}}'           => now()->format('Y-m-d'),
            '{{email}}'           => $this->h($p['email'] ?? ''),
            '{{full_name}}'       => $this->h($p['full_name'] ?? ''),
            '{{street}}'          => $this->h($p['street'] ?? ''),
            '{{town}}'            => $this->h($p['town'] ?? ''),
            '{{country}}'         => $this->h($p['country'] ?? ''),
            '{{ico}}'             => $this->h($p['ico'] ?? ''),
            '{{dic}}'             => $this->h($p['dic'] ?? ''),
            '{{variable_symbol}}' => $this->h($p['variable_symbol'] ?? ''),
            '{{phone_mask}}'      => $this->h($this->maskPhone((string) ($contract->phone ?? ''))),
        ];

        $html   = $this->resolveTemplateAssets(strtr($this->loadTemplate('termination.html'), $repl));
        $tmpDir = $this->tmpDir();
        $mpdf   = new Mpdf(['tempDir' => $tmpDir]);
        $mpdf->WriteHTML($html);
        $mpdf->AddPage();
        $mpdf->WriteHTML($this->buildEvidenceHtml($contract, $request, true));

        $outDir   = $this->storageDir();
        $filename = "termination-{$contract->id}.pdf";
        $fullpath = $outDir . '/' . $filename;
        $mpdf->Output($fullpath, Destination::FILE);

        return $fullpath;
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function loadTemplate(string $name): string
    {
        $path = base_path('resources/views/contracts/templates/' . $name);
        if (!is_file($path)) {
            throw new \RuntimeException("Template not found: {$path}");
        }
        return (string) file_get_contents($path);
    }

    /**
     * Rewrite relative <img src="..."> references in the template to absolute file:// paths
     * so mpdf (which renders from a string) can find them.
     */
    private function resolveTemplateAssets(string $html): string
    {
        $base = base_path('resources/views/contracts/templates');
        return preg_replace_callback(
            '/(<img[^>]*\bsrc=)(["\'])(?!https?:|data:|file:|\/)([^"\']+)\2/i',
            function ($m) use ($base) {
                $abs = $base . '/' . ltrim($m[3], '/');
                return is_file($abs)
                    ? $m[1] . $m[2] . 'file://' . $abs . $m[2]
                    : $m[0];
            },
            $html
        ) ?? $html;
    }

    private function memberPhone(int $memberId): string
    {
        if ($memberId <= 0) return '';
        $member = Member::find($memberId);
        if (!$member) return '';
        $mainUser = $member->users()->where('type', User::MAIN_USER)->first();
        if (!$mainUser) return '';

        $contacts = $mainUser->contacts()->with('enumType')->get();

        // 1) preferuj kontakty zařazené jako Telefon/Mobil
        $byType = $contacts->first(function ($c) {
            $type = strtolower((string) ($c->enumType?->value ?? ''));
            return str_contains($type, 'tel') || str_contains($type, 'mobil');
        });
        if ($byType) {
            return (string) $byType->value;
        }

        // 2) fallback: jakýkoli kontakt, jehož hodnota vypadá jako CZ telefon (9 číslic / +420…)
        $byValue = $contacts->first(function ($c) {
            $digits = preg_replace('/\D/', '', (string) $c->value) ?? '';
            return preg_match('/^(420)?\d{9}$/', $digits) === 1;
        });
        return (string) ($byValue?->value ?? '');
    }

    private function h($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function maskPhone(string $phone): string
    {
        $tail = substr(preg_replace('/\D/', '', $phone) ?? '', -4);
        return '****' . $tail;
    }

    private function tmpDir(): string
    {
        $cfg = (string) ($this->cfg('tmp', 'storage/app/private/tmp'));
        $abs = $this->absolutize($cfg);
        if (!is_dir($abs) && !@mkdir($abs, 0775, true) && !is_dir($abs)) {
            $abs = sys_get_temp_dir();
        }
        return rtrim($abs, '/');
    }

    private function storageDir(): string
    {
        $cfg = (string) ($this->cfg('storage', 'storage/app/private/contracts'));
        $abs = $this->absolutize($cfg);
        if (!is_dir($abs) && !@mkdir($abs, 0775, true) && !is_dir($abs)) {
            throw new \RuntimeException("Cannot create storage dir: {$abs}");
        }
        return rtrim($abs, '/');
    }

    private function absolutize(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path(ltrim($path, '/'));
    }

    private function cfg(string $key, $default = null)
    {
        $v = Setting::get($key, null);
        if ($v === null || $v === '') {
            return config("services.contracts.{$key}", $default);
        }
        return $v;
    }

    private function buildEvidenceHtml(Contract $contract, ?Request $request, bool $isTermination): string
    {
        $ip = $request?->ip() ?? '0.0.0.0';
        $ua = $request?->userAgent() ?? '';
        $cn = $this->h($contract->contract_no);
        $mid = $this->h((string) $contract->member_id);
        $phoneMask = $this->h($this->maskPhone((string) ($contract->phone ?? '')));
        $now = now()->format('Y-m-d H:i:s');
        $title = $isTermination
            ? 'Evidence odsouhlasení (SMS OTP) – Ukončení členství'
            : 'Evidence odsouhlasení (SMS OTP)';
        $note = $isTermination
            ? 'Žádost o ukončení členství byla potvrzena vložením SMS kódu a výslovným souhlasem uživatele.'
            : 'Uzavření smlouvy bylo potvrzeno zadáním SMS kódu zaslaného na výše uvedené číslo a výslovným souhlasem uživatele.';

        $ipE = $this->h($ip);
        $uaE = $this->h($ua);

        return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8">
<style>body{font-family:sans-serif;font-size:11pt}</style></head>
<body>
<h2>{$title}</h2>
<table cellpadding="4" cellspacing="0" border="0">
<tr><td><strong>Smlouva:</strong></td><td>{$cn}</td></tr>
<tr><td><strong>Member ID:</strong></td><td>{$mid}</td></tr>
<tr><td><strong>Telefon:</strong></td><td>{$phoneMask}</td></tr>
<tr><td><strong>Ověřeno:</strong></td><td>{$now}</td></tr>
<tr><td><strong>IP / UA:</strong></td><td>{$ipE} / {$uaE}</td></tr>
</table>
<p>{$note}</p>
</body></html>
HTML;
    }

    private function buildAddonEvidenceHtml(string $phoneMask, ?Request $request): string
    {
        $ip = $request?->ip() ?? '0.0.0.0';
        $ua = $request?->userAgent() ?? '';
        $now = now()->setTimezone('Europe/Prague')->format('Y-m-d H:i');

        $ipE = $this->h($ip);
        $uaE = $this->h($ua);
        $pmE = $this->h($phoneMask);

        return '<h2>Evidence elektronického podpisu dodatku</h2>'
            . '<p>Tento dodatek byl potvrzen zadáním SMS kódu (OTP).</p>'
            . '<table border="1" cellspacing="0" cellpadding="4">'
            . "<tr><td><strong>Datum a čas potvrzení</strong></td><td>{$now}</td></tr>"
            . "<tr><td><strong>IP adresa</strong></td><td>{$ipE}</td></tr>"
            . "<tr><td><strong>User-Agent</strong></td><td>{$uaE}</td></tr>"
            . "<tr><td><strong>Maskované telefonní číslo</strong></td><td>{$pmE}</td></tr>"
            . '</table>';
    }

    /**
     * Sign the unsigned PDF with the configured PFX certificate. Returns true on success.
     * Silently returns false if the cert is missing or signing fails (caller falls back to unsigned PDF).
     */
    private function signPdf(string $unsigned, string $signedTemp): bool
    {
        $pfxPathCfg = (string) $this->cfg('pdf_sign_cert', '');
        $pfxPath    = $pfxPathCfg !== '' ? $this->absolutize($pfxPathCfg) : '';
        $pfxPass    = (string) $this->cfg('pdf_sign_pass', '');
        $sigName    = (string) $this->cfg('pdf_sign_name', 'Pvfree.net, z. s.');
        $sigLoc     = (string) $this->cfg('pdf_sign_location', 'Prostějov, CZ');
        $sigReas    = (string) $this->cfg('pdf_sign_reason', 'Podepsáno poskytovatelem');

        if (!is_file($pfxPath) || $pfxPass === '' || !function_exists('openssl_pkcs12_read')) {
            return false;
        }

        try {
            $pkcs12 = file_get_contents($pfxPath);
            $certs  = [];
            if (!openssl_pkcs12_read($pkcs12, $certs, $pfxPass)) {
                throw new \RuntimeException('Invalid PFX or password.');
            }
            if (empty($certs['cert']) || empty($certs['pkey'])) {
                throw new \RuntimeException('PFX missing cert or pkey.');
            }

            $tmpDir  = $this->tmpDir();
            $pemCert = $tmpDir . '/pvfree-cert-' . bin2hex(random_bytes(6)) . '.pem';
            $pemKey  = $tmpDir . '/pvfree-key-'  . bin2hex(random_bytes(6)) . '.pem';
            file_put_contents($pemCert, $certs['cert']);
            file_put_contents($pemKey,  $certs['pkey']);
            @chmod($pemCert, 0600);
            @chmod($pemKey,  0600);

            $pdf = new Fpdi();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->setSignature(
                'file://' . $pemCert,
                'file://' . $pemKey,
                '',
                '',
                2,
                [
                    'Name'        => $sigName,
                    'Location'    => $sigLoc,
                    'Reason'      => $sigReas,
                    'ContactInfo' => 'www.pvfree.net',
                ]
            );

            $pageCount = $pdf->setSourceFile($unsigned);
            for ($i = 1; $i <= $pageCount; $i++) {
                $tplIdx = $pdf->importPage($i);
                $size   = $pdf->getTemplateSize($tplIdx);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tplIdx);
            }

            $pdf->Output($signedTemp, 'F');
            @unlink($pemCert);
            @unlink($pemKey);
            return true;
        } catch (\Throwable $e) {
            \Log::warning('[contract-app] PDF signing failed: ' . $e->getMessage());
            return false;
        }
    }
}
