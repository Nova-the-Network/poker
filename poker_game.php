<?php
// error_reporting(E_ALL); // production: désactivé
ini_set('display_errors', 0); // Désactivé en production pour la sécurité
error_reporting(0);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$userId = $_SESSION['user_id'];

// Générer un token CSRF si inexistant
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$sessionId = (int)($_GET['id'] ?? 0);
$code = trim($_GET['code'] ?? '');

if ($code && !$sessionId) {
    $stmt = $pdo->prepare("SELECT id FROM poker_sessions WHERE code_invite = ? AND statut IN ('waiting','playing')");
    $stmt->execute([$code]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        $sessionId = $res['id'];
    } else {
        echo "Partie introuvable.";
        exit;
    }
}

if (!$sessionId) {
    header("Location: poker.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM poker_sessions WHERE id = ?");
$stmt->execute([$sessionId]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) {
    echo "Partie introuvable.";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM poker_players WHERE session_id = ? AND user_id = ?");
$stmt->execute([$sessionId, $userId]);
$myPlayer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$myPlayer && $session['statut'] === 'waiting' && $session['type'] !== 'vs_bots' && ($code || $session['type'] !== 'vs_friends')) {
    $stmtU = $pdo->prepare("SELECT chips FROM utilisateur WHERE id = ?");
    $stmtU->execute([$userId]);
    $chips = $stmtU->fetchColumn();
    if ($chips >= $session['mise']) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM poker_players WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $pos = (int)$stmt->fetchColumn();
        $chipsAvant = ($session['type'] === 'vs_friends') ? min((int)$session['mise'], $chips) : $chips;
        $pdo->prepare("INSERT INTO poker_players (session_id, user_id, position, chips_avant, mise_joueur) VALUES (?, ?, ?, ?, 0)")
            ->execute([$sessionId, $userId, $pos, $chipsAvant]);
        if ($session['type'] === 'vs_friends') {
            $pdo->prepare("UPDATE utilisateur SET chips = chips - ? WHERE id = ?")->execute([$chipsAvant, $userId]);
        }
        $pdo->commit();
        $stmt = $pdo->prepare("SELECT * FROM poker_players WHERE session_id = ? AND user_id = ?");
        $stmt->execute([$sessionId, $userId]);
        $myPlayer = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$stmt = $pdo->prepare("SELECT pseudo, chips FROM utilisateur WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Poker Hold'em — Table #<?= $sessionId ?> — Nova</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
:root {
    --red:#FF0B00; --black:#0A0A0A; --white:#FFFFFF;
    --green:#22C55E; --muted:#999; --cream:#F5F0EA;
}
body {
    font-family:'Plus Jakarta Sans',sans-serif;
    background:#1a1a2e;
    min-height:100vh; margin-left:72px;
    display:flex; flex-direction:column;
    color:var(--white);
}
.table-wrap {
    flex:1; display:flex; flex-direction:column;
    align-items:center; justify-content:center;
    padding:16px; position:relative;
}
.table-felt {
    width:100%; max-width:960px; aspect-ratio:16/9;
    background:radial-gradient(ellipse at center,#0d7a3f,#064526);
    border:4px solid #8B4513; border-radius:200px/120px;
    box-shadow:0 20px 60px rgba(0,0,0,0.5), inset 0 0 80px rgba(0,0,0,0.3);
    position:relative; overflow:hidden;
}
.table-center {
    position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
    text-align:center; z-index:5; pointer-events:none;
}
.pot-display {
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(0,0,0,0.5); padding:6px 18px; border-radius:999px;
    font-weight:800; font-size:1rem; backdrop-filter:blur(4px);
}
.hand-name {
    font-size:0.78rem; font-weight:700; color:rgba(255,255,255,0.6);
    margin-top:4px; font-family:'DM Mono',monospace;
}

/* ── Community cards ── */
.community-row {
    display:flex; justify-content:center; gap:8px;
    margin-bottom:8px;
}
.community-card {
    width:52px; height:72px;
    background:var(--white); border:2px solid var(--black);
    border-radius:8px; display:flex; align-items:center; justify-content:center;
    font-size:1.15rem; font-weight:800;
    box-shadow:2px 2px 0 rgba(0,0,0,0.2);
    transition:transform 0.3s cubic-bezier(.34,1.56,.64,1), opacity 0.3s;
    transform:scale(1); opacity:1;
}
.community-card.red { color:var(--red); }
.community-card.black { color:var(--black); }
.community-card.entering { transform:scale(0) rotate(-180deg); opacity:0; }
.community-card.entered { transform:scale(1) rotate(0); opacity:1; }
.community-slot {
    width:52px; height:72px;
    border:2px dashed rgba(255,255,255,0.12); border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    color:rgba(255,255,255,0.12); font-size:0.6rem; font-weight:600;
    transition:all 0.4s;
}
.pot-display.pot-flash {
    animation:potFlash 0.6s ease-out;
}
@keyframes potFlash {
    0% { transform:scale(1); background:rgba(255,215,0,0.4); }
    50% { transform:scale(1.2); background:rgba(255,215,0,0.6); }
    100% { transform:scale(1); background:rgba(0,0,0,0.5); }
}

/* ── Players ── */
.players-area {
    position:absolute; inset:0;
}
.player-spot {
    position:absolute;
    display:flex; flex-direction:column; align-items:center; gap:4px;
    padding:8px 10px; border-radius:14px; min-width:88px;
    background:rgba(0,0,0,0.35); border:2px solid transparent;
    transition:all 0.3s; transform:translate(-50%,-50%);
    backdrop-filter:blur(4px);
}
.player-spot.is-me { border-color:var(--red); background:rgba(255,11,0,0.15); }
.player-spot.is-turn { border-color:var(--green); box-shadow:0 0 20px rgba(34,197,94,0.4); }
.player-spot.folded { opacity:0.35; }
.player-spot.is-dealer { border-color:#F59E0B; }
.turn-badge {
    position:absolute; top:-8px; left:-8px;
    background:var(--green); color:var(--black);
    width:22px; height:22px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:0.7rem; font-weight:900; border:2px solid var(--black);
    z-index:2; animation:turnPulse 1s ease-in-out infinite;
}
@keyframes turnPulse {
    0%,100% { box-shadow:0 0 0 0 rgba(34,197,94,0.6); }
    50% { box-shadow:0 0 0 8px rgba(34,197,94,0); }
}
.dealer-badge {
    position:absolute; top:-8px; right:-8px;
    background:#F59E0B; color:var(--black);
    width:22px; height:22px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:0.6rem; font-weight:900; border:2px solid var(--black);
    z-index:2;
}
.player-avatar {
    width:38px; height:38px; border-radius:50%;
    background:var(--black); border:2px solid var(--white);
    display:flex; align-items:center; justify-content:center;
    font-size:1rem; font-weight:800; overflow:hidden;
}
.player-name { font-size:0.72rem; font-weight:700; text-align:center; line-height:1.2; }
.player-chips { font-size:0.65rem; color:rgba(255,255,255,0.6); font-family:'DM Mono',monospace; }
.player-bet {
    display:none;
}
.chip-stack {
    position:absolute; z-index:4; pointer-events:none;
    display:flex; align-items:center; gap:3px;
    background:rgba(255,11,0,0.13); backdrop-filter:blur(2px);
    border:1.5px solid rgba(255,11,0,0.25);
    padding:3px 8px 3px 6px; border-radius:20px;
    font-size:0.7rem; font-weight:800; color:var(--red);
    transform:translate(-50%,-50%);
    transition:all 0.4s cubic-bezier(.34,1.56,.64,1);
    white-space:nowrap;
}
.chip-stack::before {
    content:''; width:10px; height:10px;
    background:radial-gradient(circle at 35% 35%,#e8b830,#c49a1a);
    border:1.5px solid #a07d14;
    border-radius:50%; box-shadow:0 1px 2px rgba(0,0,0,0.3);
    flex-shrink:0;
}
.chip-stack.sweep {
    transition:all 0.5s cubic-bezier(.34,1.56,.64,1);
    opacity:0; transform:translate(-50%,-50%) scale(0.3) !important;
}
.player-blind {
    font-size:0.58rem; font-weight:700; text-transform:uppercase;
    padding:1px 5px; border-radius:4px;
}
.blind-sb { background:rgba(34,197,94,0.2); color:var(--green); }
.blind-bb { background:rgba(255,11,0,0.2); color:var(--red); }

/* Notifications */
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 10px;
    background: rgba(0, 0, 0, 0.8);
    color: var(--white);
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 0.85rem;
    font-weight: 600;
    z-index: 1000;
    transform: translateX(120%);
    transition: transform 0.3s ease-out;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    border-left: 4px solid var(--green);
}
.notification.show {
    transform: translateX(0);
}
.notification.error {
    border-left-color: var(--red);
    background: rgba(255, 0, 0, 0.15);
}
.notification.success {
    border-left-color: var(--green);
    background: rgba(0, 255, 0, 0.15);
}
.notification.info {
    border-left-color: #2196F3;
    background: rgba(0, 0, 0, 0.8);
}

/* Boîte de confirmation */
.confirm-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1001;
}
.confirm-box {
    background: var(--white);
    color: var(--black);
    padding: 20px 30px;
    border-radius: 16px;
    border: var(--border);
    box-shadow: var(--sh-lg);
    text-align: center;
    max-width: 400px;
    width: 90%;
}
.confirm-box h3 {
    font-size: 1.1rem;
    font-weight: 800;
    margin-bottom: 16px;
}
.confirm-buttons {
    display: flex;
    gap: 10px;
    justify-content: center;
}
.cards-row { display:flex; gap:3px; margin-top:1px; }
.card {
    width:34px; height:48px;
    background:var(--white); border:2px solid var(--black);
    border-radius:6px; display:flex; align-items:center; justify-content:center;
    font-size:0.82rem; font-weight:800;
    box-shadow:1px 1px 0 rgba(0,0,0,0.2);
}
.card.red { color:var(--red); }
.card.black { color:var(--black); }
.card-back {
    width:34px; height:48px;
    background:repeating-linear-gradient(45deg,var(--red),var(--red) 3px,#b50800 3px,#b50800 6px);
    border:2px solid var(--black); border-radius:6px;
}

.actions-bar {
    display:flex; gap:6px; justify-content:center; flex-wrap:wrap;
    padding:10px 16px; background:rgba(0,0,0,0.55);
    border-radius:14px; z-index:10; backdrop-filter:blur(4px);
    margin-top:-14px; max-width:600px; width:100%;
}
.btn {
    padding:8px 14px; border:2px solid var(--white); border-radius:10px;
    background:transparent; color:var(--white); font-family:'Plus Jakarta Sans',sans-serif;
    font-weight:700; font-size:0.78rem; cursor:pointer;
    transition:all 0.15s;
}
.btn:hover { background:var(--white); color:var(--black); }
.btn-primary { background:var(--red); border-color:var(--red); }
.btn-primary:hover { background:#cc0900; color:var(--white); }
.btn-green { background:var(--green); border-color:var(--green); }
.btn-green:hover { background:#16a34a; color:var(--white); }
.btn:disabled { opacity:0.3; cursor:not-allowed; }
.btn-sm { padding:5px 10px; font-size:0.7rem; }
.bet-input {
    padding:6px 10px; border:2px solid var(--white); border-radius:10px;
    background:transparent; color:var(--white); font-weight:700;
    width:70px; font-family:'Plus Jakarta Sans',sans-serif; text-align:center;
    outline:none;
}
.bet-input:focus { border-color:var(--red); }

.game-header {
    display:flex; align-items:center; justify-content:space-between;
    padding:10px 20px; background:rgba(0,0,0,0.3);
}
.game-header h2 { font-size:0.95rem; font-weight:700; }
.game-header .back-link { color:rgba(255,255,255,0.5); text-decoration:none; font-size:0.78rem; font-weight:600; }
.game-header .back-link:hover { color:var(--white); }
.quit-btn {
    background:rgba(255,255,255,0.1); color:var(--white); text-decoration:none;
    padding:6px 14px; border-radius:10px; font-weight:700; font-size:0.78rem;
    border:2px solid rgba(255,255,255,0.2); transition:all 0.15s;
}
.quit-btn:hover { background:var(--red); border-color:var(--red); }

.winner-overlay {
    position:absolute; inset:0; display:none;
    align-items:center; justify-content:center;
    background:rgba(0,0,0,0.65); border-radius:200px/120px;
    z-index:15;
}
.winner-overlay.show { display:flex; }
.winner-box {
    background:var(--white); color:var(--black); padding:20px 32px;
    border-radius:18px; text-align:center; border:2.5px solid var(--black);
    box-shadow:6px 6px 0 var(--black);
}
.winner-box h3 { font-size:1.4rem; font-weight:900; }
.winner-box p { margin-top:4px; font-size:0.85rem; color:var(--muted); font-weight:600; }

#gameStatus { font-size:0.78rem; color:rgba(255,255,255,0.6); font-weight:600; }

/* ── Deal overlay ── */
.deal-overlay {
    position:absolute; inset:0; display:none;
    align-items:center; justify-content:center;
    background:rgba(0,0,0,0.75); border-radius:200px/120px; z-index:20;
    flex-direction:column; gap:10px;
}
.deal-overlay.show { display:flex; }
.deal-text {
    font-size:1.2rem; font-weight:900;
    animation:pulse 0.8s ease-in-out infinite alternate;
}
@keyframes pulse { from { opacity:0.5; transform:scale(0.95); } to { opacity:1; transform:scale(1.05); } }
.deal-cards-row {
    display:flex; gap:5px;
}
.deal-card-anim {
    width:46px; height:64px;
    background:repeating-linear-gradient(45deg,#1a3a8a,#1a3a8a 3px,#15307a 3px,#15307a 6px);
    border:2px solid var(--white); border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    font-size:0; animation:dealSlide 0.35s ease-out both;
    box-shadow:2px 2px 0 rgba(0,0,0,0.3);
    position:relative;
}
.deal-card-anim::after {
    content:'🂠'; font-size:1.8rem; opacity:0.4;
}
.deal-card-anim:nth-child(1) { animation-delay:0s; }
.deal-card-anim:nth-child(2) { animation-delay:0.12s; }
.deal-card-anim:nth-child(3) { animation-delay:0.24s; }
.deal-card-anim:nth-child(4) { animation-delay:0.36s; }
.deal-card-anim:nth-child(5) { animation-delay:0.48s; }
@keyframes dealSlide {
    from { transform:translateY(-50px) rotate(-15deg); opacity:0; }
    to { transform:translateY(0) rotate(0); opacity:1; }
}
.countdown-text { font-size:0.8rem; color:rgba(255,255,255,0.6); font-weight:600; }

/* ── Community deal animation ── */
.comm-dealing {
    filter:brightness(1.5);
    animation:commDeal 0.5s cubic-bezier(.34,1.56,.64,1);
}
@keyframes commDeal {
    0% { transform:scale(0) rotate(-180deg); opacity:0; }
    100% { transform:scale(1) rotate(0); opacity:1; }
}

@media(max-width:860px) {
    body { margin-left:0; padding:0 0 64px 0; overflow-x:hidden; }
    .game-header { flex-wrap:wrap; gap:4px; padding:8px 12px; font-size:0.78rem; }
    .game-header h2 { font-size:0.78rem; }
    .game-header .back-link { font-size:0.7rem; }
    .quit-btn { padding:4px 10px; font-size:0.7rem; }
    .table-wrap { padding:8px 8px 0 8px; }
    .table-felt { border-radius:80px/50px; max-width:100vw; }
    .card { width:26px; height:38px; font-size:0.65rem; }
    .card-back { width:26px; height:38px; }
    .community-card { width:38px; height:54px; font-size:0.85rem; }
    .community-slot { width:38px; height:54px; font-size:0.5rem; }
    .player-spot { min-width:60px; padding:4px 5px; gap:2px; }
    .player-avatar { width:26px; height:26px; font-size:0.75rem; }
    .player-name { font-size:0.58rem; }
    .player-chips { font-size:0.55rem; }
    .btn { padding:8px 12px; font-size:0.75rem; }
    .btn-sm { padding:6px 10px; font-size:0.7rem; }
    .bet-input { width:60px; font-size:0.75rem; padding:5px 8px; }
    .actions-bar {
        position:fixed; bottom:0; left:0; right:0; margin-top:0;
        padding:10px 12px; gap:4px; flex-wrap:nowrap; overflow-x:auto;
        border-radius:14px 14px 0 0; max-width:none;
        background:rgba(0,0,0,0.85); border-top:1px solid rgba(255,255,255,0.08);
    }
    .winner-overlay { border-radius:80px/50px; }
    .winner-box { padding:14px 20px; }
    .winner-box h3 { font-size:1.1rem; }
    .dealer-badge, .turn-badge { width:18px; height:18px; font-size:0.55rem; }
    .dealer-badge { top:-6px; right:-6px; }
    .turn-badge { top:-6px; left:-6px; }
    .chip-stack { font-size:0.6rem; padding:2px 6px 2px 4px; }
    .chip-stack::before { width:8px; height:8px; }
    #gameStatus { font-size:0.65rem; }
}

@media(max-width:480px) {
    .table-felt { border-radius:60px/35px; }
    .community-card { width:32px; height:46px; font-size:0.75rem; }
    .community-slot { width:32px; height:46px; font-size:0.45rem; }
    .card { width:22px; height:32px; font-size:0.55rem; }
    .card-back { width:22px; height:32px; }
    .player-spot { min-width:52px; padding:3px 4px; }
    .player-avatar { width:22px; height:22px; font-size:0.65rem; }
    .player-name { font-size:0.5rem; }
    .player-chips { font-size:0.48rem; }
    .btn { padding:6px 10px; font-size:0.7rem; }
    .btn-sm { padding:5px 8px; font-size:0.65rem; }
    .bet-input { width:50px; font-size:0.7rem; }
    .actions-bar { padding:6px 8px; gap:3px; }
    .winner-box { padding:12px 16px; }
    .winner-box h3 { font-size:0.95rem; }
}
</style>
</head>
<body>

<div class="game-header">
    <a href="poker.php" class="back-link">← Lobby</a>
    <h2>Hold'em #<?= $sessionId ?></h2>
    <span id="gameStatus">Chargement…</span>
    <a href="#" class="quit-btn" id="quitBtn" style="display:none;" onclick="confirmQuit(event)">Quitter</a>
</div>
<input type="hidden" id="csrfToken" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

<div class="table-wrap">
    <div class="table-felt" id="tableFelt">
        <div class="table-center">
            <div class="community-row" id="communityRow"></div>
            <div class="pot-display">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                Pot: <span id="potDisplay">0</span>
            </div>
            <div id="handNameDisplay" class="hand-name"></div>
        </div>

        <div class="players-area" id="playersArea"></div>

        <div id="winnerOverlay" class="winner-overlay">
            <div class="winner-box">
                <h3 id="winnerName">🏆 Gagnant</h3>
                <p id="winnerHand"></p>
                <div id="nextHandInfo" style="margin-top:10px;font-size:0.78rem;font-weight:600;color:var(--muted);">Prochaine main dans <span id="countdownNum">3</span>s…</div>
                <a href="poker.php" class="btn btn-sm" style="margin-top:8px;background:var(--cream);border-color:var(--black);color:var(--black);display:inline-flex;">🚪 Quitter la table</a>
            </div>
        </div>

        <div id="dealOverlay" class="deal-overlay">
            <div class="deal-text">🃏 Distribution…</div>
            <div class="deal-cards-row">
                <div class="deal-card-anim"></div>
                <div class="deal-card-anim"></div>
                <div class="deal-card-anim"></div>
                <div class="deal-card-anim"></div>
                <div class="deal-card-anim"></div>
            </div>
        </div>
    </div>

    <div id="actionsBar" class="actions-bar" style="display:none;">
        <button class="btn btn-green btn-sm" onclick="doAction('check')" id="btnCheck">Check ✓</button>
        <button class="btn btn-primary btn-sm" onclick="doAction('bet')" id="btnBet">Miser</button>
        <input type="number" id="betAmount" class="bet-input" value="10" min="1">
        <button class="btn btn-sm" onclick="doAction('fold')" id="btnFold">Fold ✕</button>
        <button class="btn btn-green btn-sm" onclick="doAction('call')" id="btnCall" style="display:none;">Suivre</button>
    </div>
</div>

<script>
var sessionId = <?= $sessionId ?>;
var userId = <?= $userId ?>;
var currentHandId = 0;
var currentTour = null;
var myPlayerId = <?= $myPlayer ? $myPlayer['id'] : 'null' ?>;
var botPlaying = false;
var pollTimer = null;
var autoDealTimer = null;
var isDealingAnimation = false;

// Fonction pour obtenir le token CSRF
function getCsrfToken() {
    var token = document.getElementById('csrfToken');
    return token ? token.value : '';
}

// Fonction pour afficher une notification
function showNotification(message, type) {
    var notification = document.createElement('div');
    notification.className = 'notification ' + (type || 'info');
    notification.textContent = message;
    document.body.appendChild(notification);
    
    // Animation d'apparition
    setTimeout(function() {
        notification.classList.add('show');
    }, 10);
    
    // Suppression après 3 secondes
    setTimeout(function() {
        notification.classList.remove('show');
        setTimeout(function() { notification.remove(); }, 300);
    }, 3000);
}

// Fonction pour envoyer une requête POST avec CSRF
function pokerPost(action, params, callback) {
    var csrfToken = getCsrfToken();
    params.csrf_token = csrfToken;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'poker_api.php?action=' + action, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var d = JSON.parse(xhr.responseText);
            if (!d.success && d.error) {
                showNotification(d.error, 'error');
            }
            callback(d);
        } catch(e) {
            showNotification('Erreur réseau', 'error');
            if (callback) callback({success: false, error: 'Erreur réseau'});
        }
    };
    xhr.onerror = function() {
        showNotification('Erreur réseau', 'error');
        if (callback) callback({success: false, error: 'Erreur réseau'});
    };
    xhr.send(Object.keys(params).map(function(k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&'));
}
var prevCommCount = 0;
var prevTour = null;

function loadState(callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'poker_api.php?action=state&id=' + sessionId, true);
    xhr.onload = function() {
        try {
            var d = JSON.parse(xhr.responseText);
            if (d.success) {
                render(d);
                if (callback) callback(d);
                return d;
            }
        } catch(e) {}
        if (callback) callback(null);
    };
    xhr.send();
}

function render(data) {
    var session = data.session;
    var players = data.players;

    var newTour = data.current_tour;
    var tourChanged = prevTour && newTour && prevTour !== newTour;

    document.getElementById('gameStatus').textContent = session.statut === 'waiting' ? '⏳ En attente…' : (session.statut === 'playing' ? '🃏 ' + (newTour || '') : '✅ Terminé');

    var isHost = session.host_id == userId;
    var hasMyPlayer = players.some(function(p) { return p.user_id == userId; });

    // Community cards — animate only newly arrived cards
    var comm = data.communautaires || [];
    if (prevTour === null) prevCommCount = comm.length; // pas d'animation au premier rendu
    renderCommunityCards(comm);

    // Si le tour a changé → sweep les chips vers le pot
    if (tourChanged) {
        document.getElementById('potDisplay').textContent = data.current_pot || 0;
        document.getElementById('potDisplay').classList.add('pot-flash');
        setTimeout(function() {
            document.getElementById('potDisplay').classList.remove('pot-flash');
        }, 600);
    } else {
        document.getElementById('potDisplay').textContent = data.current_pot || 0;
    }

    // Players around the table
    renderPlayers(players, data, session, tourChanged);

    // Sync hand ID from server state
    if (data.hand && data.hand.id) currentHandId = data.hand.id;

    // Actions bar
    var actionsBar = document.getElementById('actionsBar');
    currentTour = newTour;
    prevTour = newTour;

    var isMyTurn = data.current_action_player_id && data.current_action_player_id == myPlayerId && !data.current_action_player_is_bot;
    if (session.statut === 'playing' && isMyTurn && currentTour && !botPlaying) {
        actionsBar.style.display = 'flex';
        var myP = players.find(function(p) { return p.user_id == userId; });
        if (myP && (myP.folded == 1 || myP.chips_avant <= 0)) {
            actionsBar.style.display = 'none';
        } else {
            var miseCourante = data.hand ? (parseInt(data.hand.mise_courante) || 0) : 0;
            showActionButtons(currentTour, myP, miseCourante);
        }
    } else if (session.statut === 'waiting' && isHost) {
        actionsBar.style.display = 'flex';
        actionsBar.innerHTML =
            '<button class="btn btn-primary" onclick="dealCards()">🃏 Distribuer</button>' +
            '<button class="btn btn-sm" onclick="addBot()">+ Bot</button>';
    } else if (session.statut === 'waiting') {
        actionsBar.style.display = 'flex';
        actionsBar.innerHTML = '<span style="color:rgba(255,255,255,0.5);font-size:0.78rem;">En attente…</span>';
    } else if (!currentTour && session.statut === 'playing') {
        if (isHost) {
            actionsBar.style.display = 'flex';
            actionsBar.innerHTML = '<button class="btn btn-primary" onclick="dealCards()">🃏 Distribuer</button>';
        } else {
            actionsBar.style.display = 'none';
        }
    } else {
        actionsBar.style.display = 'none';
    }
}

function renderCommunityCards(comm) {
    var row = document.getElementById('communityRow');
    var html = '';
    for (var i = 0; i < 5; i++) {
        if (i < comm.length) {
            var c = comm[i];
            var color = (c.suit === '♥' || c.suit === '♦') ? 'red' : 'black';
            var isNew = i >= prevCommCount;
            var anim = isNew ? 'comm-dealing' : '';
            html += '<div class="community-card ' + color + ' ' + anim + '">' + (c.value === '10' ? '10' : c.value[0]) + c.suit + '</div>';
        } else {
            var labels = ['Flop','','','Turn','River'];
            html += '<div class="community-slot">' + (labels[i] || '') + '</div>';
        }
    }
    row.innerHTML = html;
    prevCommCount = comm.length;
}

function renderPlayers(players, data, session, tourChanged) {
    var area = document.getElementById('playersArea');

    if (tourChanged) {
        // Sweep les anciens chips vers le pot, puis on re-render
        var oldChips = area.querySelectorAll('.chip-stack');
        oldChips.forEach(function(c) { c.classList.add('sweep'); });
        setTimeout(function() {
            area.innerHTML = '';
            renderPlayersNow(players, data, session);
        }, 500);
    } else {
        area.innerHTML = '';
        renderPlayersNow(players, data, session);
    }
}

function renderPlayersNow(players, data, session) {
    var area = document.getElementById('playersArea');
    area.innerHTML = '';

    var dealerPos = (data.hand && data.hand.dealer_pos !== undefined) ? data.hand.dealer_pos : -1;
    var activePlayers = players.filter(function(p) { return p.est_actif == 1; });
    var numActive = activePlayers.length;

    var sorted = players.slice().sort(function(a,b) { return a.position - b.position; });
    var n = sorted.length;
    if (n === 0) return;

    var humanIdx = sorted.findIndex(function(p) { return p.user_id == userId; });
    if (humanIdx === -1) humanIdx = 0;

    var table = document.getElementById('tableFelt');
    var tw = table.offsetWidth || 900;
    var th = table.offsetHeight || 500;
    if (tw < 200) tw = 900;
    if (th < 200) th = 500;

    var cx = tw / 2;
    var cy = th / 2;
    var rx = Math.min(cx * 0.78, 320);
    var ry = Math.min(cy * 0.70, 180);

    sorted.forEach(function(p, idx) {
        var seatIdx = (idx - humanIdx + n) % n;
        var totalSeats = Math.max(n, 4);
        var startAngle = 200;
        var endAngle = 340;
        var angle = startAngle + (seatIdx / Math.max(totalSeats - 1, 1)) * (endAngle - startAngle);
        var rad = angle * Math.PI / 180;
        var x = cx + rx * Math.cos(rad);
        var y = cy + ry * Math.sin(rad);

        var div = document.createElement('div');
        div.className = 'player-spot';
        div.style.left = x + 'px';
        div.style.top = y + 'px';
        if (p.user_id == userId) div.classList.add('is-me');
        if (p.folded == 1) div.classList.add('folded');

        var dealerBadge = '';
        if (p.position === dealerPos && p.est_actif == 1) {
            div.classList.add('is-dealer');
            dealerBadge = '<div class="dealer-badge">D</div>';
        }
        var turnBadge = '';
        if (data.current_action_player_id && p.id == data.current_action_player_id && p.est_actif == 1 && p.folded == 0) {
            div.classList.add('is-turn');
            turnBadge = '<div class="turn-badge">▶</div>';
        }

        var avatarLetter = (p.display_name || '?')[0].toUpperCase();
        var cardsHtml = '';
        if (p.cartes && p.folded == 0) {
            try {
                var cards = JSON.parse(p.cartes);
                cards.forEach(function(c) {
                    var color = (c.suit === '♥' || c.suit === '♦') ? 'red' : 'black';
                    cardsHtml += '<div class="card ' + color + '">' + (c.value === '10' ? '10' : c.value[0]) + c.suit + '</div>';
                });
            } catch(e) {}
        } else if (p.folded == 0 && data.current_tour && p.player_type === 'bot') {
            for (var i = 0; i < 2; i++) cardsHtml += '<div class="card-back"></div>';
        }

        var blindHtml = '';
        if (p.est_actif == 1 && data.hand && numActive >= 2) {
            var srt = activePlayers.slice().sort(function(a,b){ return a.position - b.position; });
            var dIdx = srt.findIndex(function(ap) { return ap.position === dealerPos; });
            if (dIdx === -1) dIdx = 0;
            var sbIdx = (dIdx + 1) % numActive;
            var bbIdx = (dIdx + 2) % numActive;
            if (srt[sbIdx] && srt[sbIdx].id === p.id) blindHtml = '<div class="player-blind blind-sb">SB</div>';
            if (srt[bbIdx] && srt[bbIdx].id === p.id) blindHtml = '<div class="player-blind blind-bb">BB</div>';
        }

        var chipsDisplay = p.chips_apres !== null ? p.chips_apres : p.chips_avant;

        div.innerHTML = dealerBadge + turnBadge +
            '<div class="player-avatar">' + avatarLetter + '</div>' +
            '<div class="player-name">' + (p.user_id == userId ? '<strong>' + escHtml(p.display_name) + '</strong>' : escHtml(p.display_name)) + '</div>' +
            (p.folded == 1 ? '<div style="font-size:0.65rem;color:var(--red);font-weight:700;">Fold</div>' : '') +
            '<div class="player-chips">' + chipsDisplay + '</div>' +
            blindHtml +
            '<div class="cards-row">' + cardsHtml + '</div>';

        area.appendChild(div);

        // Chip stack — positionné entre le joueur et le centre
        if (p.mise_joueur > 0 && p.folded == 0) {
            var chip = document.createElement('div');
            chip.className = 'chip-stack';
            var chipX = x + (cx - x) * 0.38;
            var chipY = y + (cy - y) * 0.38;
            chip.style.left = chipX + 'px';
            chip.style.top = chipY + 'px';
            chip.textContent = p.mise_joueur;
            area.appendChild(chip);
        }
    });
}

function showActionButtons(tour, myP, miseCourante) {
    var bar = document.getElementById('actionsBar');
    var betInput = document.getElementById('betAmount');
    var wasFocused = document.activeElement === betInput;
    var savedValue = betInput ? betInput.value : null;

    var defaultBet = Math.max(1, Math.floor((myP ? myP.chips_avant : 100) / 20)) || 10;
    if (miseCourante > 0) {
        bar.innerHTML =
            '<button class="btn btn-green btn-sm" onclick="doAction(\'call\')">Suivre ' + miseCourante + '</button>' +
            '<button class="btn btn-primary btn-sm" onclick="doAction(\'raise\')">Relancer</button>' +
            '<input type="number" id="betAmount" class="bet-input" value="' + (miseCourante + 1) + '" min="' + (miseCourante + 1) + '" title="Mise totale (incluant tes mises précédentes)">' +
            '<button class="btn btn-sm" onclick="doAction(\'fold\')">Fold ✕</button>';
    } else {
        bar.innerHTML =
            '<button class="btn btn-green btn-sm" onclick="doAction(\'check\')">Check ✓</button>' +
            '<button class="btn btn-primary btn-sm" onclick="doAction(\'bet\')">Miser</button>' +
            '<input type="number" id="betAmount" class="bet-input" value="' + defaultBet + '" min="1" title="Mise totale">' +
            '<button class="btn btn-sm" onclick="doAction(\'fold\')">Fold ✕</button>';
    }

    if (savedValue !== null) {
        var newInput = document.getElementById('betAmount');
        if (newInput) {
            newInput.value = savedValue;
            if (wasFocused) newInput.focus();
        }
    }
}

function escHtml(s) {
    if (!s) return '';
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Fonction pour demander une confirmation
function confirmAction(message, callback) {
    var overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = '
        <div class="confirm-box">
            <h3>' + message + '</h3>
            <div class="confirm-buttons">
                <button class="btn btn-green" onclick="confirmYes()">Oui</button>
                <button class="btn btn-sm" onclick="confirmNo()">Non</button>
            </div>
        </div>
    ';
    document.body.appendChild(overlay);
    
    window.confirmYes = function() {
        overlay.remove();
        callback(true);
    };
    window.confirmNo = function() {
        overlay.remove();
        callback(false);
    };
}

// Fonction pour confirmer la sortie de la partie
function confirmQuit(event) {
    event.preventDefault();
    confirmAction('Êtes-vous sûr de vouloir quitter la partie ?', function(yes) {
        if (yes) {
            window.location.href = 'poker.php';
        }
    });
}

// Sons du jeu (désactivés si les fichiers n'existent pas)
var sounds = {
    deal: new Audio('sounds/deal.mp3'),
    bet: new Audio('sounds/bet.mp3'),
    call: new Audio('sounds/call.mp3'),
    raise: new Audio('sounds/raise.mp3'),
    fold: new Audio('sounds/fold.mp3'),
    win: new Audio('sounds/win.mp3'),
    chips: new Audio('sounds/chips.mp3'),
    error: new Audio('sounds/error.mp3')
};

// Désactiver les sons qui ne chargent pas
Object.keys(sounds).forEach(function(key) {
    sounds[key].addEventListener('error', function() {
        sounds[key].src = ''; // Désactiver le son
    });
});

// Fonction pour jouer un son
function playSound(name) {
    if (sounds[name] && sounds[name].src) {
        sounds[name].currentTime = 0; // Réinitialiser pour permettre de jouer plusieurs fois
        sounds[name].play().catch(function() {}); // Ignorer les erreurs
    }
}

function dealCards() {
    if (isDealingAnimation) return;
    isDealingAnimation = true;
    if (autoDealTimer) { clearInterval(autoDealTimer); autoDealTimer = null; }
    prevCommCount = 0;

    var overlay = document.getElementById('dealOverlay');
    overlay.classList.add('show');
    playSound('deal'); // Jouer le son de distribution

    pokerPost('deal', {id: sessionId}, function(d) {
        if (d.success) {
            currentHandId = d.hand_id;
            currentTour = 'preflop';
            setTimeout(function() {
                overlay.classList.remove('show');
                isDealingAnimation = false;
                loadState(function() {
                    runBotTurn();
                });
            }, 900);
        } else {
            overlay.classList.remove('show');
            isDealingAnimation = false;
            playSound('error');
        }
    });
}

function doAction(action) {
    if (botPlaying) return;
    
    // Demander confirmation pour fold
    if (action === 'fold') {
        confirmAction('Êtes-vous sûr de vouloir fold ?', function(yes) {
            if (yes) {
                botPlaying = true;
                pokerPost('action', {
                    id: sessionId,
                    hand_id: currentHandId,
                    action: action,
                    montant: 0
                }, function(d) {
                    if (d.success) {
                        loadState(function() { runBotTurn(); });
                    } else {
                        botPlaying = false;
                    }
                });
            }
        });
        return;
    }
    
    botPlaying = true;
    var montant = 0;
    if (action === 'bet' || action === 'raise') {
        montant = parseInt(document.getElementById('betAmount').value) || 1;
    }
    pokerPost('action', {
        id: sessionId,
        hand_id: currentHandId,
        action: action,
        montant: montant
    }, function(d) {
        if (d.success) {
            // Jouer le son correspondant à l'action
            if (action === 'bet') playSound('bet');
            else if (action === 'call') playSound('call');
            else if (action === 'raise') playSound('raise');
            else if (action === 'fold') playSound('fold');
            loadState(function() { runBotTurn(); });
        } else {
            botPlaying = false;
            playSound('error');
        }
    });
}

function runBotTurn() {
    if (!currentHandId) { botPlaying = false; return; }
    botPlaying = true;
    pokerPost('bot_play', {
        id: sessionId,
        hand_id: currentHandId
    }, function(d) {
        if (d.success) {
            // Afficher les notifications pour les actions des bots
            if (d.bot_actions && d.bot_actions.length > 0) {
                d.bot_actions.forEach(function(botAction) {
                    var botName = 'Bot ' + (botAction.player_id % 10); // Nom simplifié
                    var actionMsg = botName + ' a ';
                    if (botAction.action === 'fold') {
                        actionMsg += 'fold';
                    } else if (botAction.action === 'check') {
                        actionMsg += 'check';
                    } else if (botAction.action === 'call') {
                        actionMsg += 'suivi (' + botAction.montant + ' chips)';
                    } else if (botAction.action === 'bet') {
                        actionMsg += 'misé ' + botAction.montant + ' chips';
                    } else if (botAction.action === 'raise') {
                        actionMsg += 'relancé à ' + botAction.montant + ' chips';
                    }
                    showNotification(actionMsg, 'info');
                });
            }
            
            if (d.need_showdown) {
                botPlaying = false;
                runShowdown();
            } else if (d.advance_to) {
                currentTour = d.advance_to;
                loadState(function() {
                    if (d.advance_to !== 'showdown') runBotTurn();
                    else botPlaying = false;
                });
            } else {
                botPlaying = false;
                loadState();
            }
        } else { botPlaying = false; loadState(); }
    });
}

function runShowdown() {
    if (!currentHandId) return;
    pokerPost('showdown', {
        id: sessionId,
        hand_id: currentHandId
    }, function(d) {
        if (d.success) {
            document.getElementById('actionsBar').style.display = 'none';
            loadState(function() {
                setTimeout(function() { showWinner(d); }, 3000);
            });
        } else {
            loadState();
        }
    });
}

function showWinner(data) {
    var overlay = document.getElementById('winnerOverlay');
    overlay.classList.add('show');
    playSound('win'); // Jouer le son de victoire

    var winners = data.winners || [data.winner];
    var hand = data.hand_name || '';
    var winnerName;
    if (winners.length > 1) {
        var names = winners.map(function(w) { return w.display_name || '?'; });
        winnerName = '(Egalite) ' + names.join(' & ');
        hand = hand + ' — ' + data.pot_share + ' chips chacun';
    } else {
        winnerName = (winners[0]?.display_name || '?') + ' gagne !';
        hand = hand + ' — Pot: ' + data.pot + ' chips';
    }
    document.getElementById('winnerName').textContent = winnerName;
    document.getElementById('winnerHand').textContent = hand;
    document.getElementById('actionsBar').style.display = 'none';
    currentHandId = 0;
    currentTour = null;

    document.getElementById('quitBtn').style.display = 'inline-block';

    var countdown = 3;
    var info = document.getElementById('nextHandInfo');
    var num = document.getElementById('countdownNum');
    info.style.display = 'block';
    num.textContent = countdown;

    if (autoDealTimer) clearInterval(autoDealTimer);
    autoDealTimer = setInterval(function() {
        countdown--;
        num.textContent = countdown;
        if (countdown <= 0) {
            clearInterval(autoDealTimer);
            autoDealTimer = null;
            overlay.classList.remove('show');
            setTimeout(function() { dealCards(); }, 300);
        }
    }, 1000);
}

function addBot() {
    pokerPost('join_bot', {id: sessionId}, function(d) {
        loadState();
    });
}

loadState();
pollTimer = setInterval(function() {
    if (!botPlaying && !document.getElementById('winnerOverlay').classList.contains('show') && !isDealingAnimation) {
        loadState();
    }
}, 3000);
</script>

</body>
</html>
