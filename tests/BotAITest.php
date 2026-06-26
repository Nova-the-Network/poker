<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour la classe BotAI
 */
class BotAITest extends TestCase {
    private $botAI;
    private $mockPdo;

    protected function setUp(): void {
        // Créer un mock de PDO pour les tests
        $this->mockPdo = $this->createMock(PDO::class);
        $this->botAI = new BotAI($this->mockPdo);
    }

    /**
     * Test de l'évaluation d'une main (Quinte Flush Royale)
     */
    public function testEvaluateRoyalFlush() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'Roi', 'suit' => '♠'],
            ['value' => 'Dame', 'suit' => '♠'],
            ['value' => 'Valet', 'suit' => '♠'],
            ['value' => '10', 'suit' => '♠']
        ];
        $result = $this->botAI->evaluateBestHand($cards);
        $this->assertEquals('Quinte Flush Royale', $result['name']);
        $this->assertEquals(1000, $result['score']);
    }

    /**
     * Test de l'évaluation d'une main (Carré)
     */
    public function testEvaluateFourOfAKind() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'As', 'suit' => '♥'],
            ['value' => 'As', 'suit' => '♦'],
            ['value' => 'As', 'suit' => '♣'],
            ['value' => 'Roi', 'suit' => '♠']
        ];
        $result = $this->botAI->evaluateBestHand($cards);
        $this->assertEquals('Carré', $result['name']);
        $this->assertEquals(800 + 14 * 10, $result['score']); // 800 + As (14) * 10
    }

    /**
     * Test de l'évaluation d'une main (Full)
     */
    public function testEvaluateFullHouse() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'As', 'suit' => '♥'],
            ['value' => 'As', 'suit' => '♦'],
            ['value' => 'Roi', 'suit' => '♣'],
            ['value' => 'Roi', 'suit' => '♠']
        ];
        $result = $this->botAI->evaluateBestHand($cards);
        $this->assertEquals('Full', $result['name']);
        $this->assertEquals(700 + 14 * 10, $result['score']); // 700 + As (14) * 10
    }

    /**
     * Test de l'évaluation d'une main (Couleur)
     */
    public function testEvaluateFlush() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => '10', 'suit' => '♠'],
            ['value' => '7', 'suit' => '♠'],
            ['value' => '6', 'suit' => '♠'],
            ['value' => '2', 'suit' => '♠']
        ];
        $result = $this->botAI->evaluateBestHand($cards);
        $this->assertEquals('Couleur', $result['name']);
        $this->assertEquals(600 + 14, $result['score']); // 600 + As (14)
    }

    /**
     * Test de l'évaluation d'une main (Suite)
     */
    public function testEvaluateStraight() {
        $cards = [
            ['value' => '5', 'suit' => '♠'],
            ['value' => '6', 'suit' => '♥'],
            ['value' => '7', 'suit' => '♦'],
            ['value' => '8', 'suit' => '♣'],
            ['value' => '9', 'suit' => '♠']
        ];
        $result = $this->botAI->evaluateBestHand($cards);
        $this->assertEquals('Suite', $result['name']);
        $this->assertEquals(500 + 9, $result['score']); // 500 + 9 (hauteur)
    }

    /**
     * Test de l'évaluation d'une main (Brelan)
     */
    public function testEvaluateThreeOfAKind() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'As', 'suit' => '♥'],
            ['value' => 'As', 'suit' => '♦'],
            ['value' => 'Roi', 'suit' => '♣'],
            ['value' => 'Dame', 'suit' => '♠']
        ];
        $result = $this->botAI->evaluateBestHand($cards);
        $this->assertEquals('Brelan', $result['name']);
        $this->assertEquals(400 + 14 * 10, $result['score']); // 400 + As (14) * 10
    }

    /**
     * Test de l'évaluation d'une main (Double Paire)
     */
    public function testEvaluateTwoPair() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'As', 'suit' => '♥'],
            ['value' => 'Roi', 'suit' => '♦'],
            ['value' => 'Roi', 'suit' => '♣'],
            ['value' => 'Dame', 'suit' => '♠']
        ];
        $result = $this->botAI->evaluateBestHand($cards);
        $this->assertEquals('Double Paire', $result['name']);
        $this->assertEquals(300 + 14 * 10, $result['score']); // 300 + As (14) * 10
    }

    /**
     * Test de l'évaluation d'une main (Paire)
     */
    public function testEvaluatePair() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'As', 'suit' => '♥'],
            ['value' => 'Roi', 'suit' => '♦'],
            ['value' => 'Dame', 'suit' => '♣'],
            ['value' => '10', 'suit' => '♠']
        ];
        $result = $this->botAI->evaluateBestHand($cards);
        $this->assertEquals('Paire', $result['name']);
        $this->assertEquals(200 + 14 * 10, $result['score']); // 200 + As (14) * 10
    }

    /**
     * Test de l'évaluation d'une main (Carte haute)
     */
    public function testEvaluateHighCard() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'Roi', 'suit' => '♥'],
            ['value' => 'Dame', 'suit' => '♦'],
            ['value' => '10', 'suit' => '♣'],
            ['value' => '7', 'suit' => '♠']
        ];
        $result = $this->botAI->evaluateBestHand($cards);
        $this->assertEquals('Carte haute', $result['name']);
        $this->assertEquals(14, $result['score']); // As (14)
    }

    /**
     * Test de l'évaluation pré-flop (Paire)
     */
    public function testEvaluatePreflopPair() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'As', 'suit' => '♥']
        ];
        $score = $this->botAI->evaluatePreflop($cards);
        $this->assertEquals(100 + 14 * 10, $score); // 100 + As (14) * 10
    }

    /**
     * Test de l'évaluation pré-flop (Carte haute)
     */
    public function testEvaluatePreflopHighCard() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'Roi', 'suit' => '♥']
        ];
        $score = $this->botAI->evaluatePreflop($cards);
        $this->assertEquals(14 * 8 + 13 * 2, $score); // As (14) * 8 + Roi (13) * 2
    }

    /**
     * Test de l'évaluation pré-flop (Assorted)
     */
    public function testEvaluatePreflopAssorted() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'Roi', 'suit' => '♠'] // Assorted (même couleur)
        ];
        $score = $this->botAI->evaluatePreflop($cards);
        $this->assertEquals(14 * 8 + 13 * 2 + 15, $score); // +15 pour la couleur
    }

    /**
     * Test de l'évaluation pré-flop (Connectors)
     */
    public function testEvaluatePreflopConnectors() {
        $cards = [
            ['value' => 'As', 'suit' => '♠'],
            ['value' => 'Roi', 'suit' => '♥'] // Connectors (As et Roi sont consécutifs)
        ];
        $score = $this->botAI->evaluatePreflop($cards);
        $this->assertEquals(14 * 8 + 13 * 2 + 10, $score); // +10 pour les connectors
    }
}
