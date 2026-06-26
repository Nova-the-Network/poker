<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$userId = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT pseudo, chips FROM utilisateur WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jeux — Nova</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
:root {
    --red:#FF0B00; --black:#0A0A0A; --white:#FFFFFF;
    --cream:#F5F0EA; --muted:#999;
    --border:2.5px solid #0A0A0A; --sh:3px 3px 0 #0A0A0A; --sh-lg:6px 6px 0 #0A0A0A;
}
body {
    font-family:'Plus Jakarta Sans',sans-serif;
    background:var(--cream);
    min-height:100vh;
    margin-left:72px;
    padding:32px;
}
.page-header {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:32px;
}
.page-header h1 { font-size:2.4rem; font-weight:900; letter-spacing:-2px; }
.chips-badge {
    display:flex; align-items:center; gap:8px;
    background:var(--black); color:var(--white);
    padding:10px 18px; border-radius:12px; font-weight:800; font-size:0.95rem;
    border:var(--border); box-shadow:var(--sh);
}
.chips-badge span { color:var(--red); }

.games-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));
    gap:20px;
}
.game-card {
    background:var(--white);
    border:var(--border);
    border-radius:16px;
    padding:24px;
    box-shadow:var(--sh);
    text-decoration:none;
    color:var(--black);
    transition:transform 0.15s, box-shadow 0.15s;
    display:flex;
    flex-direction:column;
    gap:14px;
}
.game-card:hover {
    transform:translate(-3px,-3px);
    box-shadow:var(--sh-lg);
}
.game-card:active {
    transform:translate(1px,1px);
    box-shadow:none;
}
.game-icon {
    width:48px; height:48px;
    background:var(--cream);
    border:var(--border);
    border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    font-size:1.6rem;
}
.game-name {
    font-size:1.3rem;
    font-weight:900;
    letter-spacing:-0.5px;
}
.game-desc {
    font-size:0.85rem;
    color:var(--muted);
    font-weight:600;
    line-height:1.5;
}
.game-meta {
    display:flex;
    gap:12px;
    flex-wrap:wrap;
}
.game-tag {
    font-size:0.7rem;
    font-weight:800;
    text-transform:uppercase;
    font-family:'DM Mono',monospace;
    padding:4px 10px;
    border-radius:6px;
    background:var(--cream);
    border:1.5px solid var(--black);
}

.soon {
    opacity:0.4;
    pointer-events:none;
    filter:grayscale(1);
}
.soon-badge {
    font-size:0.7rem;
    font-weight:800;
    text-transform:uppercase;
    font-family:'DM Mono',monospace;
    color:var(--muted);
    background:var(--cream);
    padding:2px 8px;
    border-radius:4px;
    align-self:flex-start;
}

@media(max-width:860px) {
    body { margin-left:0; padding:16px; }
}
</style>
</head>
<body>

<?php include '../header.php'; ?>

<div class="page-header">
    <h1>🎮 Jeux</h1>
    <div class="chips-badge">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        <span><?= number_format($user['chips']) ?></span> chips
    </div>
</div>

<div class="games-grid">

    <a href="poker.php" class="game-card">
        <div class="game-icon">🃏</div>
        <div class="game-name">Poker</div>
        <div class="game-desc">Affronte des bots ou tes amis au 5-card draw. Paris, bluff, et retournement de situation.</div>
        <div class="game-meta">
            <span class="game-tag">Multijoueur</span>
            <span class="game-tag">Bots</span>
            <span class="game-tag">Cartes</span>
        </div>
    </a>

    <div class="game-card soon">
        <div class="game-icon">🎲</div>
        <div class="game-name">Dés</div>
        <div class="game-desc">Lance les dés et tente ta chance contre les autres joueurs. Bientôt disponible.</div>
        <div class="game-meta">
            <span class="soon-badge">Prochainement</span>
        </div>
    </div>

    <div class="game-card soon">
        <div class="game-icon">⚡</div>
        <div class="game-name">Quiz</div>
        <div class="game-desc">Défie tes amis sur des quiz de culture générale. Bientôt disponible.</div>
        <div class="game-meta">
            <span class="soon-badge">Prochainement</span>
        </div>
    </div>

</div>

</body>
</html>
