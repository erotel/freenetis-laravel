<?php

namespace App\Http\Requests\Meetings;

use App\Domains\Acl\AclService;
use App\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AclService::class)->hasAccess((int) $this->user()?->id, 'new_all', 'Meetings_Controller', 'meeting');
    }

    public function rules(): array
    {
        return [
            'title'          => 'required|string|max:255',
            'held_on'        => 'required|date',
            'type'           => 'required|in:' . implode(',', array_keys(Meeting::typeLabels())),
            'quorum_percent' => 'required|integer|min:1|max:100',
            'comment'        => 'nullable|string|max:500',
        ];
    }
}
