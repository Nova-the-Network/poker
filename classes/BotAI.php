<?php

/**
 * Classe BotAI : Gère la logique des bots pour le jeu de poker
 */
class BotAI {
    private $pdo;
    private $handCache = [];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Évalue la meilleure action pour un bot
     * 
     * @param int $handId ID de la main actuelle
     * @param int $botId ID du bot
     * @param array $hand Données de la main actuelle
     * @param array $bot Données du bot
     * @return array ['action' => string, 'montant' => int]
     */
    public function decideAction(int $handId, int $botId, array $hand, array $bot): array {
        $tour = $hand['tour_actuel'];
        $miseCourante = (int)$hand['mise_courante'];
        $botCards = $bot['cartes'] ? json_decode($bot['cartes'], true) : [];
        $community = $hand['communautaires'] ? json_decode($hand['communautaires'], true) : [];
        $allCards = array_merge($botCards, $community);

        $handScore = 0;
        $handName = '';
        if (count($allCards) >= 5) {
            $best = $this->evaluateBestHand($allCards);
            $handScore = $best['score'];
            $handName = $best['name'];
        } elseif (count($botCards) >= 2) {
            $handScore = $this->evaluatePreflop($botCards);
        }

        $roundBet = $this->getRoundBet($handId, $botId, $tour);
        $toCall = $miseCourante - $roundBet;
        
        return $this->chooseAction($tour, $handScore, $toCall, $bot['chips_avant']);
    }

    /**
     * Choisit l'action en fonction du score de la main et du contexte
     * 
     * @param string $tour Tour actuel (preflop, flop, turn, river)
     * @param int $handScore Score de la main
     * @param int $toCall Montant à suivre
     * @param int $chips Chips disponibles
     * @return array ['action' => string, 'montant' => int]
     */
    private function chooseAction(string $tour, int $handScore, int $toCall, int $chips): array {
        if ($tour === 'preflop') {
            if ($handScore > 300) {
                $betMontant = min($chips, max($toCall + $miseCourante, $miseCourante * 2));
                return ['action' => ($toCall > 0) ? 'raise' : 'bet', 'montant' => $betMontant];
            } elseif ($handScore > 100 && $toCall <= $chips * 0.3) {
                return ['action' => ($toCall > 0) ? 'call' : 'check', 'montant' => $toCall];
            } elseif ($toCall > 0 && $toCall <= $chips) {
                return ['action' => 'call', 'montant' => $toCall];
            } else {
                return ['action' => ($toCall > 0) ? 'fold' : 'check', 'montant' => 0];
            }
        } else {
            if ($handScore > 500) {
                $betMontant = min($chips, max($toCall + (int)($miseCourante * 0.5), $miseCourante));
                return ['action' => ($toCall > 0) ? 'raise' : 'bet', 'montant' => $betMontant];
            } elseif ($handScore > 200 && $toCall <= $chips * 0.4) {
                if ($toCall > 0) {
                    return ['action' => 'call', 'montant' => $toCall];
                } else {
                    $betMontant = min($chips, max(1, (int)($miseCourante * 0.5)));
                    return ['action' => 'bet', 'montant' => $betMontant];
                }
            } elseif ($handScore > 100 && $toCall <= $chips * 0.2) {
                return ['action' => ($toCall > 0) ? 'call' : 'check', 'montant' => $toCall];
            } else {
                return ['action' => ($toCall > 0) ? 'fold' : 'check', 'montant' => 0];
            }
        }
    }

    /**
     * Récupère le montant misé par un joueur dans un tour
     * 
     * @param int $handId ID de la main
     * @param int $playerId ID du joueur
     * @param string $tour Tour actuel
     * @return int Montant misé
     */
    private function getRoundBet(int $handId, int $playerId, string $tour): int {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM poker_actions WHERE hand_id = ? AND player_id = ? AND tour = ?");
        $stmt->execute([$handId, $playerId, $tour]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Évalue la meilleure main parmi toutes les combinaisons de 5 cartes
     * 
     * @param array $allCards Tableau de cartes (chaque carte est ['value' => string, 'suit' => string])
     * @return array ['score' => int, 'name' => string, 'kicker' => int]
     */
    public function evaluateBestHand(array $allCards): array {
        // Créer une clé unique pour le cache
        $cacheKey = '';
        foreach ($allCards as $card) {
            $cacheKey .= $card['value'] . $card['suit'];
        }
        
        // Vérifier si le résultat est déjà en cache
        if (isset($this->handCache[$cacheKey])) {
            return $this->handCache[$cacheKey];
        }
        
        $combinations = $this->combinations($allCards, 5);
        $best = ['score' => -1, 'name' => ''];
        foreach ($combinations as $combo) {
            $eval = $this->evaluateHand($combo);
            if ($eval['score'] > $best['score'] || ($eval['score'] === $best['score'] && ($eval['kicker'] ?? 0) > ($best['kicker'] ?? 0))) {
                $best = $eval;
            }
        }
        
        // Stocker le résultat en cache
        $this->handCache[$cacheKey] = $best;
        return $best;
    }

    /**
     * Évalue une main de 5 cartes
     * 
     * @param array $cards Tableau de 5 cartes
     * @return array ['score' => int, 'name' => string, 'kicker' => int]
     */
    private function evaluateHand(array $cards): array {
        $values = array_map(function($c) { return $this->getValueRank($c['value']); }, $cards);
        $suits = array_map(function($c) { return $c['suit']; }, $cards);

        sort($values);
        $vals = array_count_values($values);
        $isFlush = count(array_unique($suits)) === 1;

        $isStraight = false;
        $straightHigh = 0;
        if (count(array_unique($values)) === 5) {
            if (max($values) - min($values) === 4) {
                $isStraight = true;
                $straightHigh = max($values);
            }
            if (in_array(14, $values) && in_array(2, $values) && in_array(3, $values) && in_array(4, $values) && in_array(5, $values)) {
                $isStraight = true;
                $straightHigh = 5;
            }
        }

        $score = 0;
        $kicker = 0;
        $name = 'Carte haute';
        $values_desc = array_reverse($values);

        if ($isFlush && $isStraight && $straightHigh === 14) {
            $score = 1000; $name = 'Quinte Flush Royale';
        } elseif ($isFlush && $isStraight) {
            $score = 900 + $straightHigh; $name = 'Quinte Flush';
        } elseif (in_array(4, $vals)) {
            $quadsVal = array_search(4, $vals);
            $remaining = array_values(array_filter($values_desc, fn($v) => $v != $quadsVal));
            $score = 800 + $quadsVal * 10; $name = 'Carré';
            $kicker = $remaining[0] ?? 0;
        } elseif (in_array(3, $vals) && in_array(2, $vals)) {
            $tripsVal = array_search(3, $vals);
            $pairVal = array_search(2, $vals);
            $score = 700 + $tripsVal * 10; $name = 'Full';
            $kicker = $tripsVal * 16 + $pairVal;
        } elseif ($isFlush) {
            $score = 600 + max($values); $name = 'Couleur';
            $kicker = $this->encodeKickers($values_desc, 5);
        } elseif ($isStraight) {
            $score = 500 + $straightHigh; $name = 'Suite';
        } elseif (in_array(3, $vals)) {
            $tripsVal = array_search(3, $vals);
            $remaining = array_values(array_filter($values_desc, fn($v) => $v != $tripsVal));
            $score = 400 + $tripsVal * 10; $name = 'Brelan';
            $kicker = $this->encodeKickers($remaining, 2);
        } elseif (count(array_keys($vals, 2)) === 2) {
            $pairs = array_keys($vals, 2);
            rsort($pairs);
            $remaining = array_values(array_filter($values_desc, fn($v) => !in_array($v, $pairs)));
            $score = 300 + $pairs[0] * 10; $name = 'Double Paire';
            $kicker = $pairs[0] * 256 + $pairs[1] * 16 + ($remaining[0] ?? 0);
        } elseif (in_array(2, $vals)) {
            $pairVal = array_search(2, $vals);
            $remaining = array_values(array_filter($values_desc, fn($v) => $v != $pairVal));
            $score = 200 + $pairVal * 10; $name = 'Paire';
            $kicker = $this->encodeKickers($remaining, 3);
        } else {
            $score = max($values); $name = 'Carte haute';
            $kicker = $this->encodeKickers($values_desc, 5);
        }

        return ['score' => $score, 'name' => $name, 'kicker' => $kicker];
    }

    /**
     * Évaluation pré-flop simplifiée
     * 
     * @param array $cards Tableau de 2 cartes
     * @return int Score de la main
     */
    private function evaluatePreflop(array $cards): int {
        if (count($cards) < 2) return 0;
        $v1 = $this->getValueRank($cards[0]['value']);
        $v2 = $this->getValueRank($cards[1]['value']);
        $suited = ($cards[0]['suit'] === $cards[1]['suit']);

        $score = 0;
        // Paire
        if ($v1 === $v2) {
            $score = 100 + $v1 * 10;
        } else {
            $high = max($v1, $v2);
            $low = min($v1, $v2);
            $score = $high * 8 + $low * 2;
            if ($suited) $score += 15;
            if ($high - $low <= 2) $score += 10; // Connectors
        }
        return $score;
    }

    /**
     * Génère toutes les combinaisons de k éléments
     * 
     * @param array $array Tableau source
     * @param int $k Nombre d'éléments par combinaison
     * @return array Tableau de combinaisons
     */
    private function combinations(array $array, int $k): array {
        $result = [];
        $n = count($array);
        if ($k <= 0 || $k > $n) return $result;
        $indices = range(0, $k - 1);
        $result[] = array_map(function($i) use ($array) { return $array[$i]; }, $indices);
        while (true) {
            $i = $k - 1;
            while ($i >= 0 && $indices[$i] === $n - $k + $i) $i--;
            if ($i < 0) break;
            $indices[$i]++;
            for ($j = $i + 1; $j < $k; $j++) $indices[$j] = $indices[$j - 1] + 1;
            $result[] = array_map(function($i) use ($array) { return $array[$i]; }, $indices);
        }
        return $result;
    }

    /**
     * Récupère le rang d'une valeur de carte
     * 
     * @param string $value Valeur de la carte (ex: 'As', 'Roi', '10')
     * @return int Rang (2-14)
     */
    private function getValueRank(string $value): int {
        $map = ['2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,'10'=>10,
                'Valet'=>11,'Dame'=>12,'Roi'=>13,'As'=>14];
        return $map[$value] ?? 0;
    }

    /**
     * Encode les kickers en un entier pour le départage
     * 
     * @param array $values Tableau de valeurs
     * @param int $count Nombre de kickers à encoder
     * @return int Valeur encodée
     */
    private function encodeKickers(array $values, int $count): int {
        $result = 0;
        for ($i = 0; $i < $count && $i < count($values); $i++) {
            $result = $result * 16 + $values[$i];
        }
        return $result;
    }
}
