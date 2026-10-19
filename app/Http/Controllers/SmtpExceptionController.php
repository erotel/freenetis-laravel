<?php

namespace App\Http\Controllers;

use App\Domains\Network\Repositories\SmtpExceptionRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SmtpExceptionController extends Controller
{
    public function __construct(private SmtpExceptionRepository $exceptions)
    {
    }

    private function can(string $action = 'view_all'): bool
    {
        return $this->aclCheck($action, 'Network_Controller', 'smtp_exceptions');
    }

    public function index()
    {
        abort_unless($this->can(), 403);

        return view('smtp_exceptions.index', [
            'rows'    => $this->exceptions->all(),
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

        if ($this->exceptions->ipTaken($data['intip'])) {
            return back()->withInput()->withErrors([
                'intip' => 'Tato IP adresa už má SMTP výjimku.',
            ]);
        }

        $this->exceptions->create($data['intip'], (string) (Auth::user()?->login ?? '—'));

        session()->flash('success', 'SMTP výjimka byla uložena.');
        return redirect()->route('smtp-exceptions.index');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record = $this->exceptions->findOrFail($id);

        return view('smtp_exceptions.form', [
            'action' => 'edit',
            'record' => $record,
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $record = $this->exceptions->findOrFail($id);
        $data   = $this->validateException($request);

        if ($this->exceptions->ipTaken($data['intip'], $id)) {
            return back()->withInput()->withErrors([
                'intip' => 'Tato IP adresa už má SMTP výjimku.',
            ]);
        }

        $this->exceptions->changeIp($record, $data['intip']);

        session()->flash('success', 'SMTP výjimka byla uložena.');
        return redirect()->route('smtp-exceptions.index');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $this->exceptions->delete($this->exceptions->findOrFail($id));

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
