# Tests unitaires pour le projet Poker

Ce dossier contient les tests unitaires pour le projet Poker. Les tests utilisent **PHPUnit**.

## Prérequis

- PHP 7.4 ou supérieur
- PHPUnit 9.0 ou supérieur
- Extensions PHP : `pdo`, `json`, `mbstring`

## Installation

### 1. Installer PHPUnit

#### Avec Composer (recommandé)
```bash
composer require --dev phpunit/phpunit
```

#### Sans Composer
Téléchargez le PHAR de PHPUnit depuis [https://phpunit.de/](https://phpunit.de/) et placez-le dans le projet.

### 2. Configurer PHPUnit

Le fichier `phpunit.xml` est déjà configuré pour exécuter les tests dans le dossier `tests/`.

## Exécution des tests

### Exécuter tous les tests
```bash
./vendor/bin/phpunit
```

ou
```bash
phpunit
```

### Exécuter un test spécifique
```bash
./vendor/bin/phpunit tests/BotAITest.php
```

### Exécuter avec couverture de code
```bash
./vendor/bin/phpunit --coverage-text
```

## Structure des tests

- `BotAITest.php` : Tests pour la classe `BotAI` (évaluation des mains, décision des bots)
- `ValidationTest.php` : Tests pour les fonctions de validation (`validateInt`, `validateString`)

## Ajouter un nouveau test

1. Créez un nouveau fichier dans le dossier `tests/` (ex: `PokerGameTest.php`)
2. Étendez la classe `PHPUnit\Framework\TestCase`
3. Ajoutez vos méthodes de test (préfixées par `test`)
4. Exécutez les tests pour vérifier

## Exemple de test

```php
<?php

use PHPUnit\Framework\TestCase;

class MonTest extends TestCase {
    public function testExemple() {
        $this->assertEquals(2, 1 + 1);
    }
}
```

## Bonnes pratiques

- Chaque test doit être **indépendant** des autres
- Utilisez des **noms descriptifs** pour les méthodes de test
- Testez **une seule chose** par méthode
- Utilisez des **assertions claires** (`assertEquals`, `assertTrue`, etc.)
- Mock les dépendances externes (ex: PDO) pour isoler les tests

## Couverture de code

Pour générer un rapport de couverture de code :
```bash
./vendor/bin/phpunit --coverage-html coverage
```

Cela générera un rapport dans le dossier `coverage/` que vous pouvez ouvrir dans un navigateur.
