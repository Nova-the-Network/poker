<?php
/**
 * Fichier de corrections critiques pour le projet Poker
 * À inclure au début de poker_api.php, poker.php et poker_game.php
 * ou à appliquer manuellement.
 */

// =============================================
// 1. CORRECTIONS POUR LES CHEMINS DE FICHIERS
// =============================================
// Dans poker_api.php, remplacer :
//   $rootPath = dirname(__DIR__, 2);
//   require_once $rootPath . '/config.php';
//   require_once $rootPath . '/classes/autoload.php';
// Par :
//   require_once __DIR__ . '/../config.php';
//   require_once __DIR__ . '/classes/autoload.php';

// =============================================
// 2. CORRECTIONS POUR LES FONCTIONS DE VALIDATION
// =============================================
/**
 * Valide un entier (version corrigée)
 */
function validateInt($value, $min, $max, $default) {
    if (!is_numeric($value)) {
        return $default;
    }
    $intValue = (int)$value;
    if ($intValue < $min || $intValue > $max) {
        return $default;
    }
    return $intValue;
}

/**
 * Valide une chaîne de caractères (version corrigée)
 */
function validateString($value, $minLength = 1, $maxLength = 255) {
    $str = trim($value ?? '');
    if (strlen($str) < $minLength || strlen($str) > $maxLength) {
        return '';
    }
    return $str;
}

// =============================================
// 3. CORRECTIONS POUR LA GESTION DES ERREURS
// =============================================
// Remplacer set_error_handler et set_exception_handler dans poker_api.php par :
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    file_put_contents(__DIR__ . '/error_log.txt', sprintf("[%s] [%d] %s dans %s à la ligne %d\n", date('Y-m-d H:i:s'), $errno, $errstr, $errfile, $errline), FILE_APPEND);
    return false;
});

set_exception_handler(function($e) {
    file_put_contents(__DIR__ . '/error_log.txt', sprintf("[%s] Exception: %s dans %s à la ligne %d\n", date('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine()), FILE_APPEND);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Une erreur est survenue. Veuillez réessayer.']);
    exit;
});

// =============================================
// 4. CORRECTIONS POUR LA VÉRIFICATION DES SESSIONS
// =============================================
// Dans poker.php et poker_game.php, remplacer :
//   if (!isset($_SESSION['user_id'])) {
//       header("Location: ../index.php");
//       exit;
//   }
// Par :
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    die(); // Ajout de die() pour s'assurer que le script s'arrête
}

// =============================================
// 5. CORRECTIONS POUR LA GÉNÉRATION DU CSRF TOKEN
// =============================================
// Dans poker.php, poker_game.php et poker_api.php, remplacer :
//   if (empty($_SESSION['csrf_token'])) {
//       $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
//   }
// Par :
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)) ?: uniqid('', true);
}

// =============================================
// 6. CORRECTIONS POUR LES REQUÊTES SQL (XSS)
// =============================================
/**
 * Fonction pour échapper les sorties HTML
 */
function escapeHtml($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Utiliser escapeHtml() pour toutes les sorties utilisateur dans les fichiers HTML.
// Exemple : echo escapeHtml($user['pseudo']);

// =============================================
// 7. CORRECTIONS POUR LES VÉRIFICATIONS DE FICHIERS
// =============================================
// Dans poker.php et poker_game.php, remplacer :
//   require_once '../config.php';
// Par :
$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die("Erreur: Fichier config.php introuvable. Vérifiez le chemin: $configPath");
}
require_once $configPath;

// =============================================
// 8. CORRECTIONS POUR LES MESSAGES D'ERREUR
// =============================================
// Dans poker_api.php, remplacer :
//   echo "Partie introuvable.";
//   exit;
// Par :
//   die("Partie introuvable.");

// =============================================
// 9. CORRECTIONS POUR LES VALIDATIONS DE CODE D'INVITATION
// =============================================
/**
 * Valide un code d'invitation
 */
function validateInviteCode($code) {
    return preg_match('/^[a-zA-Z0-9]{6,8}$/', $code);
}

// Utiliser dans poker_game.php pour vérifier $code :
// if (!validateInviteCode($code)) {
//     die("Code d'invitation invalide.");
// }

// =============================================
// 10. CORRECTIONS POUR LES TRANSACTIONS SQL
// =============================================
// Dans poker_api.php, entourer toutes les opérations critiques de :
// try {
//     $pdo->beginTransaction();
//     // ... opérations SQL ...
//     $pdo->commit();
// } catch (PDOException $e) {
//     $pdo->rollBack();
//     throw $e;
// }
