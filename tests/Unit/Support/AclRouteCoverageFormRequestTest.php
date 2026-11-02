<?php

namespace Tests\Unit\Support;

use App\Http\Requests\Members\StoreMemberRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Support\AclRouteCoverage;
use Illuminate\Foundation\Http\FormRequest;
use PHPUnit\Framework\TestCase;

/** Skener pokrytí ACL uzná Form Request jen s authorize(), které opravdu kontroluje ACL. */
class AclRouteCoverageFormRequestTest extends TestCase
{
    public function test_clenske_requesty_kontroluji_acl(): void
    {
        $this->assertTrue(AclRouteCoverage::formRequestChecksAcl(StoreMemberRequest::class));
        $this->assertTrue(AclRouteCoverage::formRequestChecksAcl(UpdateMemberRequest::class));
    }

    public function test_bez_authorize_nebo_return_true_neni_ochrana(): void
    {
        $this->assertFalse(AclRouteCoverage::formRequestChecksAcl(AclCoverageNoAuthorizeRequest::class));
        $this->assertFalse(AclRouteCoverage::formRequestChecksAcl(AclCoverageAllowAllRequest::class));
    }

    /** Kontrola existence (404/401) ani marker v komentáři nejsou autorizace. */
    public function test_marker_autorizace_v_tele_metody(): void
    {
        foreach ([
            'abort_if(!$m, 404);',
            'abort_unless($user, 401);',
            '// TODO aclCheck tady chybí',
            "/* abort_unless(\$this->can('edit_all'), 403) */ return 1;",
            'abort(404);',
        ] as $src) {
            $this->assertFalse(AclRouteCoverage::hasMarker($src), $src);
        }
        foreach ([
            "abort_unless(\$this->can('edit_all'), 403);",
            "if (!\$this->aclCheck('view_all', 'X', 'y')) { abort(403); }",
            'abort_if(!$ok, Response::HTTP_FORBIDDEN);',
            'abort(403);',
            "\$this->authorize('x');",
        ] as $src) {
            $this->assertTrue(AclRouteCoverage::hasMarker($src), $src);
        }
    }
}

class AclCoverageNoAuthorizeRequest extends FormRequest
{
}

class AclCoverageAllowAllRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** hasAccess( mimo authorize() se nepočítá */
    public function unrelated(): void
    {
    }
}
