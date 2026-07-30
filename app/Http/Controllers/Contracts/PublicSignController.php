<?php

namespace App\Http\Controllers\Contracts;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\ContractEvent;
use App\Models\ContractParty;
use App\Models\EmailQueue;
use App\Models\EmailQueueAttachment;
use App\Models\Member;
use App\Models\Message;
use App\Models\Setting;
use App\Services\Contracts\OtpService;
use App\Services\Contracts\PdfService;
use App\Services\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PublicSignController extends Controller
{
    public function __construct(
        private ContractService $contracts,
        private OtpService $otp,
        private PdfService $pdf,
    ) {}

    /**
     * GET /sign/contract?t={token} — render the SPA. Token is validated client-side
     * by calling /sign/info, which returns either the contract data or 401.
     */
    public function showContract(Request $request)
    {
        $token = (string) $request->query('t', '');
        return view('contracts.sign', ['token' => $token]);
    }

    /**
     * POST /sign/info — resolve token + return contract data for the SPA.
     */
    public function info(Request $request): JsonResponse
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return $this->unauthorizedJson();

        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();

        return response()->json([
            'id'          => (int) $contract->id,
            'member_id'   => (int) $contract->member_id,
            'contract_no' => $contract->contract_no,
            'status'      => $contract->status,
            'phone_mask'  => $contract->phone ? $this->maskPhone((string) $contract->phone) : null,
            'full_name'   => $party?->full_name,
            'variable_symbol' => $party?->variable_symbol,
            'has_pdf'     => !empty($contract->pdf_path),
            'addon'       => (int) $contract->addon,
            'addon_signed' => (int) $contract->addon_signed,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function previewContract(Request $request): Response
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return response('', 401);
        $pdf = $this->pdf->renderContractPdf($contract, true, $request);
        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="contract-preview.pdf"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * GET /sign/addon?t={token} — render the addon-sign SPA. Token validated by /sign/info.
     */
    public function showAddon(Request $request)
    {
        $token = (string) $request->query('t', '');
        return view('contracts.addon-sign', ['token' => $token]);
    }

    /**
     * GET /sign/addon/preview?t={token} — inline addon PDF preview.
     */
    public function previewAddon(Request $request): Response
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return response('', 401);
        $pdf = $this->pdf->renderAddonPdf($contract, true, $request);
        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="addon-preview.pdf"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return $this->unauthorizedJson();

        $phone = trim((string) $request->input('phone', ''));
        if ($phone === '') {
            return response()->json(['error' => 'Chybí telefon.'], 400);
        }

        $res = $this->otp->sendOtp($contract, $phone, $request);
        return $this->fromServiceResult($res);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return $this->unauthorizedJson();

        $otp = trim((string) $request->input('otp', ''));
        $res = $this->otp->verifyOtp($contract, $otp, $request);
        return $this->fromServiceResult($res);
    }

    /**
     * POST /sign/finalize — generate, sign, store the final contract PDF.
     * Returns a one-time download token URL.
     */
    public function finalizeContract(Request $request): JsonResponse
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return $this->unauthorizedJson();

        if (!in_array($contract->status, ['otp_verified', 'signed'], true)) {
            return response()->json(['error' => 'Smlouva nebyla ověřena.'], 409);
        }

        try {
            // Mark party as not-terminated when finalizing
            ContractParty::where('contract_id', $contract->id)->update(['termination' => 'ne']);

            $fullpath = $this->pdf->renderContractPdf($contract, false, $request);
            $raw      = (string) file_get_contents($fullpath);
            $sha256   = hash('sha256', $raw);

            DB::connection('contracts')->beginTransaction();
            DB::connection('contracts')->table('contracts')
                ->where('id', $contract->id)
                ->update([
                    'status'     => 'signed',
                    'signed_at'  => now(),
                    'pdf_path'   => $fullpath,
                    'pdf_sha256' => hash('sha256', $raw, true),
                ]);

            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'pdf_generated',
                'meta_json'   => json_encode(['sha256' => $sha256], JSON_UNESCAPED_UNICODE),
            ]);
            DB::connection('contracts')->commit();

            // Sync in-memory model — query builder update nehydratuje Eloquent atributy,
            // a sendPostSignEmail() i členská synchronizace dál čtou z $contract.
            $contract->status    = 'signed';
            $contract->signed_at = now();
            $contract->pdf_path  = $fullpath;
        } catch (\Throwable $e) {
            if (DB::connection('contracts')->transactionLevel() > 0) {
                DB::connection('contracts')->rollBack();
            }
            return response()->json(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
        }

        // Synchronizace s FreenetIS — aktivace člena po podpisu smlouvy.
        // Typ 17 (čekající člen) má vlastní ruční workflow s přihláškou — ten neměníme.
        $member = Member::find($contract->member_id);
        if ($member) {
            $oldType = $member->type;
            $updates = ['registration' => 1];
            if (in_array($member->type, [1, 18], true)) {
                $updates['type'] = 2;
            }
            $member->update($updates);

            // Po přechodu type 18 → 2 okamžitě uvolnit IP z přesměrování
            // (jinak by zákazník čekal až na další běh cronu, max 1 h).
            if ($oldType !== ($updates['type'] ?? $oldType)) {
                app(\App\Services\PendingCustomerRedirectService::class)->refreshForMember($member->id);
            }

            DB::connection('contracts')->table('contract_events')->insert([
                'contract_id' => $contract->id,
                'event'       => 'member_activated',
                'meta_json'   => json_encode([
                    'member_id'    => $member->id,
                    'old_type'     => $oldType,
                    'new_type'     => $updates['type'] ?? $oldType,
                    'registration' => 1,
                ], JSON_UNESCAPED_UNICODE),
                'created_at'  => now(),
            ]);
        }

        $this->sendPostSignEmail($contract);

        $dlToken = $this->createDownloadToken($contract->id, 'contract', 86400, null, 1);
        return response()->json([
            'ok'           => true,
            'pdf_sha256'   => $sha256,
            'download_url' => route('sign.download', ['t' => $dlToken]),
        ]);
    }

    /**
     * Queue an email to the customer with the signed contract, ceník and VOP attached.
     * Silently no-op if disabled, no email on file, or contract PDF missing.
     */
    private function sendPostSignEmail(Contract $contract): void
    {
        if (!Setting::get('contract_email_attachments_enabled', 0)) {
            return;
        }

        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $email = trim((string) ($party?->email ?? ''));
        if ($email === '') {
            return;
        }

        $contractPdf = (string) $contract->pdf_path;
        if ($contractPdf === '' || !is_file($contractPdf)) {
            return;
        }

        $attachments = [
            ['path' => $contractPdf, 'name' => 'smlouva-' . $contract->id . '.pdf'],
        ];
        foreach ([
            'contract_email_pricelist_pdf' => 'cenik.pdf',
            'contract_email_vop_pdf'       => 'vop.pdf',
        ] as $settingKey => $attachName) {
            $cfg = trim((string) Setting::get($settingKey, ''));
            if ($cfg === '') continue;
            $abs = str_starts_with($cfg, '/') ? $cfg : base_path(ltrim($cfg, '/'));
            if (is_file($abs)) {
                $attachments[] = ['path' => $abs, 'name' => $attachName];
            }
        }

        try {
            [$subject, $body] = $this->renderContractEmail(
                Message::CONTRACT_SIGNED,
                (int) $contract->member_id,
                (string) $contract->contract_no,
                fn(string $contractNoHtml) => 'Podepsaná smlouva ' . $contract->contract_no . ' - PVfree.net',
                fn(string $contractNoHtml) => '<p>Dobrý den,</p>'
                    . '<p>v příloze Vám zasíláme podepsanou smlouvu <strong>' . $contractNoHtml . '</strong>'
                    . ', ceník služeb a Všeobecné obchodní podmínky.</p>'
                    . '<p>Dokumenty si prosím uschovejte pro svoji evidenci.</p>'
                    . '<p>S pozdravem,<br>PVfree.net, z.s.</p>'
            );

            $emailQueue = EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);

            foreach ($attachments as $a) {
                EmailQueueAttachment::create([
                    'email_queue_id' => $emailQueue->id,
                    'path'           => $a['path'],
                    'name'           => $a['name'],
                    'mime'           => 'application/pdf',
                    'created_at'     => now(),
                ]);
            }

            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'post_sign_email_sent',
                'meta_json'   => json_encode([
                    'to'          => $email,
                    'attachments' => array_column($attachments, 'name'),
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Post-sign email queue failed for contract #' . $contract->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Resolve the email subject + body for a contract-related message type.
     * Loads the admin-editable template from messages table; if missing/empty,
     * falls back to the hardcoded $subjectFallback / $bodyFallback closures
     * (both receive HTML-escaped contract_no). Podporuje {contract_no} i
     * standardní member-placeholdery ({variable_symbol}, {member_name}, …)
     * přes Message::buildPlaceholders — jinak by v šabloně zůstaly nevyplněné.
     *
     * @return array{0:string,1:string} [subject, body]
     */
    private function renderContractEmail(int $type, int $memberId, string $contractNo, \Closure $subjectFallback, \Closure $bodyFallback): array
    {
        $contractNoHtml = htmlspecialchars($contractNo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tpl = Message::where('type', $type)->first();

        if ($tpl && trim((string) $tpl->email_text) !== '') {
            $prefix  = (string) Setting::get('email_subject_prefix', '');
            $subject = ($prefix !== '' ? $prefix . ' :: ' : '') . $tpl->name;
            $body    = Message::substitute(
                $tpl->email_text,
                Message::buildPlaceholders($memberId, ['contract_no' => $contractNo])
            );
            return [$subject, $body];
        }

        return [$subjectFallback($contractNoHtml), $bodyFallback($contractNoHtml)];
    }

    /**
     * Queue an email to the customer with the signed addon attached.
     * Silently no-op if no email on file or addon PDF missing.
     */
    private function sendAddonPostSignEmail(Contract $contract): void
    {
        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $email = trim((string) ($party?->email ?? ''));
        if ($email === '') {
            return;
        }

        $addonPdf = (string) $contract->addon_pdf_path;
        if ($addonPdf === '' || !is_file($addonPdf)) {
            return;
        }

        try {
            [$subject, $body] = $this->renderContractEmail(
                Message::CONTRACT_ADDON_SIGNED,
                (int) $contract->member_id,
                (string) $contract->contract_no,
                fn(string $contractNoHtml) => 'Podepsaný dodatek ke smlouvě ' . $contract->contract_no . ' - PVfree.net',
                fn(string $contractNoHtml) => '<p>Dobrý den,</p>'
                    . '<p>v příloze Vám zasíláme podepsaný dodatek ke smlouvě <strong>' . $contractNoHtml . '</strong>.</p>'
                    . '<p>Dokument si prosím uschovejte pro svoji evidenci.</p>'
                    . '<p>S pozdravem,<br>PVfree.net, z.s.</p>'
            );

            $emailQueue = EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);

            EmailQueueAttachment::create([
                'email_queue_id' => $emailQueue->id,
                'path'           => $addonPdf,
                'name'           => 'dodatek-' . $contract->contract_no . '.pdf',
                'mime'           => 'application/pdf',
                'created_at'     => now(),
            ]);

            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'addon_post_sign_email_sent',
                'meta_json'   => json_encode([
                    'to' => $email,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Addon post-sign email queue failed for contract #' . $contract->id . ': ' . $e->getMessage());
        }
    }

    /**
     * GET /sign/download?t={dlToken} — one-time PDF download.
     */
    public function downloadContract(Request $request)
    {
        $token = (string) $request->query('t', '');
        if ($token === '') return response('Missing token', 400);

        $row = DB::connection('contracts')->table('download_tokens')->where('token', $token)->first();
        if (!$row || $row->used_at !== null || strtotime($row->expires_at) < time()) {
            return response('Odkaz vypršel nebo byl již použit.', 410);
        }
        if (!empty($row->bind_ip) && $row->bind_ip !== $request->ip()) {
            return response('Odkaz není platný z této IP.', 403);
        }

        $contract = Contract::find($row->contract_id);
        if (!$contract) return response('Contract not found', 404);

        $path = match ($row->file_type) {
            'contract'     => $contract->pdf_path,
            'addon'        => $contract->addon_pdf_path,
            'termination'  => $this->terminationPath($contract->id),
            'tariff_addon' => ($row->addon_id ?? null) ? ContractAddon::find($row->addon_id)?->pdf_path : null,
            default        => null,
        };
        if (!$path || !is_file($path)) return response('PDF nedostupné.', 404);

        DB::connection('contracts')->table('download_tokens')
            ->where('id', $row->id)
            ->update(['used_at' => now()]);

        return response()->file($path, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }

    public function sendAddonOtp(Request $request): JsonResponse
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return $this->unauthorizedJson();

        $res = $this->otp->sendAddonOtp($contract, $request);
        return $this->fromServiceResult($res);
    }

    public function verifyAddonOtp(Request $request): JsonResponse
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return $this->unauthorizedJson();

        $otp = trim((string) $request->input('otp', ''));
        $res = $this->otp->verifyAddonOtp($contract, $otp, $request);
        if (!($res['ok'] ?? false)) {
            return $this->fromServiceResult($res);
        }

        try {
            $fullpath = $this->pdf->renderAddonPdf($contract, false, $request);
            $raw      = (string) file_get_contents($fullpath);
            $sha256   = hash('sha256', $raw);

            DB::connection('contracts')->beginTransaction();
            DB::connection('contracts')->table('contracts')
                ->where('id', $contract->id)
                ->update([
                    'addon_signed'    => 1,
                    'addon_signed_at' => now(),
                    'addon_pdf_path'  => $fullpath,
                ]);
            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'addon_generated',
                'meta_json'   => json_encode(['sha256' => $sha256], JSON_UNESCAPED_UNICODE),
            ]);
            DB::connection('contracts')->commit();

            // Sync in-memory — query builder update nehydratuje Eloquent atributy.
            $contract->addon_signed    = 1;
            $contract->addon_signed_at = now();
            $contract->addon_pdf_path  = $fullpath;
        } catch (\Throwable $e) {
            if (DB::connection('contracts')->transactionLevel() > 0) {
                DB::connection('contracts')->rollBack();
            }
            return response()->json(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
        }

        $this->sendAddonPostSignEmail($contract);

        $dlToken = $this->createDownloadToken($contract->id, 'addon', 86400, null, 1);
        return response()->json([
            'ok'           => true,
            'pdf_sha256'   => $sha256,
            'download_url' => route('sign.download', ['t' => $dlToken]),
        ]);
    }

    public function finalizeTermination(Request $request): JsonResponse
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return $this->unauthorizedJson();

        if (!in_array($contract->status, ['otp_verified', 'signed'], true)) {
            return response()->json(['error' => 'Smlouva nebyla ověřena.'], 409);
        }

        try {
            $fullpath = $this->pdf->renderTerminationPdf($contract, $request);
            $raw      = (string) file_get_contents($fullpath);
            $sha256   = hash('sha256', $raw);

            DB::connection('contracts')->beginTransaction();
            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'termination_generated',
                'meta_json'   => json_encode(['sha256' => $sha256], JSON_UNESCAPED_UNICODE),
            ]);
            ContractParty::where('contract_id', $contract->id)->update(['termination' => 'ano']);
            DB::connection('contracts')->commit();
        } catch (\Throwable $e) {
            if (DB::connection('contracts')->transactionLevel() > 0) {
                DB::connection('contracts')->rollBack();
            }
            return response()->json(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
        }

        $dlToken = $this->createDownloadToken($contract->id, 'termination', 86400, null, 1);
        return response()->json([
            'ok'           => true,
            'pdf_sha256'   => $sha256,
            'download_url' => route('sign.download', ['t' => $dlToken]),
        ]);
    }

    // ── Dodatek: změna tarifu (contract_addons) ─────────────────────────────

    public function showTariffAddon(Request $request)
    {
        $token = (string) $request->query('t', '');
        return view('contracts.tariff-addon-sign', ['token' => $token]);
    }

    public function tariffAddonInfo(Request $request): JsonResponse
    {
        $addon = $this->resolveTariffAddon($request);
        if (!$addon) return $this->unauthorizedJson();

        $contract = $addon->contract;
        $party    = ContractParty::where('contract_id', $addon->contract_id)->orderByDesc('id')->first();

        return response()->json([
            'contract_no'     => $contract?->contract_no,
            'full_name'       => $party?->full_name,
            'variable_symbol' => $party?->variable_symbol,
            'phone_mask'      => $contract?->phone ? $this->maskPhone((string) $contract->phone) : null,
            'signed'          => $addon->status === 'signed' ? 1 : 0,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function previewTariffAddon(Request $request): Response
    {
        $addon = $this->resolveTariffAddon($request);
        if (!$addon) return response('', 401);

        if ($addon->status === 'signed' && $addon->pdf_path && is_file($addon->pdf_path)) {
            return response()->file($addon->pdf_path, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="dodatek.pdf"',
                'Cache-Control'       => 'no-store',
            ]);
        }

        $pdf = $this->pdf->renderTariffAddonPdf($addon, true, $request);
        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="dodatek-preview.pdf"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function verifyTariffAddonOtp(Request $request): JsonResponse
    {
        $contract = $this->resolveContract($request);      // cid z tokenu
        $addon    = $this->resolveTariffAddon($request);   // aid z tokenu
        if (!$contract || !$addon || (int) $addon->contract_id !== (int) $contract->id) {
            return $this->unauthorizedJson();
        }
        if ($addon->status === 'signed') {
            return response()->json(['error' => 'Dodatek je již podepsán.'], 409);
        }

        $otp = trim((string) $request->input('otp', ''));
        $res = $this->otp->verifyAddonOtp($contract, $otp, $request);
        if (!($res['ok'] ?? false)) {
            return $this->fromServiceResult($res);
        }

        try {
            $addon = $this->contracts->signTariffAddon($addon, $this->pdf, $request);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
        }

        $this->sendTariffAddonPostSignEmail($contract, $addon);

        $dlToken = $this->createDownloadToken($contract->id, 'tariff_addon', 86400, null, 1, $addon->id);
        return response()->json([
            'ok'           => true,
            'download_url' => route('sign.download', ['t' => $dlToken]),
        ]);
    }

    private function resolveTariffAddon(Request $request): ?ContractAddon
    {
        $token = (string) ($request->input('t') ?: $request->query('t', ''));
        if ($token === '') return null;
        $aid = $this->contracts->verifyTariffAddonToken($token);
        if (!$aid) return null;
        return $this->contracts->getTariffAddon($aid);
    }

    private function sendTariffAddonPostSignEmail(Contract $contract, ContractAddon $addon): void
    {
        $party = ContractParty::where('contract_id', $contract->id)->orderByDesc('id')->first();
        $email = trim((string) ($party?->email ?? ''));
        if ($email === '') return;

        $pdfPath = (string) $addon->pdf_path;
        if ($pdfPath === '' || !is_file($pdfPath)) return;

        try {
            [$subject, $body] = $this->renderContractEmail(
                Message::CONTRACT_ADDON_SIGNED,
                (int) $contract->member_id,
                (string) $contract->contract_no,
                fn(string $h) => 'Podepsaný dodatek (změna tarifu) ke smlouvě ' . $contract->contract_no . ' - PVfree.net',
                fn(string $h) => '<p>Dobrý den,</p>'
                    . '<p>v příloze Vám zasíláme podepsaný dodatek (změna tarifu) ke smlouvě <strong>' . $h . '</strong>.</p>'
                    . '<p>Dokument si prosím uschovejte pro svoji evidenci.</p>'
                    . '<p>S pozdravem,<br>PVfree.net, z.s.</p>'
            );

            $q = EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);
            EmailQueueAttachment::create([
                'email_queue_id' => $q->id,
                'path'           => $pdfPath,
                'name'           => 'dodatek-tarif-' . $contract->contract_no . '.pdf',
                'mime'           => 'application/pdf',
                'created_at'     => now(),
            ]);
            ContractEvent::create([
                'contract_id' => $contract->id,
                'event'       => 'tariff_addon_post_sign_email_sent',
                'meta_json'   => json_encode(['to' => $email, 'addon_id' => $addon->id], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Tariff addon post-sign email failed for contract #' . $contract->id . ': ' . $e->getMessage());
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function resolveContract(Request $request): ?Contract
    {
        $token = (string) ($request->input('t') ?: $request->query('t', ''));
        if ($token === '') return null;
        $cid = $this->contracts->verifyAccessToken($token);
        if (!$cid) return null;
        return Contract::find($cid);
    }

    private function unauthorizedJson(): JsonResponse
    {
        return response()->json(['error' => 'Neplatný nebo expirovaný odkaz.'], 401);
    }

    private function fromServiceResult(array $res): JsonResponse
    {
        $status = (int) ($res['status'] ?? ($res['ok'] ?? false ? 200 : 400));
        unset($res['status']);
        return response()->json($res, $status, [], JSON_UNESCAPED_UNICODE);
    }

    private function maskPhone(string $phone): string
    {
        $tail = substr(preg_replace('/\D/', '', $phone) ?? '', -4);
        return '****' . $tail;
    }

    private function terminationPath(int $contractId): ?string
    {
        $cfg  = (string) (\App\Models\Setting::get('storage', null) ?? config('services.contracts.storage', 'storage/app/private/contracts'));
        $base = str_starts_with($cfg, '/') ? $cfg : base_path(ltrim($cfg, '/'));
        $path = rtrim($base, '/') . "/termination-{$contractId}.pdf";
        return is_file($path) ? $path : null;
    }

    private function createDownloadToken(int $contractId, string $fileType, int $ttl, ?string $bindIp, int $maxUses, ?int $addonId = null): string
    {
        $token = bin2hex(random_bytes(32));
        DB::connection('contracts')->table('download_tokens')->insert([
            'token'       => $token,
            'contract_id' => $contractId,
            'addon_id'    => $addonId,
            'file_type'   => $fileType,
            'expires_at'  => now()->addSeconds($ttl),
            'bind_ip'     => $bindIp,
            'max_uses'    => $maxUses,
            'created_at'  => now(),
        ]);
        return $token;
    }
}
