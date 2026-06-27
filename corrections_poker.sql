-- =============================================
-- CORRECTIONS POUR LES TABLES DU POKER
-- (Sans toucher à la table utilisateur)
-- À exécuter dans phpMyAdmin ou via un client MySQL
-- =============================================

-- =============================================
-- 1. CORRECTION DE LA TABLE poker_players
-- Ajout des colonnes manquantes (mise_joueur, folded, chips_avant, chips_apres, est_actif)
-- =============================================
ALTER TABLE `poker_players`
  ADD COLUMN IF NOT EXISTS `mise_joueur` INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `folded` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `chips_avant` INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `chips_apres` INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `est_actif` TINYINT(1) NOT NULL DEFAULT 1;

-- =============================================
-- 2. CORRECTION DE LA TABLE poker_hands
-- Ajout des colonnes manquantes (dealer_pos, mise_courante, tour_actuel, jouee_a)
-- =============================================
ALTER TABLE `poker_hands`
  ADD COLUMN IF NOT EXISTS `dealer_pos` INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `mise_courante` INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `tour_actuel` ENUM('preflop','flop','turn','river') NOT NULL DEFAULT 'preflop',
  ADD COLUMN IF NOT EXISTS `jouee_a` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

-- =============================================
-- 3. CORRECTION DE LA COLLATION POUR LES COLONNES JSON
-- (Évite les problèmes avec les symboles de cartes comme ♠, ♥, etc.)
-- =============================================
ALTER TABLE `poker_players`
  MODIFY COLUMN `cartes` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL;

ALTER TABLE `poker_hands`
  MODIFY COLUMN `communautaires` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL;

-- =============================================
-- 4. AJOUT DES CLÉS ÉTRANGÈRES (Optionnel mais recommandé)
-- =============================================
-- Lier poker_players à poker_sessions
ALTER TABLE `poker_players`
  ADD CONSTRAINT IF NOT EXISTS `fk_players_session`
  FOREIGN KEY (`session_id`) REFERENCES `poker_sessions` (`id`) ON DELETE CASCADE;

-- Lier poker_players à poker_bots (si bot_id n'est pas NULL)
ALTER TABLE `poker_players`
  ADD CONSTRAINT IF NOT EXISTS `fk_players_bot`
  FOREIGN KEY (`bot_id`) REFERENCES `poker_bots` (`id`) ON DELETE SET NULL;

-- Lier poker_hands à poker_sessions
ALTER TABLE `poker_hands`
  ADD CONSTRAINT IF NOT EXISTS `fk_hands_session`
  FOREIGN KEY (`session_id`) REFERENCES `poker_sessions` (`id`) ON DELETE CASCADE;

-- Lier poker_hands à poker_players (pour gagnant_id)
ALTER TABLE `poker_hands`
  ADD CONSTRAINT IF NOT EXISTS `fk_hands_gagnant`
  FOREIGN KEY (`gagnant_id`) REFERENCES `poker_players` (`id`) ON DELETE SET NULL;

-- =============================================
-- 5. VÉRIFICATION DES CLÉS PRIMAIRES
-- (Assurez-vous que les tables ont bien une clé primaire)
-- =============================================
ALTER TABLE `poker_players`
  MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `poker_hands`
  MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT;

-- =============================================
-- 6. AJOUT DES INDEX POUR OPTIMISER LES REQUÊTES
-- =============================================
CREATE INDEX IF NOT EXISTS `idx_poker_players_folded` ON `poker_players` (`folded`);
CREATE INDEX IF NOT EXISTS `idx_poker_players_est_actif` ON `poker_players` (`est_actif`);
CREATE INDEX IF NOT EXISTS `idx_poker_players_session_id` ON `poker_players` (`session_id`);
CREATE INDEX IF NOT EXISTS `idx_poker_hands_tour_actuel` ON `poker_hands` (`tour_actuel`);
CREATE INDEX IF NOT EXISTS `idx_poker_hands_gagnant_id` ON `poker_hands` (`gagnant_id`);
CREATE INDEX IF NOT EXISTS `idx_poker_hands_session_id` ON `poker_hands` (`session_id`);
