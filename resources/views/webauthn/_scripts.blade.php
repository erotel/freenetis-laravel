{{-- Sdílený WebAuthn helper. Vyžaduje <meta name="csrf-token">. --}}
<script>
window.FNWebAuthn = (function () {
    function b64uToBuf(b64u) {
        b64u = String(b64u).replace(/-/g, '+').replace(/_/g, '/');
        var pad = b64u.length % 4; if (pad) b64u += '===='.slice(pad);
        var bin = atob(b64u), bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return bytes.buffer;
    }
    function bufToB64(buf) {
        var bytes = new Uint8Array(buf), bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin); // standardní base64 (shodné s úložištěm na serveru)
    }
    function csrf() {
        var m = document.querySelector('meta[name=csrf-token]');
        return m ? m.getAttribute('content') : '';
    }
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body || {})
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; })
                           .catch(function () { return { ok: r.ok, status: r.status, data: {} }; });
        });
    }

    // Biometrie je dostupná jen v secure contextu (HTTPS/localhost).
    function supported() {
        return !!(window.isSecureContext && window.PublicKeyCredential &&
                  navigator.credentials && navigator.credentials.create);
    }

    async function register(deviceName) {
        var res = await post('{{ route("webauthn.register.options") }}', {});
        if (!res.ok) throw new Error((res.data && res.data.error) || 'Chyba serveru');
        var pk = res.data.publicKey;
        pk.challenge = b64uToBuf(pk.challenge);
        pk.user.id = b64uToBuf(pk.user.id);
        (pk.excludeCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });

        var cred = await navigator.credentials.create({ publicKey: pk });
        var reg = await post('{{ route("webauthn.register") }}', {
            device_name: deviceName,
            clientDataJSON: bufToB64(cred.response.clientDataJSON),
            attestationObject: bufToB64(cred.response.attestationObject)
        });
        if (!reg.ok) throw new Error((reg.data && reg.data.error) || 'Registrace selhala');
        return reg.data;
    }

    // Vrací {ok, redirect} při úspěchu, {fallback:true} když nemá passkey.
    async function login(loginName, context) {
        var chk = await post('{{ route("webauthn.check") }}', { login: loginName });
        if (!chk.ok || !chk.data.hasCredentials) return { fallback: true };

        var res = await post('{{ route("webauthn.login.options") }}', { login: loginName });
        if (!res.ok) return { fallback: true, message: res.data && res.data.error };

        var pk = res.data.publicKey;
        pk.challenge = b64uToBuf(pk.challenge);
        (pk.allowCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });

        var assertion = await navigator.credentials.get({ publicKey: pk });
        var out = await post('{{ route("webauthn.login") }}', {
            id: bufToB64(assertion.rawId),
            clientDataJSON: bufToB64(assertion.response.clientDataJSON),
            authenticatorData: bufToB64(assertion.response.authenticatorData),
            signature: bufToB64(assertion.response.signature),
            context: context || 'web'
        });
        if (!out.ok) throw new Error((out.data && out.data.error) || 'Přihlášení selhalo');
        return out.data;
    }

    return { supported: supported, register: register, login: login };
})();
</script>
