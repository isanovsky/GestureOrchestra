// Form "Buat room" dan "Gabung room" di room.php.
// Setelah berhasil, token pemain disimpan di sessionStorage (per tab browser),
// lalu pindah ke halaman lobby room.php?code=...&player=...

import { baseUrl, postJson } from './api.js';

const $ = (id) => document.getElementById(id);

// Simpan identitas pemain untuk room ini. sessionStorage dipakai (bukan
// localStorage atau cookie) supaya setiap tab bisa menjadi pemain berbeda.
export function saveRoomSession(code, data) {
    sessionStorage.setItem('go_room_' + code, JSON.stringify(data));
}

function checked(name) {
    const input = document.querySelector(`input[name="${name}"]:checked`);
    return input ? input : null;
}

// Drum tidak punya mode akor: pilihan Akor dinonaktifkan bila drum dipilih.
function syncStyleWithInstrument(prefix) {
    const instrument = checked(prefix + '-instrument');
    const isDrum = instrument && instrument.dataset.type === 'drum';
    const chords = document.querySelector(`input[name="${prefix}-style"][value="chords"]`);
    chords.disabled = isDrum;
    if (isDrum && chords.checked) {
        document.querySelector(`input[name="${prefix}-style"][value="notes"]`).checked = true;
    }
}

for (const prefix of ['create', 'join']) {
    document.querySelectorAll(`input[name="${prefix}-instrument"]`).forEach((input) => {
        input.addEventListener('change', () => syncStyleWithInstrument(prefix));
    });
    syncStyleWithInstrument(prefix);
}

// Nada dasar mengikuti lagu yang dipilih (default_transpose lagu).
const songSelect = $('create-song');
function syncTransposeWithSong() {
    const option = songSelect.selectedOptions[0];
    $('create-transpose').value = option.dataset.transpose;
}
songSelect.addEventListener('change', syncTransposeWithSong);
syncTransposeWithSong();

// Beri tahu bila part yang dipilih punya nada tanpa gestur kamera (hanya keyboard).
function syncKeyboardNote() {
    const instrument = checked('create-instrument');
    const style = checked('create-style');
    const keyboardParts = (songSelect.selectedOptions[0].dataset.keyboardParts || '').split(',');
    $('create-keys-note').hidden = !instrument || !style || !keyboardParts.includes(instrument.value + ':' + style.value);
}
songSelect.addEventListener('change', syncKeyboardNote);
document.querySelectorAll('input[name="create-instrument"], input[name="create-style"]').forEach((input) => {
    input.addEventListener('change', syncKeyboardNote);
});
syncKeyboardNote();

// Kode room selalu huruf besar.
$('join-code').addEventListener('input', (event) => {
    event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});

function goToLobby(code, playerId) {
    location.href = baseUrl() + '/room.php?code=' + encodeURIComponent(code) + '&player=' + playerId;
}

$('create-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.submitter;
    $('create-error').textContent = '';
    button.disabled = true;
    try {
        const result = await postJson('api/room_create.php', {
            nickname: $('create-nickname').value,
            song_id: Number(songSelect.value),
            instrument_id: Number(checked('create-instrument').value),
            part_style: checked('create-style').value,
            transpose: Number($('create-transpose').value),
            tempo_percent: Number($('create-tempo').value),
        });
        saveRoomSession(result.code, { playerId: result.player_id, token: result.player_token, hostToken: result.host_token });
        goToLobby(result.code, result.player_id);
    } catch (error) {
        $('create-error').textContent = error.message;
        button.disabled = false;
    }
});

$('join-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = event.submitter;
    $('join-error').textContent = '';
    button.disabled = true;
    try {
        const result = await postJson('api/room_join.php', {
            code: $('join-code').value,
            nickname: $('join-nickname').value,
            instrument_id: Number(checked('join-instrument').value),
            part_style: checked('join-style').value,
        });
        saveRoomSession(result.code, { playerId: result.player_id, token: result.player_token, hostToken: null });
        goToLobby(result.code, result.player_id);
    } catch (error) {
        $('join-error').textContent = error.message;
        button.disabled = false;
    }
});
