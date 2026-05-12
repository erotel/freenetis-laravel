<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>FreenetIS — první spuštění</title>
<style>
* { box-sizing: border-box; }
body {
    margin: 0; padding: 24px 12px;
    background: #f0ede8; color: #222;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    line-height: 1.5;
}
.wrap { max-width: 760px; margin: 0 auto; }
.card {
    background: #fff; border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
    padding: 24px 28px; margin-bottom: 16px;
}
h1 { margin: 0 0 8px; font-size: 29px; }
h2 { font-size: 19px; margin: 24px 0 12px; color: #444; text-transform: uppercase; letter-spacing: .03em; }
p.lead { color: #555; margin-top: 0; }
label { display: block; margin: 14px 0 4px; font-size: 16px; font-weight: 600; }
input[type=text], input[type=password], input[type=email], input[type=file] {
    width: 100%; padding: 10px 12px; border: 1px solid #d4cfc6;
    border-radius: 5px; font-size: 17px; font-family: inherit;
    background: #fafaf7;
}
input:focus { border-color: #185FA5; outline: none; background: #fff; }
.mode-choice { display: flex; gap: 12px; margin: 18px 0; flex-wrap: wrap; }
.mode-choice label {
    flex: 1 1 280px;
    border: 2px solid #d4cfc6; border-radius: 6px;
    padding: 14px 16px; cursor: pointer;
    font-size: 17px; font-weight: 500;
    display: flex; align-items: flex-start; gap: 10px;
    margin: 0;
    background: #fafaf7;
}
.mode-choice label:hover { border-color: #888; background: #fff; }
.mode-choice input[type=radio]:checked + .mc-text { font-weight: 700; }
.mode-choice label:has(input:checked) { border-color: #185FA5; background: #eef5fc; }
.mode-choice .mc-text { font-size: 16px; }
.mode-choice .mc-text small { display: block; color: #666; font-weight: 400; margin-top: 4px; line-height: 1.3; }
.help {
    margin-top: 8px; padding: 10px 12px;
    background: #f9f5e7; border: 1px solid #f0e0a3; border-radius: 4px;
    font-size: 14px; color: #6c5b1e;
}
.help code {
    display: block; padding: 6px 8px; margin: 4px 0; background: #fffbe6;
    font-family: ui-monospace, monospace; font-size: 13px; white-space: pre-wrap;
    word-break: break-all; border-radius: 3px;
}
button {
    background: #185FA5; color: #fff; border: 0;
    padding: 12px 24px; font-size: 17px; font-weight: 600; border-radius: 5px;
    cursor: pointer; font-family: inherit;
}
button:hover { background: #0c4d8e; }
button:disabled { background: #888; cursor: wait; }
.error { background: #fde0e0; border: 1px solid #f0a8a8; padding: 10px 12px; border-radius: 4px; color: #8a1f1f; font-size: 16px; margin-bottom: 14px; }
.row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 540px) { .row2 { grid-template-columns: 1fr; } }
.field-error { color: #b91c1c; font-size: 14px; margin-top: 4px; }
.spinner-overlay {
    position: fixed; inset: 0; background: rgba(255,255,255,.92);
    display: none; align-items: center; justify-content: center;
    z-index: 100; flex-direction: column; gap: 16px;
}
.spinner-overlay.visible { display: flex; }
.spinner {
    width: 48px; height: 48px;
    border: 5px solid #d4cfc6; border-top-color: #185FA5;
    border-radius: 50%;
    animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<div class="wrap">

<div class="card">
    <h1>FreenetIS — první spuštění</h1>
    <p class="lead">Tento průvodce dokončí instalaci. Spustí se jen jednou — po dokončení se sám vypne.</p>
</div>

@if($errors->any())
<div class="card">
    <div class="error">
        @foreach($errors->all() as $e)
            <div>{{ $e }}</div>
        @endforeach
    </div>
</div>
@endif

<form method="POST" action="{{ url('/setup') }}" enctype="multipart/form-data" id="setupForm">
    @csrf
    <input type="hidden" name="t" value="{{ $token }}">

    <div class="card">
        <h2>1) Způsob instalace</h2>

        <div class="mode-choice">
            <label>
                <input type="radio" name="install_mode" value="fresh" checked onchange="updateMode()">
                <span class="mc-text">Čistá instalace
                    <small>Vyplníš údaje o organizaci a admin účtu. Použije se přiložené prázdné schéma + ACL.</small>
                </span>
            </label>
            <label>
                <input type="radio" name="install_mode" value="dump" onchange="updateMode()">
                <span class="mc-text">Migrace ze starého serveru
                    <small>Nahraješ SQL dump z legacy FreenetIS / produkce. Admin účty jsou v dumpu.</small>
                </span>
            </label>
        </div>
    </div>

    <div class="card" id="section-dump" style="display:none">
        <h2>SQL dump</h2>
        <label for="sql_dump">Soubor (.sql nebo .sql.gz, max. 2 GB)</label>
        <input type="file" name="sql_dump" id="sql_dump" accept=".sql,.gz">
        @error('sql_dump') <div class="field-error">{{ $message }}</div> @enderror

        <div class="help">
            <strong>Jak vytvořit dump na starém serveru:</strong>
            <code>mysqldump --opt --single-transaction --skip-lock-tables \
  -u USER -pPASSWORD DB_NAME | gzip > freenetis-dump.sql.gz</code>
            Přenes na svůj počítač (<code>scp root@old-server:/path/freenetis-dump.sql.gz .</code>) a tady ho nahraj. Velikost klidně 1+ GB — upload se streamuje.
        </div>
    </div>

    <div class="card" id="section-fresh">
        <h2>Organizace</h2>

        <label for="org_name">Název organizace</label>
        <input type="text" name="org_name" id="org_name" value="{{ old('org_name') }}" required maxlength="255" placeholder="např. PVfree.net z.s.">
        @error('org_name') <div class="field-error">{{ $message }}</div> @enderror

        <h2>Administrátor</h2>

        <div class="row2">
            <div>
                <label for="admin_name">Křestní jméno</label>
                <input type="text" name="admin_name" id="admin_name" value="{{ old('admin_name') }}" required maxlength="50">
                @error('admin_name') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="admin_surname">Příjmení</label>
                <input type="text" name="admin_surname" id="admin_surname" value="{{ old('admin_surname') }}" required maxlength="50">
                @error('admin_surname') <div class="field-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <label for="admin_email">E-mail</label>
        <input type="email" name="admin_email" id="admin_email" value="{{ old('admin_email') }}" required maxlength="255">
        @error('admin_email') <div class="field-error">{{ $message }}</div> @enderror

        <label for="admin_login">Login</label>
        <input type="text" name="admin_login" id="admin_login" value="{{ old('admin_login') }}" required maxlength="50" placeholder="např. admin">
        @error('admin_login') <div class="field-error">{{ $message }}</div> @enderror

        <div class="row2">
            <div>
                <label for="admin_password">Heslo (min. 8 znaků)</label>
                <input type="password" name="admin_password" id="admin_password" required minlength="8">
                @error('admin_password') <div class="field-error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="admin_password_confirm">Heslo (znovu)</label>
                <input type="password" name="admin_password_confirm" id="admin_password_confirm" required minlength="8">
                @error('admin_password_confirm') <div class="field-error">{{ $message }}</div> @enderror
            </div>
        </div>
    </div>

    <div class="card">
        <button type="submit" id="submitBtn">Dokončit instalaci →</button>
        <span style="margin-left: 14px; color: #888; font-size: 14px;">Po dokončení se průvodce sám vypne a přesměruje na login.</span>
    </div>
</form>

</div>

<div class="spinner-overlay" id="spinner">
    <div class="spinner"></div>
    <div style="font-size: 19px; color: #444; max-width: 400px; text-align: center;">
        <strong>Probíhá instalace…</strong><br>
        <span style="font-size: 16px; color: #777;">Importuju schéma a vytvářím účet. Velký dump může trvat několik minut. <strong>Nezavírej okno.</strong></span>
    </div>
</div>

<script>
// Cachujeme uzly jen jednou — dotaz `input[required]` by po prvním přepnutí
// vracel prázdný NodeList a `required` flag by se už nikdy nevrátil zpět.
// Místo manipulace s `required` používáme `disabled`: disabled pole jsou
// browserem vyřazena z form-validation (žádné "is not focusable" warningy
// pro skryté inputy) a zároveň nejdou v POSTu, takže serverová větev
// install_mode=dump nedostane nepoužitá pole.
const freshSection = document.getElementById('section-fresh');
const dumpSection  = document.getElementById('section-dump');
const freshFields  = freshSection.querySelectorAll('input');
const dumpField    = document.getElementById('sql_dump');

function updateMode() {
    const isDump = document.querySelector('input[name=install_mode]:checked').value === 'dump';
    dumpSection.style.display  = isDump ? ''     : 'none';
    freshSection.style.display = isDump ? 'none' : '';
    freshFields.forEach(f => f.disabled = isDump);
    dumpField.disabled = !isDump;
}
updateMode();
document.querySelectorAll('input[name=install_mode]').forEach(r => r.addEventListener('change', updateMode));

document.getElementById('setupForm').addEventListener('submit', () => {
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('spinner').classList.add('visible');
});
</script>

</body>
</html>
