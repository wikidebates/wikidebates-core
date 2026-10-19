# Wikidebates — DebatePHP 0.8.0

Wikidebates intègre les moteurs natifs Débat et Argument de DebatePHP 0.8.0,
tout en conservant les interfaces de lecture, contributions récentes, liens
parentaux et hooks d’authentification existants.

La cible est **MediaWiki 1.43.x**, avec PHP 8.1 ou supérieur. Le manifeste reprend
la borne supérieure du paquet 0.8 ; MediaWiki 1.44 n’est pas annoncé compatible
sans validation de ses API internes.

## Chargement et dépendances

Un seul chargement enregistre `#debate:render`, `#argument:render` et
`#recentdebatechanges` :

```php
wfLoadExtension( 'Wikidebates' );
```

Ne pas charger en plus le paquet autonome DebatePHP. Le composant `debatephp/`
n’a pas de manifeste indépendant. Scribunto et HTML Tags ne sont plus requis
pour le rendu PHP. Les quatre anciennes pages de modules Lua ne sont plus lues
et ne créent plus de dépendance de cache. Les modèles utilisant encore `#invoke`
ont toujours besoin de Scribunto ; l’extension ne réécrit aucune page du wiki.

| Fonction tierce | Extensions nécessaires à cette fonction |
| --- | --- |
| Données et requêtes sémantiques | Semantic MediaWiki et SemanticScribunto, via sa `LibraryFactory` |
| Formulaires et boutons `#autoedit` | Page Forms |
| Descriptions | ShortDescription |
| Métadonnées SEO | WikiSEO |
| Variables partagées | Variables et VariablesLua |
| Autres modèles Lua du site | Scribunto |

SemanticScribunto et VariablesLua peuvent eux-mêmes requérir Scribunto.
Le chargement unique concerne Wikidebates/DebatePHP ; il ne remplace pas les
extensions tierces du site. Leur absence conserve les mécanismes de repli du
paquet et peut retirer la fonction tierce correspondante.

## Configuration

| Paramètre historique | Défaut | Rôle |
| --- | --- | --- |
| `$wgDebatePHPMode` | `reference` | `reference` ou `optimized`, pour les deux moteurs |
| `$wgDebatePHPProfiling` | `false` | Durées et compteurs, canal privé `DebatePHP` |
| `$wgDebatePHPDiagnostics` | `false` | Erreurs d’intégration sans leurs valeurs sensibles |
| `$wgDebatePHPHTMLAttributes` | `null` | Politique d’attributs des balises natives |

Une politique HTML nulle hérite de `$wgHTMLTagsAttributes` lorsqu’il existe,
sinon des règles embarquées pour `a` et `script`. Un hook `htmltag` tiers
déjà enregistré est conservé.

Les deux modes exécutent du PHP ; `optimized` active notamment la mémorisation
des traductions. Les lectures de marqueurs sont désormais partagées. Une valeur
de mode inconnue est refusée. Le JSON-LD conserve ses valeurs et protège les
fermetures de script, sans réinterpréter ses chaînes comme du wiki.

Le profilage fournit `preprocess_executed`, `preprocess_cache_hits`,
`native_cache_hits` et `literal_fields`, ainsi que les durées par origine.
Il compte les entrées supplémentaires déclenchées par DebatePHP après cache,
pas le développement initial des paramètres par MediaWiki. Un zéro est explicite.
Les durées imbriquées ne doivent pas être additionnées.

## Tests

Sans MediaWiki, y compris les tests amont isolés dans des processus séparés :

```sh
php tests/run.php
```

Avec un checkout MediaWiki 1.43 et son `vendor/` correspondant :

```sh
MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawiki.php
WD_TEST_EXTENSIONS=PageForms,ShortDescription,WikiSEO,Variables MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawiki.php
WD_TEST_EXTENSIONS=Scribunto,PageForms,ShortDescription,WikiSEO,Variables MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawiki.php
```

Le banc utilise `MW_CONFIG_CALLBACK` et une base SQLite temporaire supprimée en
fin d’exécution. Il ne lit ni n’écrit `LocalSettings.php` et n’utilise aucune base
existante. PDO SQLite et les extensions PHP normales de MediaWiki sont requis.
Sans `WD_TEST_EXTENSIONS`, il vérifie que seule Wikidebates est chargée.

Avec les vraies extensions SMW, SemanticScribunto et VariablesLua installées
dans ce laboratoire, la validation des écritures différées, requêtes, variables
et rendus Lua/PHP se lance avec :

```sh
MW_INSTALL_PATH=/path/to/mediawiki-1.43 WD_FULLSTACK_AUTOLOAD=/path/to/dependencies/vendor/autoload.php python3 tests/fullstack.py --php php --output /tmp/wikidebates-fullstack.json
```

Versions testées, installation isolée reproductible, résultats, mesures et
contrôles restant à effectuer figurent dans le
[rapport de validation avec les extensions réelles](docs/debatephp-fullstack-validation.md).
L'autoload supplémentaire est optionnel si les dépendances SMW sont déjà
fournies par le `vendor/` du laboratoire.

Les oracles hors ligne nécessitent Python, `lupa` et `luaparser` :

```sh
python3 debatephp/tests/conditions_differential.py php
python3 debatephp/tests/serialized_differential.py php
python3 debatephp/tests/differential_i18n.py php
python3 debatephp/tests/argument_differential.py php
```

La comparaison historique, les mesures et leurs commandes de reproduction sont
détaillées dans [le bilan de migration](docs/debatephp-upgrade.md). Les doubles
sémantiques et outils de test ne sont jamais chargés par le manifeste.

## Traductions, outils et provenance

Les traductions installées dans `debatephp/resources/I18nData.php` font autorité.
Modifier les anciennes pages Lua n’affecte plus le rendu PHP. Les snapshots Lua
sont conservés pour les tests et les outils hors ligne.

L’interface de compilation de la première intégration est conservée :

```sh
php tools/compileI18n.php debatephp/resources/WD-I18N-Data.snapshot.lua debatephp/resources/WD-I18N.reference.lua > /tmp/I18nData.php
```

Le compilateur amont avec remplacement atomique reste sous `debatephp/tools/`.
Les générateurs des moteurs, fixtures et références utiles remplacent les
dossiers provisoires `imports/`.

Voir les [empreintes recalculées](debatephp/UPSTREAM.json), la
[notice conservée](debatephp/NOTICE) et la [licence GPLv3](debatephp/LICENSE).
La distribution combinée est GPL-3.0-or-later ; les sources Wikidebates
préexistantes conservent leur licence MIT. Les snapshots conservent leurs
licences et attributions. Les révisions historiques sont maintenant conservées
dans `debatephp/tests/fixtures/revisions.json`.
