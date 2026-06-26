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

// Récupérer chips du joueur
$stmt = $pdo->prepare("SELECT pseudo, chips FROM utilisateur WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Sessions actives (friend games are private — hidden from lobby)
$sessions = $pdo->prepare("
    SELECT s.*, u.pseudo AS host_pseudo,
        (SELECT COUNT(*) FROM poker_players WHERE session_id = s.id) AS nb_players
    FROM poker_sessions s
    JOIN utilisateur u ON u.id = s.host_id
    WHERE s.statut = 'waiting' AND s.type != 'vs_friends'
    ORDER BY s.created_at DESC
    LIMIT 20
");
$sessions->execute();
$activeSessions = $sessions->fetchAll(PDO::FETCH_ASSOC);

// Sessions créées par l'utilisateur
$mySessions = $pdo->prepare("
    SELECT s.*,
        (SELECT COUNT(*) FROM poker_players WHERE session_id = s.id) AS nb_players
    FROM poker_sessions s
    WHERE s.host_id = ?
    ORDER BY s.created_at DESC
    LIMIT 20
");
$mySessions->execute([$userId]);
$userSessions = $mySessions->fetchAll(PDO::FETCH_ASSOC);

// Stats du joueur
$stats = $pdo->prepare("
    SELECT COUNT(*) AS parties_jouees,
           COALESCE(SUM(CASE WHEN pp.chips_apres > pp.chips_avant THEN 1 ELSE 0 END), 0) AS parties_gagnees
    FROM poker_players pp
    JOIN poker_sessions ps ON ps.id = pp.session_id
    WHERE pp.user_id = ? AND ps.statut = 'finished'
");
$stats->execute([$userId]);
$playerStats = $stats->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Poker — Nova</title>
<script src="jsqr.js"></script>
<link rel="stylesheet" href="css/variables.css">
<link rel="stylesheet" href="css/animations.css">
<link rel="stylesheet" href="css/poker.css">
</head>
<body>

<?php include '../header.php'; ?>

<div class="page-header">
    <h1>🃏 Poker</h1>
    <div class="chips-badge">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        <span id="chipsDisplay"><?= number_format($user['chips']) ?></span> chips
    </div>
</div>

<div class="grid">

    <!-- Colonne gauche : sessions actives -->
    <div>
        <div class="card" style="margin-bottom:24px;">
            <h2>Parties en cours</h2>
            <?php if (empty($activeSessions)): ?>
                <p class="no-sessions">Aucune partie active. Crée ou rejoins une partie !</p>
            <?php else: ?>
                <div class="session-list">
                <?php foreach ($activeSessions as $s): ?>
                    <a href="poker_game.php?id=<?= $s['id'] ?>" class="session-item">
                        <div class="session-info">
                            <span class="session-host"><?= htmlspecialchars($s['host_pseudo']) ?></span>
                            <span class="session-meta">
                                <?= $s['nb_players'] ?> joueur(s) · <?= $s['mise'] ?> chips de mise ·
                                <?= $s['type'] === 'vs_bots' ? 'vs Bots' : ($s['type'] === 'mixte' ? 'Mixte' : 'vs Amis') ?>
                            </span>
                        </div>
                        <span class="session-status <?= $s['statut'] === 'waiting' ? 'status-waiting' : 'status-playing' ?>">
                            <?= $s['statut'] === 'waiting' ? 'En attente' : 'En cours' ?>
                        </span>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-bottom:24px;">
            <h2>Mes parties</h2>
            <?php if (empty($userSessions)): ?>
                <p class="no-sessions">Tu n'as créé aucune partie.</p>
            <?php else: ?>
                <div class="session-list">
                <?php foreach ($userSessions as $s): ?>
                    <div class="session-item" style="position:relative;">
                        <a href="poker_game.php?id=<?= $s['id'] ?>" style="text-decoration:none;color:inherit;display:flex;align-items:center;justify-content:space-between;flex:1;">
                            <div class="session-info">
                                <span class="session-host">
                                    <?= htmlspecialchars($s['type'] === 'vs_bots' ? 'vs Bots' : ($s['type'] === 'mixte' ? 'Mixte' : 'vs Amis')) ?>
                                    <span style="font-weight:400;font-size:0.78rem;color:var(--muted);">· <?= $s['nb_players'] ?> joueur(s)</span>
                                </span>
                                <span class="session-meta"><?= $s['mise'] ?> chips · <?= $s['code_invite'] ? 'Code: ' . $s['code_invite'] : '' ?></span>
                            </div>
                            <span class="session-status <?= $s['statut'] === 'waiting' ? 'status-waiting' : ($s['statut'] === 'playing' ? 'status-playing' : 'status-finished') ?>">
                                <?= $s['statut'] === 'waiting' ? 'En attente' : ($s['statut'] === 'playing' ? 'En cours' : 'Terminé') ?>
                            </span>
                        </a>
                        <button class="btn btn-delete" onclick="deleteSession(<?= $s['id'] ?>)" title="Supprimer">✕</button>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Colonne droite : créer + stats -->
    <div>
        <div class="card" style="margin-bottom:16px;">
            <h2>Nouvelle partie</h2>
            <div class="create-options">
                <label>Type de partie</label>
                <select id="gameType" onchange="toggleBotsSelect()">
                    <option value="vs_bots">vs Bots</option>
                    <option value="vs_friends">vs Amis</option>
                    <option value="mixte">Mixte (amis + bots)</option>
                </select>
                <label>Buy-in (chips de départ)</label>
                <input type="number" id="gameMise" value="100" min="10" max="100000">
                <span style="font-size:0.72rem;color:var(--muted);font-weight:600;margin-top:-6px;">Chaque joueur apporte ce montant à la table</span>
                <label id="botsLabel">Nombre de bots</label>
                <select id="gameBots">
                    <option value="1">1 bot</option>
                    <option value="2" selected>2 bots</option>
                    <option value="3">3 bots</option>
                    <option value="4">4 bots</option>
                    <option value="5">5 bots</option>
                </select>
                <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <button class="btn btn-primary" onclick="createGame()">Créer la partie →</button>
            </div>
            <div class="invite-box" id="inviteBox">
                <p style="font-weight:700;margin-bottom:8px;">Code d'invitation :</p>
                <code id="inviteCode"></code>
                <div class="qr-placeholder"><img id="qrImg" style="display:none;max-width:100%;max-height:100%;" alt="QR code"></div>
                <button class="btn btn-secondary" style="margin-top:8px;width:100%;" onclick="copyInvite()">Copier le lien</button>
                <button class="btn btn-primary" id="goToGameBtn" style="margin-top:6px;width:100%;display:none;" onclick="goToGame()">Aller à la partie →</button>
            </div>
        </div>

        <div class="card" style="margin-bottom:16px;">
            <h2>Rejoindre par code</h2>
            <div style="display:flex;gap:8px;">
                <input type="text" id="joinCode" placeholder="Code d'invitation" style="flex:1;padding:10px 12px;border:var(--border);border-radius:10px;font-family:'Plus Jakarta Sans',sans-serif;font-weight:600;font-size:0.9rem;outline:none;box-shadow:var(--sh);">
                <button class="btn btn-secondary" onclick="joinByCode()">→</button>
                <button class="btn btn-secondary" onclick="startScanner()" title="Scanner un QR code">📷</button>
            </div>
            <div id="joinError" style="color:var(--red);font-size:0.78rem;font-weight:600;margin-top:6px;display:none;"></div>
            <div id="scannerBox" style="display:none;margin-top:10px;position:relative;">
                <video id="scannerVideo" style="width:100%;border-radius:10px;border:var(--border);box-shadow:var(--sh);" autoplay playsinline></video>
                <canvas id="scannerCanvas" style="display:none;"></canvas>
                <div id="scannerStatus" style="font-size:0.78rem;font-weight:600;color:var(--muted);margin-top:4px;text-align:center;">Scanne le QR code…</div>
                <button class="btn btn-sm" style="margin-top:4px;width:100%;" onclick="stopScanner()">Annuler</button>
            </div>
        </div>

        <div class="card">
            <h2>Mes stats</h2>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-number"><?= (int)($playerStats['parties_jouees'] ?? 0) ?></div>
                    <div class="stat-label">Parties</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?= (int)($playerStats['parties_gagnees'] ?? 0) ?></div>
                    <div class="stat-label">Victoires</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?= number_format($user['chips']) ?></div>
                    <div class="stat-label">Chips</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?= (int)($playerStats['parties_jouees'] ?? 0) > 0 ? round(($playerStats['parties_gagnees'] ?? 0) / max($playerStats['parties_jouees'], 1) * 100) : 0 ?>%</div>
                    <div class="stat-label">Winrate</div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
var _pendingRedirect = null;

function toggleBotsSelect() {
    var type = document.getElementById('gameType').value;
    var botsWrap = document.getElementById('gameBots');
    var botsLabel = document.getElementById('botsLabel');
    var hide = type === 'vs_friends';
    botsWrap.style.display = hide ? 'none' : 'block';
    botsLabel.style.display = hide ? 'none' : 'block';
}

function createGame() {
    var type = document.getElementById('gameType').value;
    var mise = parseInt(document.getElementById('gameMise').value) || 10;
    var bots = parseInt(document.getElementById('gameBots').value) || 0;
    var csrfToken = document.getElementById('csrfToken').value;

    // Validation des inputs
    if (mise < 10 || mise > 100000) {
        alert('La mise doit être comprise entre 10 et 100 000 chips.');
        return;
    }
    if (bots < 0 || bots > 6) {
        alert('Le nombre de bots doit être compris entre 0 et 6.');
        return;
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'poker_api.php?action=create', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var d = JSON.parse(xhr.responseText);
            if (d.success) {
                _pendingRedirect = d.redirect;
                if (d.invite_code) {
                    document.getElementById('inviteCode').textContent = d.invite_code;
                    document.getElementById('inviteBox').classList.add('show');
                    document.getElementById('goToGameBtn').style.display = 'block';
                    var img = document.getElementById('qrImg');
                    var url = window.location.origin + window.location.pathname.replace(/\/poker\.php.*$/, '') + '/poker_game.php?code=' + d.invite_code;
                    img.onerror = function() { img.style.display = 'none'; };
                    img.src = 'qr_gen.php?url=' + encodeURIComponent(url);
                    img.style.display = 'block';
                }
                if (d.redirect && !d.invite_code) {
                    window.location.href = d.redirect;
                }
            } else {
                alert(d.error || 'Erreur');
            }
        } catch(e) { alert('Erreur réseau: ' + e.message); }
    };
    xhr.send('type=' + encodeURIComponent(type) + '&mise=' + mise + '&bots=' + bots + '&csrf_token=' + encodeURIComponent(csrfToken));
}

function goToGame() {
    if (_pendingRedirect) window.location.href = _pendingRedirect;
}

function copyInvite() {
    var code = document.getElementById('inviteCode').textContent;
    var url = window.location.origin + window.location.pathname.replace(/\/poker\.php.*$/, '') + '/poker_game.php?code=' + code;
    navigator.clipboard.writeText(url).then(function() {
        var btn = document.querySelector('#inviteBox .btn-secondary');
        btn.textContent = '✓ Copié !';
        setTimeout(function() { btn.textContent = 'Copier le lien'; }, 2000);
    });
}

function joinByCode() {
    var code = document.getElementById('joinCode').value.trim();
    var csrfToken = document.getElementById('csrfToken').value;
    // Validation du code
    if (!code || code.length < 8) {
        err.textContent = 'Le code doit faire au moins 8 caractères.';
        err.style.display = 'block';
        return;
    }
    if (!code) return;
    var err = document.getElementById('joinError');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'poker_api.php?action=join', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var d = JSON.parse(xhr.responseText);
            if (d.success) {
                err.style.display = 'none';
                if (d.redirect) window.location.href = d.redirect;
            } else {
                err.textContent = d.error || 'Erreur';
                err.style.display = 'block';
            }
        } catch(e) { err.textContent = 'Erreur réseau'; err.style.display = 'block'; }
    };
    xhr.send('code=' + encodeURIComponent(code) + '&csrf_token=' + encodeURIComponent(csrfToken));
}

var scannerStream = null;
var scannerTimer = null;

function startScanner() {
    var box = document.getElementById('scannerBox');
    var video = document.getElementById('scannerVideo');
    box.style.display = 'block';
    document.getElementById('scannerStatus').textContent = 'Accès caméra…';
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function(stream) {
            scannerStream = stream;
            video.srcObject = stream;
            video.play();
            document.getElementById('scannerStatus').textContent = 'Scanne le QR code…';
            scanFrame();
        }).catch(function() {
            document.getElementById('scannerStatus').textContent = 'Erreur caméra';
        });
    } else {
        document.getElementById('scannerStatus').textContent = 'Caméra non disponible';
    }
}

function scanFrame() {
    var video = document.getElementById('scannerVideo');
    var canvas = document.getElementById('scannerCanvas');
    if (video.readyState !== video.HAVE_ENOUGH_DATA) {
        scannerTimer = setTimeout(scanFrame, 300);
        return;
    }
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    var ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);
    var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    var code = jsQR(imageData.data, imageData.width, imageData.height);
    if (code) {
        stopScanner();
        var match = code.data.match(/[?&]code=([a-f0-9]+)/);
        if (match) {
            document.getElementById('joinCode').value = match[1];
            joinByCode();
        } else {
            document.getElementById('scannerStatus').textContent = 'QR code invalide';
        }
        return;
    }
    scannerTimer = setTimeout(scanFrame, 300);
}

function stopScanner() {
    if (scannerTimer) { clearTimeout(scannerTimer); scannerTimer = null; }
    if (scannerStream) {
        scannerStream.getTracks().forEach(function(t) { t.stop(); });
        scannerStream = null;
    }
    document.getElementById('scannerBox').style.display = 'none';
}

function deleteSession(id) {
    if (!confirm('Supprimer cette partie ? Les chips seront remboursés aux joueurs.')) return;
    var csrfToken = document.getElementById('csrfToken').value;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'poker_api.php?action=delete', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var d = JSON.parse(xhr.responseText);
            if (d.success) {
                location.reload();
            } else {
                alert(d.error || 'Erreur');
            }
        } catch(e) { alert('Erreur réseau'); }
    };
    xhr.send('id=' + id + '&csrf_token=' + encodeURIComponent(csrfToken));
}
</script>

</body>
</html>
