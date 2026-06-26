-- Migration pour ajouter les index manquants sur les tables de poker
-- Exécuter ce fichier dans votre base de données pour améliorer les performances

-- Index pour poker_sessions
CREATE INDEX IF NOT EXISTS idx_poker_sessions_host_id ON poker_sessions(host_id);
CREATE INDEX IF NOT EXISTS idx_poker_sessions_code_invite ON poker_sessions(code_invite);
CREATE INDEX IF NOT EXISTS idx_poker_sessions_statut ON poker_sessions(statut);
CREATE INDEX IF NOT EXISTS idx_poker_sessions_type ON poker_sessions(type);

-- Index pour poker_players
CREATE INDEX IF NOT EXISTS idx_poker_players_session_id ON poker_players(session_id);
CREATE INDEX IF NOT EXISTS idx_poker_players_user_id ON poker_players(user_id);
CREATE INDEX IF NOT EXISTS idx_poker_players_bot_id ON poker_players(bot_id);
CREATE INDEX IF NOT EXISTS idx_poker_players_position ON poker_players(position);
CREATE INDEX IF NOT EXISTS idx_poker_players_est_actif ON poker_players(est_actif);
CREATE INDEX IF NOT EXISTS idx_poker_players_folded ON poker_players(folded);

-- Index pour poker_hands
CREATE INDEX IF NOT EXISTS idx_poker_hands_session_id ON poker_hands(session_id);
CREATE INDEX IF NOT EXISTS idx_poker_hands_gagnant_id ON poker_hands(gagnant_id);
CREATE INDEX IF NOT EXISTS idx_poker_hands_tour_actuel ON poker_hands(tour_actuel);

-- Index pour poker_actions
CREATE INDEX IF NOT EXISTS idx_poker_actions_hand_id ON poker_actions(hand_id);
CREATE INDEX IF NOT EXISTS idx_poker_actions_player_id ON poker_actions(player_id);
CREATE INDEX IF NOT EXISTS idx_poker_actions_tour ON poker_actions(tour);
CREATE INDEX IF NOT EXISTS idx_poker_actions_action ON poker_actions(action);
