<?php
ini_set('display_errors', 0); // Désactivé en production pour la sécurité
error_reporting(0);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config.php';

// Charger les classes
require_once __DIR__ . '/../classes/BotAI.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Non connecté']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// Vérifier le token CSRF pour les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide']);
        exit;
    }
}

// Fonction pour valider un entier dans une plage
function validateInt($value, $min, $max, $default) {
    $intValue = (int)$value;
    if ($intValue < $min || $intValue > $max) {
        return $default;
    }
    return $intValue;
}

// Fonction pour valider une chaîne de caractères
function validateString($value, $minLength = 1, $maxLength = 255) {
    $str = trim($value ?? '');
    if (strlen($str) < $minLength || strlen($str) > $maxLength) {
        return '';
    }
    return $str;
}

try {

switch ($action) {

    // ═══════════════════════════════════════
    // CRÉER UNE PARTIE
    // ═══════════════════════════════════════
    case 'create':
        $type = validateString($_POST['type'] ?? '');
        if (!in_array($type, ['vs_bots','vs_friends','mixte'])) {
            $type = 'vs_bots';
        }
        $mise = validateInt($_POST['mise'] ?? 100, 10, 100000, 100);
        $nbBots = ($type === 'vs_friends') ? 0 : validateInt($_POST['bots'] ?? 0, 0, 6, 0);

        $stmt = $pdo->prepare("SELECT chips FROM utilisateur WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user['chips'] < $mise) {
            echo json_encode(['success' => false, 'error' => 'Pas assez de chips']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM poker_players pp JOIN poker_sessions ps ON ps.id = pp.session_id WHERE pp.user_id = ? AND ps.statut = 'playing'");
        $stmt->execute([$userId]);
        if ((int)$stmt->fetchColumn() >= 3) {
            echo json_encode(['success' => false, 'error' => 'Tu as déjà 3 parties en cours maximum']);
            exit;
        }

        $code = substr(bin2hex(random_bytes(4)), 0, 8);
        $stmt = $pdo->prepare("SELECT id FROM poker_sessions WHERE code_invite = ?");
        $stmt->execute([$code]);
        while ($stmt->fetch()) {
            $code = substr(bin2hex(random_bytes(4)), 0, 8);
            $stmt->execute([$code]);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO poker_sessions (code_invite, host_id, statut, type, nb_bots, mise) VALUES (?, ?, 'waiting', ?, ?, ?)");
        $stmt->execute([$code, $userId, $type, $nbBots, $mise]);
        $sessionId = $pdo->lastInsertId();

        $chipsAvant = ($type === 'vs_friends') ? min($mise, $user['chips']) : $user['chips'];
        $stmt = $pdo->prepare("INSERT INTO poker_players (session_id, user_id, position, chips_avant, mise_joueur) VALUES (?, ?, 0, ?, 0)");
        $stmt->execute([$sessionId, $userId, $chipsAvant]);

        if ($type === 'vs_friends') {
            $pdo->prepare("UPDATE utilisateur SET chips = chips - ? WHERE id = ?")->execute([$chipsAvant, $userId]);
        }

        $pdo->commit();

        if ($type === 'vs_bots' || $type === 'mixte') {
            $botsNeeded = $nbBots;
            $pos = 1;

            $stmt = $pdo->prepare("SELECT * FROM poker_bots ORDER BY RAND() LIMIT ?");
            $stmt->execute([$botsNeeded]);
            $bots = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            foreach ($bots as $bot) {
                $stmt = $pdo->prepare("INSERT INTO poker_players (session_id, bot_id, position, chips_avant, mise_joueur) VALUES (?, ?, ?, ?, 0)");
                $stmt->execute([$sessionId, $bot['id'], $pos, $bot['chips']]);
                $pos++;
            }
            $pdo->prepare("UPDATE poker_sessions SET statut = 'playing', nb_joueurs = (SELECT COUNT(*) FROM poker_players WHERE session_id = ?) WHERE id = ?")
                ->execute([$sessionId, $sessionId]);
            $pdo->commit();
        }

        echo json_encode([
            'success' => true,
            'redirect' => 'poker_game.php?id=' . $sessionId,
            'invite_code' => $type !== 'vs_bots' ? $code : null
        ]);
        break;

    // ═══════════════════════════════════════
    // REJOINDRE PAR CODE
    // ═══════════════════════════════════════
    case 'join':
        $code = validateString($_POST['code'] ?? '', 8, 8); // Code doit faire exactement 8 caractères
        if (!$code) {
            echo json_encode(['success' => false, 'error' => 'Code invalide (8 caractères requis)']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM poker_sessions WHERE code_invite = ? AND statut = 'waiting' AND type != 'vs_bots'");
        $stmt->execute([$code]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            echo json_encode(['success' => false, 'error' => 'Partie introuvable ou déjà commencée']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM poker_players WHERE session_id = ? AND user_id = ?");
        $stmt->execute([$session['id'], $userId]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => true, 'redirect' => 'poker_game.php?id=' . $session['id']]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT chips FROM utilisateur WHERE id = ?");
        $stmt->execute([$userId]);
        $chips = $stmt->fetchColumn();
        if ($chips < $session['mise']) {
            echo json_encode(['success' => false, 'error' => 'Pas assez de chips']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM poker_players WHERE session_id = ?");
        $stmt->execute([$session['id']]);
        $pos = (int)$stmt->fetchColumn();
        if ($pos >= 7) {
            echo json_encode(['success' => false, 'error' => 'Partie pleine (max 7 joueurs)']);
            exit;
        }

        $pdo->beginTransaction();
        $chipsAvant = ($session['type'] === 'vs_friends') ? min((int)$session['mise'], $chips) : $chips;
        $pdo->prepare("INSERT INTO poker_players (session_id, user_id, position, chips_avant, mise_joueur) VALUES (?, ?, ?, ?, 0)")
            ->execute([$session['id'], $userId, $pos, $chipsAvant]);
        if ($session['type'] === 'vs_friends') {
            $pdo->prepare("UPDATE utilisateur SET chips = chips - ? WHERE id = ?")->execute([$chipsAvant, $userId]);
        }
        $pdo->commit();

        echo json_encode(['success' => true, 'redirect' => 'poker_game.php?id=' . $session['id']]);
        break;

    // ═══════════════════════════════════════
    // ÉTAT DE LA PARTIE
    // ═══════════════════════════════════════
    case 'state':
        $sessionId = (int)($_GET['id'] ?? 0);
        if (!$sessionId) {
            echo json_encode(['success' => false, 'error' => 'ID requis']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM poker_sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            echo json_encode(['success' => false, 'error' => 'Partie introuvable']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT pp.*, u.pseudo, u.photo,
                   COALESCE(b.nom, u.pseudo) AS display_name,
                   CASE WHEN pp.user_id IS NOT NULL THEN 'user' ELSE 'bot' END AS player_type
            FROM poker_players pp
            LEFT JOIN utilisateur u ON u.id = pp.user_id
            LEFT JOIN poker_bots b ON b.id = pp.bot_id
            WHERE pp.session_id = ?
            ORDER BY pp.position
        ");
        $stmt->execute([$sessionId]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $finished = $session['statut'] === 'finished';

        // Récupérer la main active et les cartes communes
        $hand = null;
        $currentPot = $session['pot'] ?? 0;
        $currentTour = null;
        $communautaires = [];

        if ($session['statut'] === 'playing') {
            $stmt = $pdo->prepare("SELECT * FROM poker_hands WHERE session_id = ? AND gagnant_id IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$sessionId]);
            $hand = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($hand) {
                $currentPot = (int)$hand['pot'];
                $currentTour = $hand['tour_actuel'];
                if ($hand['communautaires']) {
                    $communautaires = json_decode($hand['communautaires'], true) ?? [];
                }
            }
        }

        $handFinished = $hand && $hand['gagnant_id'];
        foreach ($players as &$p) {
            if ($handFinished) continue;
            if ($finished) continue;
            if ($p['user_id'] != $userId) {
                $p['cartes'] = null;
            }
        }
        unset($p);

        // Déterminer quel joueur doit agir
        $currentActionPlayerId = null;
        $currentActionPlayerIsBot = false;
        if ($hand && !$handFinished && in_array($hand['tour_actuel'] ?? '', ['preflop','flop','turn','river'])) {
            $tour = $hand['tour_actuel'];
            $dealerPos = (int)$hand['dealer_pos'];
            $miseCourante = (int)$hand['mise_courante'];
            $handId = (int)$hand['id'];

            $stmt = $pdo->prepare("SELECT * FROM poker_players WHERE session_id = ? AND est_actif = 1 AND folded = 0 ORDER BY position");
            $stmt->execute([$sessionId]);
            $orderedPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $numOrdered = count($orderedPlayers);

            if ($numOrdered > 0) {
                // Trouver l'index du dealer dans la liste non-foldés
                $dealerIdx = -1;
                foreach ($orderedPlayers as $i => $p) {
                    if ((int)$p['position'] === $dealerPos) {
                        $dealerIdx = $i;
                        break;
                    }
                }

                if ($tour === 'preflop') {
                    if ($numOrdered === 2) {
                        $startIdx = $dealerIdx;
                    } else {
                        $bbIdx = ($dealerIdx + 2) % $numOrdered;
                        $startIdx = ($bbIdx + 1) % $numOrdered;
                    }
                } else {
                    $startIdx = ($dealerIdx >= 0) ? ($dealerIdx + 1) % $numOrdered : 0;
                }

                for ($pi = 0; $pi < $numOrdered; $pi++) {
                    $p = $orderedPlayers[($startIdx + $pi) % $numOrdered];
                    if ((int)$p['chips_avant'] <= 0) continue;

                    $stmtB = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM poker_actions WHERE hand_id = ? AND player_id = ? AND tour = ?");
                    $stmtB->execute([$handId, $p['id'], $tour]);
                    $roundBet = (int)$stmtB->fetchColumn();

                    $stmtC = $pdo->prepare("SELECT id FROM poker_actions WHERE hand_id = ? AND player_id = ? AND tour = ? AND action != 'blind'");
                    $stmtC->execute([$handId, $p['id'], $tour]);
                    $hasActed = $stmtC->fetch();

                    $mustAct = false;
                    if ($miseCourante == 0) {
                        if (!$hasActed) $mustAct = true;
                    } else {
                        if ($roundBet < $miseCourante) $mustAct = true;
                        if ($tour === 'preflop' && !$hasActed && $roundBet == $miseCourante) $mustAct = true;
                    }

                    if ($mustAct) {
                        $currentActionPlayerId = (int)$p['id'];
                        $currentActionPlayerIsBot = !empty($p['bot_id']);
                        break;
                    }
                }
            }
        }

        echo json_encode([
            'success' => true,
            'session' => $session,
            'current_pot' => $currentPot,
            'current_tour' => $currentTour,
            'communautaires' => $communautaires,
            'hand' => $hand,
            'players' => $players,
            'current_user_id' => $userId,
            'current_action_player_id' => $currentActionPlayerId,
            'current_action_player_is_bot' => $currentActionPlayerIsBot
        ]);
        break;

    // ═══════════════════════════════════════
    // DISTRIBUER — 2 cartes + blinds + preflop
    // ═══════════════════════════════════════
    case 'deal':
        $sessionId = validateInt($_POST['id'] ?? 0, 1, PHP_INT_MAX, 0);
        if (!$sessionId) {
            echo json_encode(['success' => false, 'error' => 'ID requis']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM poker_sessions WHERE id = ? AND host_id = ?");
        $stmt->execute([$sessionId, $userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            echo json_encode(['success' => false, 'error' => 'Action non autorisée']);
            exit;
        }

        // Vérifier qu'aucune main n'est en cours
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM poker_hands WHERE session_id = ? AND gagnant_id IS NULL");
        $stmt->execute([$sessionId]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Une main est déjà en cours']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM poker_players WHERE session_id = ? AND est_actif = 1 ORDER BY position");
        $stmt->execute([$sessionId]);
        $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($players) < 2) {
            echo json_encode(['success' => false, 'error' => 'Pas assez de joueurs']);
            exit;
        }

        // Blinds fixes basés sur la mise
        $bigBlind = max(2, (int)($session['mise'] / 20));
        $smallBlind = max(1, (int)($bigBlind / 2));

        // Déterminer le dealer : stocke la position réelle (pas l'index)
        $stmt = $pdo->prepare("SELECT dealer_pos FROM poker_hands WHERE session_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sessionId]);
        $lastRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $lastDealerPos = $lastRow ? (int)$lastRow['dealer_pos'] : -1;
        $numPlayers = count($players);

        // Trouver l'index du précédent dealer dans la liste actuelle
        $lastDealerIdx = -1;
        if ($lastDealerPos !== -1) {
            foreach ($players as $i => $p) {
                if ((int)$p['position'] === $lastDealerPos) {
                    $lastDealerIdx = $i;
                    break;
                }
            }
        }
        $dealerIdx = ($lastDealerIdx + 1) % $numPlayers;
        $dealerPos = (int)$players[$dealerIdx]['position']; // valeur réelle de la colonne position

        // Trouver positions SB, BB par indice dans la liste ordonnée
        if ($numPlayers === 2) {
            $sbIdx = $dealerIdx;
            $bbIdx = ($dealerIdx + 1) % $numPlayers;
        } else {
            $sbIdx = ($dealerIdx + 1) % $numPlayers;
            $bbIdx = ($dealerIdx + 2) % $numPlayers;
        }
        $sbPos = (int)$players[$sbIdx]['position'];
        $bbPos = (int)$players[$bbIdx]['position'];

        $pdo->beginTransaction();

        // Passer la session en 'playing' si elle était en 'waiting' (partie multi)
        $pdo->prepare("UPDATE poker_sessions SET statut = 'playing' WHERE id = ? AND statut = 'waiting'")
            ->execute([$sessionId]);

        // Synchroniser chips_avant depuis chips_apres (joueurs qui ont gagné une main depuis la dernière distribution)
        $stmt = $pdo->prepare("UPDATE poker_players SET chips_avant = chips_apres, chips_apres = NULL WHERE session_id = ? AND chips_apres IS NOT NULL");
        $stmt->execute([$sessionId]);

        // Refill les bots à 0 chips pour que la partie continue
        $stmt = $pdo->prepare("SELECT id, position, chips_avant, bot_id FROM poker_players WHERE session_id = ? AND est_actif = 1");
        $stmt->execute([$sessionId]);
        $freshPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($freshPlayers as $fp) {
            if ($fp['bot_id'] && $fp['chips_avant'] <= 0) {
                $refill = $fp['bot_id'] <= 2 ? 5000 : ($fp['bot_id'] <= 4 ? 8000 : 12000);
                $pdo->prepare("UPDATE poker_players SET chips_avant = ? WHERE id = ?")->execute([$refill, $fp['id']]);
                // Mettre à jour le joueur dans le tableau players
                foreach ($players as &$pp) {
                    if ($pp['id'] === $fp['id']) { $pp['chips_avant'] = $refill; break; }
                }
                unset($pp);
            }
        }

        // Distribuer 2 cartes par joueur
        $deck = createDeck();
        shuffle($deck);

        $playersById = [];
        foreach ($players as $p) {
            $playersById[$p['position']] = $p;
            $handCards = array_splice($deck, 0, 2);
            $pdo->prepare("UPDATE poker_players SET cartes = ?, folded = 0, mise_joueur = 0 WHERE id = ?")
                ->execute([json_encode($handCards), $p['id']]);
        }

        // Poster les blinds (dans chips_avant)
        $pot = 0;

        $sbPlayer = $playersById[$sbPos];
        $sbActual = min($smallBlind, $sbPlayer['chips_avant']);
        $pdo->prepare("UPDATE poker_players SET mise_joueur = mise_joueur + ?, chips_avant = chips_avant - ? WHERE id = ?")
            ->execute([$sbActual, $sbActual, $sbPlayer['id']]);
        if ($sbPlayer['user_id'] && $session['type'] !== 'vs_friends') {
            $pdo->prepare("UPDATE utilisateur SET chips = chips - ? WHERE id = ?")->execute([$sbActual, $sbPlayer['user_id']]);
        }
        $pot += $sbActual;

        $bbPlayer = $playersById[$bbPos];
        $bbActual = min($bigBlind, $bbPlayer['chips_avant']);
        $pdo->prepare("UPDATE poker_players SET mise_joueur = mise_joueur + ?, chips_avant = chips_avant - ? WHERE id = ?")
            ->execute([$bbActual, $bbActual, $bbPlayer['id']]);
        if ($bbPlayer['user_id'] && $session['type'] !== 'vs_friends') {
            $pdo->prepare("UPDATE utilisateur SET chips = chips - ? WHERE id = ?")->execute([$bbActual, $bbPlayer['user_id']]);
        }
        $pot += $bbActual;

        $stmt = $pdo->prepare("SELECT COALESCE(MAX(num_main), 0) + 1 FROM poker_hands WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $numMain = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("INSERT INTO poker_hands (session_id, num_main, pot, dealer_pos, mise_courante, tour_actuel) VALUES (?, ?, ?, ?, ?, 'preflop')");
        $stmt->execute([$sessionId, $numMain, $pot, $dealerPos, $bigBlind]);
        $handId = $pdo->lastInsertId();

        // Sauvegarder les blinds dans la session pour les actions futures
        $pdo->prepare("UPDATE poker_sessions SET small_blind = ?, big_blind = ? WHERE id = ?")
            ->execute([$smallBlind, $bigBlind, $sessionId]);

        // Enregistrer les blinds comme actions preflop pour que le tracking mise_courante soit correct
        if ($sbActual > 0) {
            $pdo->prepare("INSERT INTO poker_actions (hand_id, player_id, tour, action, montant) VALUES (?, ?, 'preflop', 'blind', ?)")
                ->execute([$handId, $sbPlayer['id'], $sbActual]);
        }
        if ($bbActual > 0) {
            $pdo->prepare("INSERT INTO poker_actions (hand_id, player_id, tour, action, montant) VALUES (?, ?, 'preflop', 'blind', ?)")
                ->execute([$handId, $bbPlayer['id'], $bbActual]);
        }

        $pdo->commit();

        echo json_encode(['success' => true, 'hand_id' => $handId, 'num_main' => $numMain, 'small_blind' => $sbActual, 'big_blind' => $bbActual]);
        break;

    // ═══════════════════════════════════════
    // ACTION : CHECK, BET, CALL, RAISE, FOLD
    // ═══════════════════════════════════════
    case 'action':
        $sessionId = validateInt($_POST['id'] ?? 0, 1, PHP_INT_MAX, 0);
        $handId = validateInt($_POST['hand_id'] ?? 0, 1, PHP_INT_MAX, 0);
        $actionType = validateString($_POST['action'] ?? '');
        $montant = validateInt($_POST['montant'] ?? 0, 0, PHP_INT_MAX, 0);

        // Valider le type d'action
        $validActions = ['check', 'bet', 'call', 'raise', 'fold'];
        if (!$sessionId || !$handId || !in_array($actionType, $validActions)) {
            echo json_encode(['success' => false, 'error' => 'Paramètres invalides']);
            exit;
        }

        // Lire le tour depuis la BDD
        $stmt = $pdo->prepare("SELECT * FROM poker_hands WHERE id = ? AND session_id = ?");
        $stmt->execute([$handId, $sessionId]);
        $hand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hand) {
            echo json_encode(['success' => false, 'error' => 'Main introuvable']);
            exit;
        }
        $tourBDD = $hand['tour_actuel'];
        if (!in_array($tourBDD, ['preflop', 'flop', 'turn', 'river'])) {
            echo json_encode(['success' => false, 'error' => 'Tour invalide pour une action']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM poker_players WHERE session_id = ? AND user_id = ? AND est_actif = 1");
        $stmt->execute([$sessionId, $userId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$player) {
            echo json_encode(['success' => false, 'error' => 'Tu n\'es pas dans cette partie']);
            exit;
        }

        // Lire les blinds depuis la session
        $stmt = $pdo->prepare("SELECT big_blind FROM poker_sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $bbSession = (int)$stmt->fetchColumn();
        $bigBlind = $bbSession > 0 ? $bbSession : 10;

        $pdo->beginTransaction();

        $stmtT = $pdo->prepare("SELECT type FROM poker_sessions WHERE id = ?");
        $stmtT->execute([$sessionId]);
        $sessionType = $stmtT->fetchColumn();

        // Re-fetch player et hand dans la transaction (données à jour)
        $stmt = $pdo->prepare("SELECT * FROM poker_players WHERE id = ?");
        $stmt->execute([$player['id']]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$player) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Joueur introuvable']);
            exit;
        }
        if ($player['chips_avant'] <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Tu es tapis']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM poker_hands WHERE id = ? AND session_id = ?");
        $stmt->execute([$handId, $sessionId]);
        $hand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hand) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Main introuvable']);
            exit;
        }
        $miseCourante = (int)$hand['mise_courante'];
        $tourBDD = $hand['tour_actuel'];

        // Total misé par ce joueur dans ce tour (incluant blinds)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM poker_actions WHERE hand_id = ? AND player_id = ? AND tour = ?");
        $stmt->execute([$handId, $player['id'], $tourBDD]);
        $playerRoundBet = (int)$stmt->fetchColumn();

        // Vérifier si le joueur a déjà agi (hors blind) ET a suivi la mise courante
        $stmt = $pdo->prepare("SELECT id FROM poker_actions WHERE hand_id = ? AND player_id = ? AND tour = ? AND action != 'blind'");
        $stmt->execute([$handId, $player['id'], $tourBDD]);
        $hasAction = $stmt->fetch();
        if ($hasAction && $playerRoundBet >= $miseCourante) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Tu as déjà agi ce tour-ci']);
            exit;
        }
        // Si $hasAction mais $playerRoundBet < $miseCourante → relance d'un autre joueur, on autorise la ré-action

        if ($actionType === 'fold') {
            $pdo->prepare("UPDATE poker_players SET folded = 1 WHERE id = ?")->execute([$player['id']]);
            $montant = 0;
        } elseif ($actionType === 'check') {
            if ($miseCourante > $playerRoundBet) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Tu dois suivre ou te coucher']);
                exit;
            }
            $montant = 0;
        } elseif ($actionType === 'bet') {
            if ($miseCourante > 0 && $miseCourante > $playerRoundBet) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Tu dois suivre, pas miser (il y a déjà une mise)']);
                exit;
            }
            // $montant = total voulu
            $totalVoulu = $montant;
            $additional = $totalVoulu - $playerRoundBet;
            if ($additional <= 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Mise invalide']);
                exit;
            }
            $additional = min($additional, $player['chips_avant']);
            $totalReel = $playerRoundBet + $additional;
            $pdo->prepare("UPDATE poker_players SET mise_joueur = mise_joueur + ?, chips_avant = chips_avant - ? WHERE id = ?")
                ->execute([$additional, $additional, $player['id']]);
            $pdo->prepare("UPDATE poker_hands SET pot = pot + ?, mise_courante = ? WHERE id = ?")
                ->execute([$additional, $totalReel, $handId]);
            if ($player['user_id'] && $sessionType !== 'vs_friends') {
                $pdo->prepare("UPDATE utilisateur SET chips = chips - ? WHERE id = ?")->execute([$additional, $userId]);
            }
        } elseif ($actionType === 'raise') {
            if ($miseCourante <= $playerRoundBet) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Personne n\'a misé, tu dois bet (pas raise)']);
                exit;
            }
            // $montant = total voulu
            $totalVoulu = $montant;
            if ($totalVoulu <= $miseCourante) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'La relance doit dépasser la mise courante']);
                exit;
            }
            $additional = $totalVoulu - $playerRoundBet;
            $additional = min($additional, $player['chips_avant']);
            if ($additional <= ($miseCourante - $playerRoundBet)) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'La relance est trop faible']);
                exit;
            }
            $totalReel = $playerRoundBet + $additional;
            $pdo->prepare("UPDATE poker_players SET mise_joueur = mise_joueur + ?, chips_avant = chips_avant - ? WHERE id = ?")
                ->execute([$additional, $additional, $player['id']]);
            $pdo->prepare("UPDATE poker_hands SET pot = pot + ?, mise_courante = ? WHERE id = ?")
                ->execute([$additional, $totalReel, $handId]);
            if ($player['user_id'] && $sessionType !== 'vs_friends') {
                $pdo->prepare("UPDATE utilisateur SET chips = chips - ? WHERE id = ?")->execute([$additional, $userId]);
            }
        } elseif ($actionType === 'call') {
            if ($miseCourante <= $playerRoundBet) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Rien à suivre']);
                exit;
            }
            $toCall = min($miseCourante - $playerRoundBet, $player['chips_avant']);
            if ($toCall <= 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Montant à suivre invalide']);
                exit;
            }
            $montant = $toCall;
            $pdo->prepare("UPDATE poker_players SET mise_joueur = mise_joueur + ?, chips_avant = chips_avant - ? WHERE id = ?")
                ->execute([$montant, $montant, $player['id']]);
            $pdo->prepare("UPDATE poker_hands SET pot = pot + ? WHERE id = ?")->execute([$montant, $handId]);
            if ($player['user_id'] && $sessionType !== 'vs_friends') {
                $pdo->prepare("UPDATE utilisateur SET chips = chips - ? WHERE id = ?")->execute([$montant, $userId]);
            }
        }

        $pdo->prepare("INSERT INTO poker_actions (hand_id, player_id, tour, action, montant) VALUES (?, ?, ?, ?, ?)")
            ->execute([$handId, $player['id'], $tourBDD, $actionType, $montant]);

        $pdo->commit();

        echo json_encode(['success' => true]);
        break;

    // ═══════════════════════════════════════
    // JOUER LES BOTS + AVANCER LES TOURS
    // ═══════════════════════════════════════
    case 'bot_play':
        $sessionId = validateInt($_POST['id'] ?? 0, 1, PHP_INT_MAX, 0);
        $handId = validateInt($_POST['hand_id'] ?? 0, 1, PHP_INT_MAX, 0);

        if (!$sessionId || !$handId) {
            echo json_encode(['success' => false, 'error' => 'Paramètres manquants']);
            exit;
        }

        // Lire le tour depuis la BDD
        $stmt = $pdo->prepare("SELECT * FROM poker_hands WHERE id = ? AND session_id = ?");
        $stmt->execute([$handId, $sessionId]);
        $hand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hand) {
            echo json_encode(['success' => false, 'error' => 'Main introuvable']);
            exit;
        }
        $tour = $hand['tour_actuel'];

        // Vérifier que l'appelant est un joueur actif de cette session
        $stmt = $pdo->prepare("SELECT id FROM poker_players WHERE session_id = ? AND user_id = ? AND est_actif = 1");
        $stmt->execute([$sessionId, $userId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Action non autorisée']);
            exit;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM poker_sessions WHERE id = ?");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        // Re-fetch current hand data (mise_courante peut avoir changé)
        $hand = $pdo->prepare("SELECT * FROM poker_hands WHERE id = ?");
        $hand->execute([$handId]);
        $hand = $hand->fetch(PDO::FETCH_ASSOC);
        $miseCourante = (int)$hand['mise_courante'];
        $tour = $hand['tour_actuel'];

        // Tous les joueurs actifs non-foldés, par ordre de position
        $stmt = $pdo->prepare("SELECT * FROM poker_players WHERE session_id = ? AND est_actif = 1 AND folded = 0 ORDER BY position");
        $stmt->execute([$sessionId]);
        $orderedPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $numOrdered = count($orderedPlayers);

        $startIdx = 0;
        $dealerPos = (int)$hand['dealer_pos'];

        // Trouver l'index du dealer dans la liste non-foldés
        $dealerIdx = -1;
        foreach ($orderedPlayers as $i => $p) {
            if ((int)$p['position'] === $dealerPos) {
                $dealerIdx = $i;
                break;
            }
        }

        if ($tour === 'preflop') {
            if ($numOrdered === 2) {
                $startIdx = $dealerIdx;
            } else {
                $bbIdx = ($dealerIdx + 2) % $numOrdered;
                $startIdx = ($bbIdx + 1) % $numOrdered;
            }
        } else {
            $startIdx = ($dealerIdx >= 0) ? ($dealerIdx + 1) % $numOrdered : 0;
        }

        $actions = [];
        $stoppedAtHuman = false;
        $advanceTo = null;
        $needShowdown = false;
        $allActed = false;
        $botPasses = 0;
        
        // Cache pour les données des joueurs (roundBet, hasActed)
        $playerCache = [];

        do {
            if (++$botPasses > 30) break;
            for ($pi = 0; $pi < $numOrdered; $pi++) {
                $player = $orderedPlayers[($startIdx + $pi) % $numOrdered];
                if ($player['chips_avant'] <= 0) continue;

                // Vérifier si les données sont déjà en cache
                $playerId = $player['id'];
                if (!isset($playerCache[$playerId])) {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM poker_actions WHERE hand_id = ? AND player_id = ? AND tour = ?");
                    $stmt->execute([$handId, $playerId, $tour]);
                    $roundBet = (int)$stmt->fetchColumn();

                    $stmtA = $pdo->prepare("SELECT id FROM poker_actions WHERE hand_id = ? AND player_id = ? AND tour = ? AND action != 'blind'");
                    $stmtA->execute([$handId, $playerId, $tour]);
                    $hasActed = $stmtA->fetch();
                    
                    $playerCache[$playerId] = ['roundBet' => $roundBet, 'hasActed' => $hasActed];
                } else {
                    $roundBet = $playerCache[$playerId]['roundBet'];
                    $hasActed = $playerCache[$playerId]['hasActed'];
                }

                if ($miseCourante == 0) {
                    if ($hasActed) continue;
                } else {
                    if ($roundBet >= $miseCourante) {
                        if ($tour === 'preflop' && !$hasActed && $roundBet == $miseCourante) {
                        } else {
                            continue;
                        }
                    }
                }

                if (!$player['bot_id']) {
                    $stoppedAtHuman = true;
                    break;
                }

                // Utiliser BotAI pour décider de l'action
                $botAI = new BotAI($pdo);
                $botAction = $botAI->decideAction($handId, $player['id'], $hand, $player);
                $action = $botAction['action'];
                $betMontant = $botAction['montant'];
                $toCall = $miseCourante - $roundBet;

                $montantReel = 0;
                if ($action === 'fold') {
                    $pdo->prepare("UPDATE poker_players SET folded = 1 WHERE id = ?")->execute([$bot['id']]);
                } elseif ($action === 'check') {
                    if ($miseCourante > $roundBet) {
                        $pdo->prepare("UPDATE poker_players SET folded = 1 WHERE id = ?")->execute([$bot['id']]);
                        $action = 'fold';
                    }
                } elseif ($action === 'call') {
                    $montantReel = min($toCall, $bot['chips_avant']);
                    $pdo->prepare("UPDATE poker_players SET mise_joueur = mise_joueur + ?, chips_avant = chips_avant - ? WHERE id = ?")
                        ->execute([$montantReel, $montantReel, $bot['id']]);
                    $pdo->prepare("UPDATE poker_hands SET pot = pot + ? WHERE id = ?")->execute([$montantReel, $handId]);
                } elseif ($action === 'bet' || $action === 'raise') {
                    $montantReel = min($betMontant, $bot['chips_avant']);
                    if ($montantReel <= $roundBet) {
                        if ($toCall > 0) {
                            $montantReel = min($toCall, $bot['chips_avant']);
                            $action = 'call';
                        } else {
                            $action = 'check';
                            $montantReel = 0;
                        }
                    } else {
                        $pdo->prepare("UPDATE poker_players SET mise_joueur = mise_joueur + ?, chips_avant = chips_avant - ? WHERE id = ?")
                            ->execute([$montantReel, $montantReel, $bot['id']]);
                        $newMise = $roundBet + $montantReel;
                        $pdo->prepare("UPDATE poker_hands SET pot = pot + ?, mise_courante = ? WHERE id = ?")
                            ->execute([$montantReel, $newMise, $handId]);
                        $miseCourante = $newMise;
                    }
                }

                $pdo->prepare("INSERT INTO poker_actions (hand_id, player_id, tour, action, montant) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$handId, $bot['id'], $tour, $action, $montantReel]);

                $actions[] = ['player_id' => $bot['id'], 'action' => $action, 'montant' => $montantReel];
            }

            if ($stoppedAtHuman) break;

            // Re-fetch joueurs non-foldés après ce pass
            $stmt = $pdo->prepare("SELECT * FROM poker_players WHERE session_id = ? AND est_actif = 1 AND folded = 0 ORDER BY position");
            $stmt->execute([$sessionId]);
            $remainingPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($remainingPlayers) <= 1) {
                $needShowdown = true;
                break;
            }

            // Re-fetch mise_courante depuis la BDD (peut avoir changé après une relance)
            $stmt = $pdo->prepare("SELECT mise_courante FROM poker_hands WHERE id = ?");
            $stmt->execute([$handId]);
            $mc = (int)$stmt->fetchColumn();

            $allActed = true;
            foreach ($remainingPlayers as $ap) {
                if ($ap['chips_avant'] <= 0) continue;
                
                // Utiliser le cache si disponible
                $apId = $ap['id'];
                if (isset($playerCache[$apId])) {
                    $rb = $playerCache[$apId]['roundBet'];
                    $ha = $playerCache[$apId]['hasActed'];
                } else {
                    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM poker_actions WHERE hand_id = ? AND player_id = ? AND tour = ?");
                    $stmt->execute([$handId, $apId, $tour]);
                    $rb = (int)$stmt->fetchColumn();
                    $stmtA = $pdo->prepare("SELECT id FROM poker_actions WHERE hand_id = ? AND player_id = ? AND tour = ? AND action != 'blind'");
                    $stmtA->execute([$handId, $apId, $tour]);
                    $ha = $stmtA->fetch();
                    $playerCache[$apId] = ['roundBet' => $rb, 'hasActed' => $ha];
                }
                
                if ($mc == 0) {
                    if (!$ha) { $allActed = false; break; }
                } else {
                    if ($rb < $mc) { $allActed = false; break; }
                    if ($tour === 'preflop' && !$ha && $rb == $mc) { $allActed = false; break; }
                }
            }

            if (!$allActed) {
                $stmt = $pdo->prepare("SELECT * FROM poker_players WHERE session_id = ? AND est_actif = 1 AND folded = 0 ORDER BY position");
                $stmt->execute([$sessionId]);
                $orderedPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $numOrdered = count($orderedPlayers);
                if ($numOrdered <= 1) { $needShowdown = true; break; }
                $miseCourante = $mc;
                $startIdx = 0;
                continue;
            }
        } while (!$stoppedAtHuman && !$needShowdown && !$allActed);

        if ($allActed && !$stoppedAtHuman && !$needShowdown) {
            $tourOrder = ['preflop' => 'flop', 'flop' => 'turn', 'turn' => 'river', 'river' => null];
            $nextTour = $tourOrder[$tour] ?? null;

            if ($nextTour) {
                $advanceTo = $nextTour;
                $deck = createDeck();
                $stmtA = $pdo->prepare("SELECT cartes FROM poker_players WHERE session_id = ? AND cartes IS NOT NULL");
                $stmtA->execute([$sessionId]);
                while ($rowA = $stmtA->fetch(PDO::FETCH_ASSOC)) {
                    $dealtCards = json_decode($rowA['cartes'], true);
                    if (!$dealtCards) continue;
                    foreach ($dealtCards as $dc) {
                        $idx = array_search($dc['value'] . '_' . $dc['suit'], array_map(function($d) {
                            return $d['value'] . '_' . $d['suit'];
                        }, $deck));
                        if ($idx !== false) array_splice($deck, $idx, 1);
                    }
                }
                $existingComm = $hand['communautaires'] ? json_decode($hand['communautaires'], true) : [];
                foreach ($existingComm as $ec) {
                    $idx = array_search($ec['value'] . '_' . $ec['suit'], array_map(function($d) {
                        return $d['value'] . '_' . $d['suit'];
                    }, $deck));
                    if ($idx !== false) array_splice($deck, $idx, 1);
                }

                shuffle($deck);

                $cardsToDeal = $nextTour === 'flop' ? 3 : 1;
                $newCards = array_splice($deck, 0, $cardsToDeal);
                $allCommunity = array_merge($existingComm, $newCards);

                $pdo->prepare("UPDATE poker_hands SET communautaires = ?, tour_actuel = ?, mise_courante = 0 WHERE id = ?")
                    ->execute([json_encode($allCommunity), $nextTour, $handId]);
            } else {
                $needShowdown = true;
                $advanceTo = 'showdown';
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'bot_actions' => $actions,
            'all_acted' => $allActed,
            'advance_to' => $advanceTo,
            'need_showdown' => $needShowdown
        ]);
        break;

    // ═══════════════════════════════════════
    // SHOWDOWN — Comparer les mains et déclarer le gagnant
    // ═══════════════════════════════════════
    case 'showdown':
        $sessionId = validateInt($_POST['id'] ?? 0, 1, PHP_INT_MAX, 0);
        $handId = validateInt($_POST['hand_id'] ?? 0, 1, PHP_INT_MAX, 0);

        if (!$sessionId || !$handId) {
            echo json_encode(['success' => false, 'error' => 'Paramètres manquants']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM poker_players WHERE session_id = ? AND user_id = ? AND est_actif = 1");
        $stmt->execute([$sessionId, $userId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Action non autorisée']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM poker_hands WHERE id = ? AND session_id = ?");
        $stmt->execute([$handId, $sessionId]);
        $hand = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hand || $hand['gagnant_id']) {
            echo json_encode(['success' => false, 'error' => 'Main déjà terminée ou introuvable']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT pp.*, COALESCE(u.pseudo, b.nom, 'Joueur') AS display_name
            FROM poker_players pp
            LEFT JOIN utilisateur u ON u.id = pp.user_id
            LEFT JOIN poker_bots b ON b.id = pp.bot_id
            WHERE pp.session_id = ? AND pp.est_actif = 1 AND pp.folded = 0
        ");
        $stmt->execute([$sessionId]);
        $activePlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($activePlayers)) {
            echo json_encode(['success' => false, 'error' => 'Aucun joueur actif']);
            exit;
        }

        $community = $hand['communautaires'] ? json_decode($hand['communautaires'], true) : [];

        if (count($activePlayers) === 1) {
            $winners = [$activePlayers[0]];
            $handName = 'Victoire par abandon';
        } else {
            $bestScore = -1;
            $bestKicker = -1;
            $winners = [];
            $handName = '';
            $botAI = new BotAI($pdo);

            foreach ($activePlayers as $p) {
                if (!$p['cartes']) continue;
                $holeCards = json_decode($p['cartes'], true);
                $allCards = array_merge($holeCards, $community);
                if (count($allCards) < 5) continue;
                $eval = $botAI->evaluateBestHand($allCards);
                $evalKicker = $eval['kicker'] ?? 0;
                if ($eval['score'] > $bestScore || ($eval['score'] === $bestScore && $evalKicker > $bestKicker)) {
                    $bestScore = $eval['score'];
                    $bestKicker = $evalKicker;
                    $winners = [$p];
                    $handName = $eval['name'];
                } elseif ($eval['score'] === $bestScore && $evalKicker === $bestKicker) {
                    $winners[] = $p;
                }
            }

            if (empty($winners)) {
                $winners = [$activePlayers[0]];
                $handName = '?';
            }
        }

        $pdo->beginTransaction();

        $stmtT = $pdo->prepare("SELECT type FROM poker_sessions WHERE id = ?");
        $stmtT->execute([$sessionId]);
        $sType = $stmtT->fetchColumn();

        $pot = (int)$hand['pot'];
        $potShare = (int)($pot / count($winners));
        $remainder = $pot % count($winners);

        foreach ($winners as $idx => $w) {
            $share = $potShare + ($idx === 0 ? $remainder : 0);
            $newChips = (int)$w['chips_avant'] + $share;
            $pdo->prepare("UPDATE poker_players SET chips_apres = ? WHERE id = ?")->execute([$newChips, $w['id']]);
            if ($w['user_id'] && $sType !== 'vs_friends') {
                $pdo->prepare("UPDATE utilisateur SET chips = chips + ? WHERE id = ?")->execute([$share, $w['user_id']]);
            }
        }

        $pdo->prepare("UPDATE poker_hands SET gagnant_id = ?, main_gagnante = ?, pot = ? WHERE id = ?")
            ->execute([$winners[0]['id'], $handName, $pot, $handId]);

        $pdo->prepare("UPDATE poker_sessions SET pot = ? WHERE id = ?")->execute([$pot, $sessionId]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'winners' => $winners,
            'winner' => $winners[0],
            'hand_name' => $handName,
            'pot' => $pot,
            'pot_share' => $potShare,
            'game_over' => false
        ]);
        break;

    // ═══════════════════════════════════════
    // AJOUTER UN BOT (hôte)
    // ═══════════════════════════════════════
    case 'join_bot':
        $sessionId = validateInt($_POST['id'] ?? 0, 1, PHP_INT_MAX, 0);
        if (!$sessionId) {
            echo json_encode(['success' => false, 'error' => 'ID requis']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM poker_sessions WHERE id = ? AND host_id = ? AND statut = 'waiting'");
        $stmt->execute([$sessionId, $userId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Action non autorisée']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM poker_players WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $count = (int)$stmt->fetchColumn();
        if ($count >= 7) {
            echo json_encode(['success' => false, 'error' => 'Maximum 7 joueurs atteint']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM poker_bots ORDER BY RAND() LIMIT 1");
        $stmt->execute();
        $bot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bot) {
            echo json_encode(['success' => false, 'error' => 'Aucun bot disponible']);
            exit;
        }

        $pdo->prepare("INSERT INTO poker_players (session_id, bot_id, position, chips_avant, mise_joueur) VALUES (?, ?, ?, ?, 0)")
            ->execute([$sessionId, $bot['id'], $count, $bot['chips']]);

        $pdo->prepare("UPDATE poker_sessions SET nb_bots = nb_bots + 1 WHERE id = ?")->execute([$sessionId]);

        echo json_encode(['success' => true]);
        break;

    // ═══════════════════════════════════════
    // SUPPRIMER UNE PARTIE (hôte uniquement)
    // ═══════════════════════════════════════
    case 'delete':
        $sessionId = validateInt($_POST['id'] ?? 0, 1, PHP_INT_MAX, 0);
        if (!$sessionId) {
            echo json_encode(['success' => false, 'error' => 'ID requis']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM poker_sessions WHERE id = ? AND host_id = ?");
        $stmt->execute([$sessionId, $userId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Action non autorisée']);
            exit;
        }

        $pdo->beginTransaction();

        // Rendre les chips aux joueurs humains (chips_apres si un showdown a eu lieu, sinon chips_avant)
        $stmt = $pdo->prepare("SELECT pp.id, COALESCE(pp.chips_apres, pp.chips_avant) AS chips_restant, pp.user_id FROM poker_players pp WHERE pp.session_id = ? AND pp.user_id IS NOT NULL");
        $stmt->execute([$sessionId]);
        $humanPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($humanPlayers as $hp) {
            if ($hp['chips_restant'] > 0) {
                $pdo->prepare("UPDATE utilisateur SET chips = chips + ? WHERE id = ?")->execute([$hp['chips_restant'], $hp['user_id']]);
            }
        }

        $pdo->prepare("DELETE FROM poker_actions WHERE hand_id IN (SELECT id FROM poker_hands WHERE session_id = ?)")->execute([$sessionId]);
        $pdo->prepare("DELETE FROM poker_hands WHERE session_id = ?")->execute([$sessionId]);
        $pdo->prepare("DELETE FROM poker_players WHERE session_id = ?")->execute([$sessionId]);
        $pdo->prepare("DELETE FROM poker_sessions WHERE id = ?")->execute([$sessionId]);

        $pdo->commit();

        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action inconnue']);
}

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Poker API Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erreur serveur']);
}

// ═══════════════════════════════════════
// FONCTIONS DU JEU
// ═══════════════════════════════════════

function createDeck(): array {
    $values = ['2','3','4','5','6','7','8','9','10','Valet','Dame','Roi','As'];
    $suits = ['♠','♥','♦','♣'];
    $deck = [];
    foreach ($suits as $suit) {
        foreach ($values as $value) {
            $deck[] = ['value' => $value, 'suit' => $suit];
        }
    }
    return $deck;
}
