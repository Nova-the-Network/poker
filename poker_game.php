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
<link rel="stylesheet" href="css/variables.css">
<link rel="stylesheet" href="css/animations.css">
<link rel="stylesheet" href="css/game.css">
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
    // Utiliser un chemin absolu pour Infinity Free
    var apiUrl = window.location.pathname.replace(/poker_game\.php.*$/, '') + 'poker_api.php?action=' + action;
    xhr.open('POST', apiUrl, true);
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
    // Utiliser un chemin absolu pour Infinity Free
    var apiUrl = window.location.pathname.replace(/poker_game\.php.*$/, '') + 'poker_api.php?action=state&id=' + sessionId;
    xhr.open('GET', apiUrl, true);
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

        // Chip stack — positionné entre le joueur et le centre (jetons 3D)
        if (p.mise_joueur > 0 && p.folded == 0) {
            var chipStack = document.createElement('div');
            chipStack.className = 'chip-stack';
            var chipX = x + (cx - x) * 0.38;
            var chipY = y + (cy - y) * 0.38;
            chipStack.style.left = chipX + 'px';
            chipStack.style.top = chipY + 'px';
            
            // Calculer le nombre de jetons (1 jeton = 10 chips pour l'affichage)
            var numChips = Math.min(Math.max(1, Math.floor(p.mise_joueur / 10)), 5);
            
            // Créer les jetons empilés
            for (var c = 0; c < numChips; c++) {
                var chip = document.createElement('div');
                chip.className = 'chip';
                chipStack.appendChild(chip);
            }
            
            // Ajouter le texte du montant (optionnel)
            var chipText = document.createElement('div');
            chipText.className = 'chip-text';
            chipText.textContent = p.mise_joueur;
            chipStack.appendChild(chipText);
            
            area.appendChild(chipStack);
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
