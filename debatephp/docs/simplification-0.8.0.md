> Document amont conservé pour le portage. Pour le chargement intégré et les vérifications actuelles, voir [le README Wikidebates](../../README.md) et [le bilan de migration](../../docs/debatephp-upgrade.md). Les anciennes instructions `wfLoadExtension( 'DebatePHP' )` concernent le paquet autonome.

# Simplification du portage PHP — 0.8.0

## Travail effectué

Les conditions `if`, `while` et `not` des deux moteurs sont produites directement avec les opérateurs PHP de court-circuit. Les comparaisons n’ont plus besoin d’un appel à `Lua::truth()` ; les combinaisons booléennes n’ont plus besoin des variables temporaires du portage initial. La traduction agit sur l’arbre syntaxique Lua, sans remplacer du texte PHP par une expression régulière pour réécrire les conditions.

Exemple de transformation, pour deux comparaisons de chaînes :

```php
// Ancienne traduction :
if (Lua::truth((Lua::truth($v1 = ($title === '')) ? ($text === '') : $v1))) { /* … */ }

// Condition PHP équivalente :
if (($title === '') && ($text === '')) { /* … */ }
```

Les valeurs retournées par `and` et `or` hors des conditions gardent les règles Lua. Une chaîne vide, `"0"`, le nombre zéro et une table vide y sont vrais ; un remplacement général par les règles PHP serait incorrect. Les tests comparent également l’ordre et le nombre des effets de bord, pour préserver le court-circuit.

| Fichier | Occurrences de `Lua::truth()` en 0.7.0 | En 0.8.0 |
| --- | ---: | ---: |
| `ReferenceRenderer.php` | 534 | 291 |
| `ArgumentReferenceRenderer.php` | 990 | 486 |
| Total | 1 524 | 777 |

Ce sont **747 conversions explicites retirées du code**, soit environ 49 %. Ce décompte statique ne mesure pas le gain de temps d’une page.

Les autres changements :

- `NativeText.php` partage les ancres, infobulles, attributs HTML, séparations en deux parties et segments de chemin. Les transformations utilisent `strtr`, `str_replace`, `strpos` et `substr` quand elles sont littérales.
- Les trois formats de références d’Argument et son format de citations utilisent les lecteurs PHP communs. Les conditions d’acceptation des marqueurs, les champs manquants, les champs supplémentaires et les espaces ASCII restent conformes aux sources.
- Les quatre caches inutilisés `EXPAND_CACHE`, `TITLE_CACHE`, `I18N_DOMAIN` et `I18N_MSG` sont retirés d’Argument. Les services PHP qui ont remplacé leurs fonctions possèdent déjà leurs propres caches. Les caches encore actifs conservent le même format et les mêmes règles FIFO.
- La fermeture de normalisation des paramètres d’Argument capture uniquement sa table de paramètres. Elle ne capture plus toutes les variables du rendu ni son temporaire de travail par référence.
- Les générateurs réappliquent les fonctions natives après génération. Le remplacement d’une méthode respecte les méthodes publiques et privées voisines, et vérifie la présence du point d’entrée `render`.

Les classes PHP `Lua` et `Table` restent présentes pour les règles encore nécessaires : valeurs vraies/fausses, formatage, indices commençant à un et objets partagés. Ce travail poursuit la simplification ; il ne constitue pas une suppression complète de cette couche.

## Vérification

Environnement : MediaWiki 1.43.0, PHP 8.2, SQLite. Scribunto LuaStandalone fournit l’oracle des comparaisons. Les données et versions d’extensions sont identiques à celles documentées pour 0.7.0.

| Contrôle | Résultat |
| --- | --- |
| Argument sur le vrai parseur, deux modes, trois configurations | 516 comparaisons |
| Débat sur le vrai parseur, deux modes | 70 comparaisons |
| Rendu Argument à la frontière MediaWiki simulée | 174 comparaisons |
| Traductions, deux modes | 2 510 comparaisons |
| Texte, marqueurs et citations, cas limites et données aléatoires reproductibles | 3 138 comparaisons Lua/PHP |
| Conditions, valeurs retournées, ordre et nombre des effets de bord | 3 888 comparaisons Lua/PHP |
| Cache FIFO : bornes, ordre après mise à jour, valeurs fausses/vides, isolation | 12 assertions |
| Balises et descriptions, modes HTML et prétraitement | 52 comparaisons |
| Date, citations et Page Forms, modes HTML et prétraitement | 22 comparaisons |
| JSON-LD et classification des champs | 31 assertions |
| Compteurs et rendu sans Scribunto ni HTML Tags chargés | 42 assertions |

Tous passent. Les sorties visibles, valeurs JSON-LD et métadonnées restent contrôlées. Les dépendances aux anciennes pages Lua restent supprimées, comme en 0.7.0. L’activation des observations ne change pas les résultats du parseur. La reconstruction des deux moteurs est reproductible.

Les tests sémantiques de Débat utilisent des doubles pour capturer les appels ; ils ne vérifient pas les écritures différées du magasin SMW de production. Aucun pourcentage d’accélération globale n’est annoncé.

Pour reproduire les contrôles spécifiques, avec `lupa` et `luaparser` disponibles dans Python :

```sh
python3 tests/conditions_differential.py php
python3 tests/serialized_differential.py php
php tests/cache_fifo.php
```

Les scripts de test `conditions_native.php` et `serialized_native.php` utilisent la réflexion ou l’évaluation de code produit par les générateurs. Ils sont réservés aux tests hors ligne ; ils ne sont ni chargés ni appelés par l’extension pendant un rendu.

Les rapports de cette livraison sont dans `tests/results/v080-*`. La configuration des compteurs reste celle du README et de [native-0.7.0.md](native-0.7.0.md).

## Installation

Remplacer le dossier `extensions/DebatePHP/`, appliquer la procédure habituelle de rechargement OPcache et purger les pages. Les modèles et paramètres restent identiques. Aucun dispositif de vérification des sources Lua n’est réintroduit et aucun nouveau prétraitement n’est ajouté.
