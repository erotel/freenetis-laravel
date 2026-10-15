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
