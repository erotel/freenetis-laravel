@extends('layouts.app')

@section('title', 'Biometrické přihlášení')

@section('content')
<div style="max-width:640px">
    <h2 style="margin:0 0 4px">Biometrické přihlášení</h2>
    <p style="color:var(--fn-text-muted);margin:0 0 18px;font-size:15px">
        Zaregistruj na tomto zařízení Touch ID / Face ID / otisk prstu a příště se přihlas bez hesla
        — funguje v klasickém i Field rozhraní.
    </p>

    <div id="wa-insecure" style="display:none" class="m-alert m-alert-danger">
        Biometrické přihlášení vyžaduje zabezpečené připojení (HTTPS). Na tomto serveru přes HTTP není dostupné.
    </div>

    <div id="wa-msg" style="display:none;margin-bottom:14px" class="m-alert"></div>

    <div class="m-card" style="padding:0">
        @forelse($credentials as $cred)
            <div class="wa-row" data-id="{{ $cred->id }}"
                 style="display:flex;align-items:center;gap:14px;padding:14px 16px;border-bottom:1px solid var(--fn-border)">
                <span style="font-size:22px">🔑</span>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600">{{ $cred->device_name }}</div>
                    <div style="font-size:13px;color:var(--fn-text-muted)">
                        Registrováno {{ optional($cred->created_at)->format('d.m.Y') ?? '—' }}
                        @if($cred->last_used_at) · naposledy {{ $cred->last_used_at->format('d.m.Y') }} @endif
                    </div>
                </div>
                <button type="button" class="m-btn wa-remove" data-id="{{ $cred->id }}"
                        style="color:#c0392b;border-color:#e0b4b4">Odebrat</button>
            </div>
        @empty
            <div id="wa-empty" style="padding:20px 16px;color:var(--fn-text-muted)">
                Zatím žádné zařízení. Přidej první níže.
            </div>
        @endforelse
    </div>

    <button type="button" id="wa-add" class="m-btn" style="margin-top:16px;display:none">＋ Přidat zařízení</button>
</div>

@include('webauthn._scripts')
<script>
(function () {
    var addBtn = document.getElementById('wa-add');
    var msg = document.getElementById('wa-msg');

    function showMsg(text, ok) {
        msg.textContent = text;
        msg.className = 'm-alert ' + (ok ? 'm-alert-success' : 'm-alert-danger');
        msg.style.display = 'block';
    }

    if (!FNWebAuthn.supported()) {
        document.getElementById('wa-insecure').style.display = 'block';
    } else {
        addBtn.style.display = 'inline-block';
        addBtn.addEventListener('click', async function () {
            var name = prompt('Název zařízení (např. "iPhone Pavla", "Pixel 7"):', '');
            if (name === null) return;
            addBtn.disabled = true;
            try {
                await FNWebAuthn.register(name);
                location.reload();
            } catch (e) {
                showMsg(e.message || 'Registrace selhala.', false);
                addBtn.disabled = false;
            }
        });
    }

    document.querySelectorAll('.wa-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Opravdu odebrat toto zařízení?')) return;
            var id = btn.getAttribute('data-id');
            var meta = document.querySelector('meta[name=csrf-token]');
            fetch('{{ url('webauthn/credential') }}/' + id, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': meta ? meta.content : '', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d.ok) { location.reload(); } else { showMsg('Odebrání selhalo.', false); }
            }).catch(function () { showMsg('Odebrání selhalo.', false); });
        });
    });
})();
</script>
@endsection
