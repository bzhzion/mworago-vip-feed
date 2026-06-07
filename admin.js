document.addEventListener('DOMContentLoaded', function () {
    // Générer un token cryptographiquement sûr
    var generateBtn = document.getElementById('generate-token');
    if (generateBtn) {
        generateBtn.addEventListener('click', function () {
            var token = Array.from(crypto.getRandomValues(new Uint8Array(24)))
                .map(function (b) { return b.toString(16).padStart(2, '0'); })
                .join('');
            var input = document.getElementById('vip_feed_token_hash');
            input.value = token;
            input.type = 'text';
            document.getElementById('toggle-token').textContent = 'Masquer';
        });
    }

    // Afficher / masquer le token saisi
    var toggleBtn = document.getElementById('toggle-token');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            var input = document.getElementById('vip_feed_token_hash');
            if (input.type === 'password') {
                input.type = 'text';
                toggleBtn.textContent = 'Masquer';
            } else {
                input.type = 'password';
                toggleBtn.textContent = 'Afficher';
            }
        });
    }

});
