<?php

namespace App\Http\Controllers\Contracts;

use App\Domains\Contracts\Actions\FinalizeContractSignAction;
use App\Domains\Contracts\Actions\FinalizeLegacyAddonSignAction;
use App\Domains\Contracts\Actions\SignAddonAction;
use App\Domains\Contracts\Exceptions\ContractSigningFailed;
use App\Domains\Contracts\Otp\OtpService;
use App\Domains\Contracts\Pdf\PdfService;
use App\Domains\Contracts\Repositories\ContractAddonRepository;
use App\Domains\Contracts\Repositories\ContractRepository;
use App\Domains\Contracts\Repositories\DownloadTokenRepository;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\PostSignEmails;
use App\Domains\Contracts\Support\SignLinkToken;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractAddon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicSignController extends Controller
{
    public function __construct(
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

        $party = app(ContractRepository::class)->latestParty((int) $contract->id);

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
     * POST /sign/finalize — podpis smlouvy (PDF, aktivace člena, e-mail).
     * Vrací jednorázový odkaz ke stažení (24 h).
     */
    public function finalizeContract(Request $request, FinalizeContractSignAction $finalize): JsonResponse
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return $this->unauthorizedJson();

        return $this->exclusively('contract:' . $contract->id, function () use ($contract, $request, $finalize) {
            $contract->refresh();

            // Opakované volání (obnova stránky, druhý klik, starý odkaz) už podepsanou
            // smlouvu nepřepíše ani znovu neaktivuje/neposílá e-mail — jen nový odkaz
            // ke stažení uloženého PDF.
            if ($contract->status === 'signed') {
                if (!$contract->pdf_path || !is_file($contract->pdf_path)) {
                    return response()->json(['error' => 'Smlouva je již podepsaná.'], 409);
                }
                return $this->signedResponse($contract->id, 'contract', bin2hex((string) $contract->pdf_sha256));
            }
            if ($contract->status !== 'otp_verified') {
                return response()->json(['error' => 'Smlouva nebyla ověřena.'], 409);
            }

            try {
                $sha256 = $finalize->handle($contract, $request);
            } catch (ContractSigningFailed $e) {
                return $this->serverError($e);
            }

            return $this->signedResponse($contract->id, 'contract', $sha256);
        });
    }

    /**
     * GET /sign/download?t={dlToken} — one-time PDF download.
     */
    public function downloadContract(Request $request, DownloadTokenRepository $tokens)
    {
        $token = (string) $request->query('t', '');
        if ($token === '') return response('Missing token', 400);

        $row = $tokens->find($token);
        if (!$row || $row->used_at !== null || strtotime($row->expires_at) < time()) {
            return response('Odkaz vypršel nebo byl již použit.', 410);
        }
        if (!empty($row->bind_ip) && $row->bind_ip !== $request->ip()) {
            return response('Odkaz není platný z této IP.', 403);
        }

        $contract = app(ContractRepository::class)->find((int) $row->contract_id);
        if (!$contract) return response('Contract not found', 404);

        $path = match ($row->file_type) {
            'contract'    => $contract->pdf_path,
            'addon'       => $contract->addon_pdf_path,
            'tariff_addon', 'service_addon', 'connection_point_addon'
                          => ($row->addon_id ?? null) ? app(ContractAddonRepository::class)->findAny((int) $row->addon_id)?->pdf_path : null,
            default       => null,
        };
        if (!$path || !is_file($path)) return response('PDF nedostupné.', 404);

        $tokens->markUsed((int) $row->id);

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

    public function verifyAddonOtp(Request $request, FinalizeLegacyAddonSignAction $finalize): JsonResponse
    {
        $contract = $this->resolveContract($request);
        if (!$contract) return $this->unauthorizedJson();

        return $this->exclusively('legacy_addon:' . $contract->id, function () use ($contract, $request, $finalize) {
            $contract->refresh();
            if ((int) $contract->addon_signed === 1) {
                return response()->json(['error' => 'Dodatek je již podepsán.'], 409);
            }

            $otp = trim((string) $request->input('otp', ''));
            $res = $this->otp->verifyAddonOtp($contract, $otp, $request);
            if (!($res['ok'] ?? false)) {
                return $this->fromServiceResult($res);
            }

            try {
                $sha256 = $finalize->handle($contract, $request);
            } catch (ContractSigningFailed $e) {
                return $this->serverError($e);
            }

            return $this->signedResponse($contract->id, 'addon', $sha256);
        });
    }

    // ── Dodatky tarif / služba / přípojné místo (contract_addons) ───────────

    public function showTariffAddon(Request $request)            { return $this->addonView($request, AddonType::TariffChange); }
    public function tariffAddonInfo(Request $request): JsonResponse { return $this->addonInfo($request, AddonType::TariffChange); }
    public function previewTariffAddon(Request $request): Response|BinaryFileResponse  { return $this->addonPreview($request, AddonType::TariffChange); }
    public function verifyTariffAddonOtp(Request $request): JsonResponse { return $this->verifyTypedAddonOtp($request, AddonType::TariffChange); }

    public function showServiceAddon(Request $request)            { return $this->addonView($request, AddonType::AdditionalService); }
    public function serviceAddonInfo(Request $request): JsonResponse { return $this->addonInfo($request, AddonType::AdditionalService); }
    public function previewServiceAddon(Request $request): Response|BinaryFileResponse  { return $this->addonPreview($request, AddonType::AdditionalService); }
    public function verifyServiceAddonOtp(Request $request): JsonResponse { return $this->verifyTypedAddonOtp($request, AddonType::AdditionalService); }

    public function showConnectionPointAddon(Request $request)            { return $this->addonView($request, AddonType::ConnectionPoint); }
    public function connectionPointAddonInfo(Request $request): JsonResponse { return $this->addonInfo($request, AddonType::ConnectionPoint); }
    public function previewConnectionPointAddon(Request $request): Response|BinaryFileResponse  { return $this->addonPreview($request, AddonType::ConnectionPoint); }
    public function verifyConnectionPointAddonOtp(Request $request): JsonResponse { return $this->verifyTypedAddonOtp($request, AddonType::ConnectionPoint); }

    private function addonView(Request $request, AddonType $type)
    {
        return view($type->signView(), ['token' => (string) $request->query('t', '')]);
    }

    private function addonInfo(Request $request, AddonType $type): JsonResponse
    {
        $addon = $this->resolveAddon($request, $type);
        if (!$addon) return $this->unauthorizedJson();

        $contract = $addon->contract;
        $party    = app(ContractRepository::class)->latestParty((int) $addon->contract_id);

        return response()->json([
            'contract_no'     => $contract?->contract_no,
            'full_name'       => $party?->full_name,
            'variable_symbol' => $party?->variable_symbol,
            'phone_mask'      => $contract?->phone ? $this->maskPhone((string) $contract->phone) : null,
        ] + $type->infoFields($addon) + [
            'signed'          => $addon->status === 'signed' ? 1 : 0,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** Náhled: podepsaný = uložené PDF, jinak vykreslený návrh. */
    private function addonPreview(Request $request, AddonType $type): Response|BinaryFileResponse
    {
        $addon = $this->resolveAddon($request, $type);
        if (!$addon) return response('', 401);

        if ($addon->status === 'signed' && $addon->pdf_path && is_file($addon->pdf_path)) {
            return response()->file($addon->pdf_path, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $type->previewFileBase() . '.pdf"',
                'Cache-Control'       => 'no-store',
            ]);
        }

        return response($type->renderPdf($this->pdf, $addon, true, $request), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $type->previewFileBase() . '-preview.pdf"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** OTP (cid i aid z téhož tokenu, dodatek patří smlouvě) → podpis → e-mail → odkaz ke stažení. */
    private function verifyTypedAddonOtp(Request $request, AddonType $type): JsonResponse
    {
        $contract = $this->resolveContract($request);
        $addon    = $this->resolveAddon($request, $type);
        if (!$contract || !$addon || (int) $addon->contract_id !== (int) $contract->id) {
            return $this->unauthorizedJson();
        }
        // Dva souběžné podpisy by dopad (tarif, přiřazení placené služby) provedly dvakrát.
        return $this->exclusively('addon:' . $addon->id, function () use ($contract, $addon, $type, $request) {
            $addon->refresh();
            if ($addon->status === 'signed') {
                return response()->json(['error' => 'Dodatek je již podepsán.'], 409);
            }

            $otp = trim((string) $request->input('otp', ''));
            $res = $this->otp->verifyAddonOtp($contract, $otp, $request);
            if (!($res['ok'] ?? false)) {
                return $this->fromServiceResult($res);
            }

            try {
                $addon = app(SignAddonAction::class)->handle($addon, $type, $this->pdf, $request);
            } catch (\Throwable $e) {
                return $this->serverError($e);
            }

            app(PostSignEmails::class)->addon($contract, $addon, $type);

            $dlToken = app(DownloadTokenRepository::class)->issue($contract->id, $type->downloadFileType(), 86400, null, 1, $addon->id);
            return response()->json([
                'ok'           => true,
                'download_url' => route('sign.download', ['t' => $dlToken]),
            ]);
        });
    }

    private function resolveAddon(Request $request, AddonType $type): ?ContractAddon
    {
        $token = (string) ($request->input('t') ?: $request->query('t', ''));
        if ($token === '') return null;
        $aid = app(SignLinkToken::class)->addonId($token);
        if (!$aid) return null;
        return app(ContractAddonRepository::class)->find($aid, $type);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function resolveContract(Request $request): ?Contract
    {
        $token = (string) ($request->input('t') ?: $request->query('t', ''));
        if ($token === '') return null;
        $cid = app(SignLinkToken::class)->contractId($token);
        if (!$cid) return null;
        return app(ContractRepository::class)->find((int) $cid);
    }

    /**
     * Podpis jednoho dokumentu najednou — souběžný požadavek (dvojklik, dvě
     * záložky) dostane 409 místo druhého PDF, aktivace a e-mailu.
     */
    private function exclusively(string $key, \Closure $fn): JsonResponse
    {
        $lock = Cache::lock('contract_sign:' . $key, 120);
        if (!$lock->get()) {
            return response()->json(['error' => 'Podpis se právě zpracovává, zkuste to za chvíli.'], 409);
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /** Úspěšný podpis: otisk + jednorázový odkaz ke stažení (24 h). */
    private function signedResponse(int $contractId, string $fileType, string $sha256): JsonResponse
    {
        $dlToken = app(DownloadTokenRepository::class)->issue($contractId, $fileType, 86400, null, 1);
        return response()->json([
            'ok'           => true,
            'pdf_sha256'   => $sha256,
            'download_url' => route('sign.download', ['t' => $dlToken]),
        ]);
    }

    private function serverError(\Throwable $e): JsonResponse
    {
        return response()->json(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
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

}
