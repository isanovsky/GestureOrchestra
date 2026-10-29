<?php
// Papan skor: 10 nilai tertinggi per lagu dan part (instrumen + gaya).
// Default hanya permainan tempo 100%, karena nilai di tempo lambat tidak setara.

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/content.php';
require __DIR__ . '/includes/icons.php';
require __DIR__ . '/includes/scoring.php';

const LEADERBOARD_SIZE = 10;

$songs = fetch_published_songs();

// Lagu terpilih dari ?song=, default lagu pertama.
$songId = (int) ($_GET['song'] ?? 0);
$song = null;
foreach ($songs as $candidate) {
    if ((int) $candidate['id'] === $songId) {
        $song = $candidate;
    }
}
if ($song === null && $songs !== []) {
    $song = $songs[0];
}

// Tempo: ?tempo=100 (default), 50..90, atau 'all' untuk semua tempo.
$tempoParam = is_string($_GET['tempo'] ?? null) ? $_GET['tempo'] : '100';
$tempo = $tempoParam === 'all' ? null : (int) $tempoParam;
if ($tempo !== null && !in_array($tempo, TEMPO_OPTIONS, true)) {
    $tempo = 100;
}

// Satu tabel per part yang bisa dimainkan di lagu ini.
$boards = [];
if ($song !== null) {
    $sql = 'SELECT nickname, accuracy, hits, total_notes, mode, tempo_percent, played_at
            FROM performances
            WHERE song_id = ? AND instrument_id = ? AND part_style = ?'
        . ($tempo === null ? '' : ' AND tempo_percent = ?')
        . ' ORDER BY accuracy DESC, tempo_percent DESC, hits DESC, played_at ASC
            LIMIT ' . LEADERBOARD_SIZE;
    $stmt = db()->prepare($sql);

    $instrumentsById = array_column(fetch_active_instruments(), null, 'id');
    foreach ($song['parts'] as $part) {
        $instrument = $instrumentsById[$part['instrument_id']] ?? null;
        if ($instrument === null) {
            continue;
        }
        $params = [(int) $song['id'], $part['instrument_id'], $part['style']];
        if ($tempo !== null) {
            $params[] = $tempo;
        }
        $stmt->execute($params);
        $boards[] = ['instrument' => $instrument, 'style' => $part['style'], 'rows' => $stmt->fetchAll()];
    }
}

$pageTitle = 'Papan skor';
$activeNav = 'leaderboard';
require __DIR__ . '/includes/layout_header.php';
?>
<h1 class="wavy">Papan skor</h1>

<?php if ($song === null): ?>
    <div class="empty-state paper">
        <img src="<?= e(url('assets/img/mascot-oops.svg')) ?>" alt="" width="90" height="105">
        <p>Belum ada lagu yang tayang, jadi papan skornya masih kosong melompong.</p>
    </div>
<?php else: ?>
    <form method="get" action="<?= e(url('leaderboard.php')) ?>" class="form form-inline">
        <label for="song">Lagu</label>
        <select id="song" name="song">
            <?php foreach ($songs as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= (int) $option['id'] === (int) $song['id'] ? ' selected' : '' ?>><?= e($option['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="tempo">Tempo</label>
        <select id="tempo" name="tempo">
            <?php foreach (array_reverse(TEMPO_OPTIONS) as $option): ?>
                <option value="<?= $option ?>"<?= $option === $tempo ? ' selected' : '' ?>><?= $option ?>%<?= $option === 100 ? ' (asli)' : '' ?></option>
            <?php endforeach; ?>
            <option value="all"<?= $tempo === null ? ' selected' : '' ?>>Semua tempo</option>
        </select>
        <button type="submit" class="btn">Lihat</button>
    </form>
    <p class="muted small">
        <?= $tempo === null
            ? 'Menampilkan semua tempo. Tempo lambat lebih mudah, jadi bandingkan dengan bijak.'
            : 'Hanya permainan di tempo ' . $tempo . '%.' ?>
    </p>

    <div class="board-grid">
        <?php foreach ($boards as $board): ?>
            <?php $instrument = $board['instrument']; ?>
            <section class="board" style="--inst-color: <?= e($instrument['color']) ?>">
                <h2>
                    <?= instrument_icon($instrument['type'], $instrument['color'], 'inst-icon inst-icon-small') ?>
                    <?= e($instrument['name']) ?>
                    <?php if ($instrument['type'] !== 'drum'): /* drum hanya punya satu gaya, label tidak perlu */ ?>
                        <span class="muted">(<?= e(strtolower(PART_STYLES[$board['style']])) ?>)</span>
                    <?php endif; ?>
                </h2>
                <?php if ($board['rows'] === []): ?>
                    <p class="empty">Belum ada yang main bagian ini<?= $tempo !== null ? ' di tempo ' . $tempo . '%' : '' ?>. Jadi yang pertama?</p>
                    <a class="btn btn-small btn-primary" href="<?= e(url('play.php?instrument=' . $instrument['slug'] . '&song=' . (int) $song['id'] . '&part=' . $board['style'])) ?>">Main sekarang</a>
                <?php else: ?>
                    <ol class="board-list">
                        <?php foreach ($board['rows'] as $rank => $row): ?>
                            <li>
                                <span class="board-rank"><?= $rank + 1 ?></span>
                                <span class="board-name">
                                    <?= e($row['nickname']) ?>
                                    <small class="muted">
                                        <?= e($row['hits']) ?>/<?= e($row['total_notes']) ?>,
                                        tempo <?= e($row['tempo_percent']) ?>%,
                                        <?= $row['mode'] === 'room' ? 'main bersama' : 'solo' ?>,
                                        <?= e(format_datetime($row['played_at'])) ?>
                                    </small>
                                </span>
                                <span class="board-score"><?= e(number_format((float) $row['accuracy'], 1, ',', '')) ?>%</span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
