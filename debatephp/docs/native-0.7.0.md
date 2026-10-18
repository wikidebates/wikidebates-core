> Document amont conservé pour le portage. Pour le chargement intégré et les vérifications actuelles, voir [le README Wikidebates](../../README.md) et [le bilan de migration](../../docs/debatephp-upgrade.md). Les anciennes instructions `wfLoadExtension( 'DebatePHP' )` concernent le paquet autonome.

# Version 0.7.0

## Changements

- Suppression des lectures et gardes des sources Lua des deux moteurs, ainsi que de leurs dépendances artificielles dans le cache MediaWiki. Aucune bascule automatique vers Lua. Scribunto retiré des dépendances déclarées de DebatePHP.
- Compteurs des prétraitements exécutés après cache, avec origine et durée. Les chemins de champs, descriptions, balises et paramètres Page Forms sont observés ; zéro reste explicitement visible. Les échecs sont comptés, sans journaliser leur contenu.
- Suppression de `spaceStructuralClosures()` : plus de parcours du JSON pour espacer les accolades structurelles. La conversion des tables en valeurs JSON, la validation et la protection de la balise `script` restent nécessaires et sont conservées.
- Partage des fonctions de découpage natives dans les deux modes, suppression des doublons des classes optimisées et centralisation du stockage du cache d’expansion. Les différences historiques entre les séparateurs acceptés par Argument et Débat sont conservées.
- Les générateurs réappliquent les simplifications après traduction mécanique : une régénération n’annule pas ces changements.

## Contrôles de cette livraison

Environnement local : MediaWiki 1.43.0, PHP 8.2, SQLite, Scribunto LuaStandalone pour les comparaisons, Page Forms 5.8.1, ShortDescription 4.0.2, HTML Tags 0.3 facultatif, Variables/VariablesLua facultatifs.

| Contrôle | Résultat |
| --- | --- |
| Argument, véritable parseur | 86 cas × 2 modes × 3 configurations = 516 comparaisons |
| Débat, véritable parseur | 35 cas × 2 modes = 70 comparaisons |
| Argument, frontière MediaWiki simulée | 174 comparaisons Lua/PHP |
| Traductions FR/EN, deux modes | 2 510 comparaisons |
| Fonctions de découpage, cas limites et données aléatoires reproductibles | 1 740 comparaisons aux fonctions Lua d’origine |
| Balises et SHORTDESC | 52 comparaisons, modes HTML et prétraitement |
| Date, citations et Page Forms | 22 comparaisons, modes HTML et prétraitement |
| JSON-LD et classification des champs | 31 assertions |
| Compteurs et autonomie, sans Scribunto ni HTML Tags chargés | 42 assertions |

Les sorties visibles, catégories, propriétés, liens, drapeaux et durées de cache restent comparés. Les seules dépendances retirées du résultat attendu sont les sources Lua devenues inutiles ; les dépendances des modèles réellement utilisés restent vérifiées. Le contrôle JSON-LD tient compte de la correction d’échappement déjà introduite en 0.6.0, sans ignorer les autres différences de valeurs. Aucune nouvelle différence de rendu détectée dans ce corpus.

Les appels sémantiques de Débat sont capturés par des doubles. Les contrôles ne constituent pas une validation des écritures différées dans le magasin SMW de production, ni une mesure du gain sur le serveur du site. Aucun pourcentage d’accélération globale n’est déduit des tests locaux.

## Reproduire les nouveaux contrôles

Tests PHP sans installation MediaWiki :

```sh
php tests/argument_adapter.php
php tests/i18n_bundle.php
php tests/telemetry.php
```

Découpages contre Lua, avec Python et `lupa` :

```sh
python3 tests/serialized_differential.py php
```

Sur un wiki MediaWiki 1.43 avec DebatePHP chargé :

```sh
php tests/integration/preprocessing.php --wiki-root=/srv/mediawiki
php tests/integration/profile_render.php --wiki-root=/srv/mediawiki --module=Argument --corpus=tests/fixtures/argument-cases.json --output=/tmp/argument-profils.json
```

Le test des compteurs ne modifie pas les pages. Pour vérifier l’autonomie, utiliser une configuration de test sans charger Scribunto ni les extensions qui l’exigent. La variable d’environnement `DEBATEPHP_WITHOUT_SCRIBUNTO=1` permet au test de vérifier cette absence ; elle ne désactive pas à elle seule une extension chargée dans votre `LocalSettings.php`.

Les autres comparaisons contre Lua nécessitent un wiki de référence et les pages sources, uniquement pour fournir leur oracle. Utiliser `tests/integration/check_direct_jsonld.py` sur les résultats du parseur pour tenir compte du JSON-LD direct et du retrait des dépendances Lua de cette version.

## Lire les mesures

Dans `LocalSettings.php` :

```php
$wgDebatePHPProfiling = true;
$wgDebugLogGroups['DebatePHP'] = '/var/log/mediawiki/debatephp.log';
```

Exemple vérifié : deux demandes du même champ dynamique donnent `preprocess_executed: 1` et `preprocess_cache_hits: 1`. Si son expansion rend le cadre volatil, le cache n’est pas utilisé : le compteur donne 2 exécutions. Deux demandes d’un champ littéral donnent zéro exécution et un `native_cache_hits`.

Sur le corpus local, les 86 cas Argument donnent zéro appel supplémentaire dans les deux modes (172 profils). Les 35 cas Débat produisent 72 profils, car une page contient deux invocations : 58 à zéro appel, 12 à un appel et 2 à quatre appels. Pour « Dieu existe-t-il ? », le seul appel supplémentaire est celui de l’introduction. Ces résultats décrivent le corpus et les extensions locales, pas toutes les pages du site. Les paramètres ont déjà subi leur expansion initiale par MediaWiki.

Les motifs `debate.*` permettent d’isoler les 13 emplacements de champs ; `argument.quotes`, `shortdesc.fallback`, `htmltag.fallback`, `htmltag.argument`, `htmltag.body`, `htmltag.attribute` et `autoedit.parameter` couvrent les autres chemins de l’extension. Le total compte les appels explicitement lancés par DebatePHP, pas chaque opération récursive interne au parseur ou aux autres extensions. Les durées imbriquées ne s’additionnent pas.

Le script de profils accepte soit `{title,args}`, soit `{title,text}`. Pour le second format, il remplace l’appel au moteur dans les modèles uniquement en mémoire. Si une page contient plusieurs invocations, chacune reçoit son propre profil.

Les résultats des caches PHP sont limités à l’invocation. La variable statique d’observation est restaurée par `finally`, y compris après exception et lors d’appels imbriqués ; elle ne conserve ni compteur ni texte d’une invocation à la suivante.
