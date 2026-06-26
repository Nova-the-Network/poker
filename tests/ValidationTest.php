<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour les fonctions de validation
 */
class ValidationTest extends TestCase {
    
    /**
     * Test de la validation d'un entier dans une plage
     */
    public function testValidateInt() {
        // Inclure le fichier pour accéder aux fonctions
        require_once __DIR__ . '/../poker_api.php';
        
        // Test avec une valeur valide
        $this->assertEquals(50, validateInt(50, 10, 100, 0));
        
        // Test avec une valeur trop basse
        $this->assertEquals(10, validateInt(5, 10, 100, 0));
        
        // Test avec une valeur trop haute
        $this->assertEquals(100, validateInt(150, 10, 100, 0));
        
        // Test avec une valeur non numérique
        $this->assertEquals(0, validateInt('abc', 10, 100, 0));
        
        // Test avec null
        $this->assertEquals(0, validateInt(null, 10, 100, 0));
    }
    
    /**
     * Test de la validation d'une chaîne de caractères
     */
    public function testValidateString() {
        // Inclure le fichier pour accéder aux fonctions
        require_once __DIR__ . '/../poker_api.php';
        
        // Test avec une chaîne valide
        $this->assertEquals('test', validateString('test', 1, 255));
        
        // Test avec une chaîne trop courte
        $this->assertEquals('', validateString('', 1, 255));
        
        // Test avec une chaîne trop longue
        $longString = str_repeat('a', 300);
        $this->assertEquals('', validateString($longString, 1, 255));
        
        // Test avec une chaîne valide de longueur minimale
        $this->assertEquals('a', validateString('a', 1, 255));
        
        // Test avec une chaîne valide de longueur maximale
        $maxString = str_repeat('a', 255);
        $this->assertEquals($maxString, validateString($maxString, 1, 255));
        
        // Test avec null
        $this->assertEquals('', validateString(null, 1, 255));
    }
}
