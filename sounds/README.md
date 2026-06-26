# Sons pour le jeu de poker

Ce dossier contient les sons utilisés dans le jeu de poker. Vous pouvez ajouter les fichiers audio suivants :

- `deal.mp3` : Son joué quand les cartes sont distribuées
- `bet.mp3` : Son joué quand un joueur mise
- `call.mp3` : Son joué quand un joueur suit
- `raise.mp3` : Son joué quand un joueur relance
- `fold.mp3` : Son joué quand un joueur fold
- `win.mp3` : Son joué quand un joueur gagne
- `chips.mp3` : Son joué quand des chips sont ajoutés au pot
- `error.mp3` : Son joué en cas d'erreur

## Format recommandé
- Format : MP3 ou WAV
- Durée : 0.5 à 2 secondes
- Taille : < 100 Ko par fichier

## Exemple de sons
Vous pouvez trouver des sons gratuits sur :
- https://freesound.org/
- https://www.zapsplat.com/
- https://mixkit.co/free-sound-effects/

## Utilisation dans le code
Les sons sont joués via la fonction `playSound()` dans `poker_game.php` :

```javascript
playSound('deal'); // Joue le son de distribution
playSound('win');  // Joue le son de victoire
```
