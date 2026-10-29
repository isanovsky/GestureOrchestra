<?php
// Daftar lagu yang sudah tayang (is_published = 1).

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/content.php';
require __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/music.php';

$songs = fetch_published_songs();

// Peta id instrumen -> data instrumen, untuk ikon part di setiap lagu.
$instrumentsById = [];
foreach (fetch_active_instruments() as $instrument) {
    $instrumentsById[(int) $instrument['id']] = $instrument;
}

$pageTitle = 'Lagu';
$activeNav = 'songs';
require __DIR__ . '/includes/layout_header.php';
?>
<h1 class="wavy">Lagu</h1>
<p class="lead">Pilih lagu, lalu pilih instrumen yang punya part di lagu itu.</p>

<?php if ($songs === []): ?>
    <div class="empty-state paper">
        <img src="<?= e(url('assets/img/mascot-oops.svg')) ?>" alt="" width="90" height="105">
        <p>Belum ada lagu yang tayang. Admin sedang menulis partiturnya. Sementara itu, coba <a href="<?= e(url('play.php')) ?>">main bebas</a>.</p>
    </div>
<?php else: ?>
    <ul class="ticket-list">
        <?php foreach ($songs as $song): ?>
            <li class="ticket">
                <div class="ticket-main">
                    <h2><?= e($song['title']) ?></h2>
                    <p class="muted">
                        <?= $song['composer'] !== null ? e($song['composer']) . ' &middot; ' : '' ?>
                        <?= e($song['bpm']) ?> bpm &middot;
                        Do = <?= e(key_name((int) $song['default_transpose'])) ?> &middot;
                        <?= e(DIFFICULTIES[$song['difficulty']] ?? $song['difficulty']) ?> &middot;
                        <?= e(format_duration($song['length_seconds'])) ?>
                    </p>
                    <?php if ($song['description'] !== null): ?>
                        <p><?= e($song['description']) ?></p>
                    <?php endif; ?>
                    <p class="part-icons">
                        <span class="muted">Part:</span>
                        <?php foreach ($song['parts'] as $part): ?>
                            <?php if (isset($instrumentsById[$part['instrument_id']])): ?>
                                <?php $inst = $instrumentsById[$part['instrument_id']]; ?>
                                <span class="part-chip" title="<?= e($inst['name']) ?>">
                                    <?= instrument_icon($inst['type'], $inst['color'], 'inst-icon inst-icon-small') ?>
                                    <?= e($inst['name']) ?> <?= $part['style'] === 'chords' ? '(akor)' : '' ?>
                                    <?php if ($part['keyboard_only']): ?><span class="keys-tag" title="Ada nada tanpa gestur kamera">keyboard</span><?php endif; ?>
                                </span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </p>
                </div>
                <div class="ticket-stub">
                    <a class="btn btn-primary" href="<?= e(url('play.php?song=' . (int) $song['id'])) ?>">Main</a>
                    <a class="btn btn-small btn-ghost" href="<?= e(url('leaderboard.php?song=' . (int) $song['id'])) ?>">Papan skor</a>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
