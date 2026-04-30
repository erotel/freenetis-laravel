<?php

namespace App\Http\Controllers;

use App\Models\SmtpException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SmtpExceptionController extends Controller
{
    private function can(string $action = 'view_all'): bool
    {
        return $this->aclCheck($action, 'Network_Controller', 'smtp_exceptions');
    }

    public function index()
    {
        abort_unless($this->can(), 403);

        $rows = SmtpException::orderByRaw('INET_ATON(intip) ASC')->get();

        return view('smtp_exceptions.index', [
            'rows'    => $rows,
            'canEdit' => $this->can('edit_all'),
        ]);
    }

    public function create()
    {
        abort_unless($this->can('edit_all'), 403);

        return view('smtp_exceptions.form', [
            'action' => 'create',
            'record' => null,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->can('edit_all'), 403);

        $data = $this->validateException($request);

        if (SmtpException::where('intip', $data['intip'])->exists()) {
            return back()->withInput()->withErrors([
                'intip' => 'Tato IP adresa už má SMTP výjimku.',
            ]);
        }

        SmtpException::create([
            'intip' => $data['intip'],
            'user'  => (string) (Auth::user()?->login ?? '—'),
            'datum' => now()->toDateString(),
        ]);

        session()->flash('success', 'SMTP výjimka byla uložena.');
        return redirect()->route('smtp-exceptions.index');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record = SmtpException::findOrFail($id);

        return view('smtp_exceptions.form', [
            'action' => 'edit',
            'record' => $record,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record = SmtpException::findOrFail($id);
        $data   = $this->validateException($request);

        $collision = SmtpException::where('intip', $data['intip'])
            ->where('id', '!=', $id)
            ->exists();
        if ($collision) {
            return back()->withInput()->withErrors([
                'intip' => 'Tato IP adresa už má SMTP výjimku.',
            ]);
        }

        // user/datum jsou auditní stopa původního zadavatele — neměníme je při úpravě.
        $record->update([
            'intip' => $data['intip'],
        ]);

        session()->flash('success', 'SMTP výjimka byla uložena.');
        return redirect()->route('smtp-exceptions.index');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        SmtpException::findOrFail($id)->delete();

        session()->flash('success', 'SMTP výjimka byla smazána.');
        return redirect()->route('smtp-exceptions.index');
    }

    private function validateException(Request $request): array
    {
        $intip = trim((string) $request->input('intip', ''));

        if (!filter_var($intip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            back()->withInput()->withErrors(['intip' => 'Neplatná IPv4 adresa.'])->throwResponse();
        }

        return ['intip' => $intip];
    }
}
