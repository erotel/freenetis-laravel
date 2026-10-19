<?php

namespace App\Http\Requests\Members;

use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Repositories\MembershipInterruptRepository;
use App\Domains\Members\Support\InterruptPeriodPolicy;
use App\Services\AclService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Nové (routa {memberId}) / upravené (routa {id}) přerušení členství.
 * Pořadí jako dřív: nové = ACL new_all → člen (404); úprava = přerušení
 * (404) → ACL edit_all. Pak validace polí a pravidel intervalu.
 */
class SaveMembershipInterruptRequest extends FormRequest
{
    private ?object $interrupt = null;

    public function authorize(): bool
    {
        $acl = app(AclService::class);
        $uid = (int) $this->user()?->id;

        if ($this->route('id') !== null) {
            $this->interrupt = app(MembershipInterruptRepository::class)->find((int) $this->route('id'));
            abort_if(!$this->interrupt, 404);
            return $acl->hasAccess($uid, 'edit_all', 'Members_Controller', 'membership_interrupts');
        }

        if (!$acl->hasAccess($uid, 'new_all', 'Members_Controller', 'membership_interrupts')) {
            return false;
        }
        abort_if(!app(MemberRepository::class)->row((int) $this->route('memberId')), 404);
        return true;
    }

    public function rules(): array
    {
        return [
            'from'    => 'required|date',
            'to'      => 'required|date',
            'comment' => 'required|string|max:250',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $error = app(InterruptPeriodPolicy::class)->violation($this->memberId(), $this->from(), $this->to(), $this->interrupt?->id);
            if ($error !== null) {
                $v->errors()->add('from', $error);
            }
        }];
    }

    public function memberId(): int
    {
        return $this->interrupt ? (int) $this->interrupt->member_id : (int) $this->route('memberId');
    }

    /** Upravované přerušení (jen u úpravy). */
    public function interrupt(): ?object
    {
        return $this->interrupt;
    }

    public function from(): Carbon
    {
        return Carbon::parse($this->input('from'))->startOfDay();
    }

    public function to(): Carbon
    {
        return Carbon::parse($this->input('to'))->startOfDay();
    }

    public function comment(): string
    {
        return trim((string) $this->input('comment', ''));
    }
}
