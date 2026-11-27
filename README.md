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
n’a pas de manifeste indépendant. Les trois moteurs Argument, Debate et
ArgumentMap sont exclusivement natifs. Aucun interpréteur ni adaptateur
d’exécution Lua n’est requis. Sur les wikis historiques FR/EN, Wikidebates
conserve la lecture des révisions archivées sans exécuter leur source.
Voir [le gestionnaire de contenu historique](docs/lua-archive.md).

| Fonction tierce | Extensions nécessaires à cette fonction |
| --- | --- |
| Données et requêtes sémantiques | Semantic MediaWiki (API PHP native) |
| Formulaires et boutons `#autoedit` | Page Forms |
| Descriptions | ShortDescription |
| Métadonnées SEO | WikiSEO |
| Variables partagées | Variables |

Le chargement unique ne remplace pas les extensions fonctionnelles du site.
Les lectures sémantiques gardent leurs contrôles de validité et leur chemin
PHP général lorsque la lecture directe n’est pas certifiée.

## Configuration

| Paramètre historique | Défaut | Rôle |
| --- | --- | --- |
| `$wgDebatePHPProfiling` | `false` | Durées et compteurs, canal privé `DebatePHP` |
| `$wgDebatePHPDiagnostics` | `false` | Erreurs d’intégration sans leurs valeurs sensibles |

Le moteur PHP construit directement les liens de navigation et les scripts
JSON-LD non exécutables. Wikidebates ne déclare plus le hook `htmltag` et
n'hérite plus de `$wgHTMLTagsAttributes`. Les anciens usages de cette balise
présents dans les pages wiki doivent être vérifiés et migrés avant de désactiver
HTML Tags sur le serveur (voir `docs/html-tags-retirement.md`).

Argument et Debate utilisent chacun un moteur PHP canonique, avec mémorisation
des traductions limitée au rendu. Le paramètre historique `$wgDebatePHPMode`
n’est plus utilisé et peut être retiré de la configuration locale après déploiement.
Le JSON-LD conserve ses valeurs et protège les
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

La phase d'optimisation native et de fiabilisation SQL de la PR #3 est documentée
dans [son rapport dédié](docs/native-php-optimizations.md), avec les comparaisons
avant/après sous PHP 8.3 et les variantes SQLite/MariaDB isolées.
La [suite en six points](docs/native-php-followup.md) détaille les parcours
sans copie, le FIFO natif, la préparation commune et la réduction des captures.
Les [cinq optimisations complémentaires](docs/native-php-further-optimizations.md)
traitent les clés SMW, les fragments HTML, le JSON-LD, les records natifs et le
budget du cache CSV, avec leurs mesures et compromis.
La [suite sur les lectures natives](docs/native-php-read-optimizations.md)
réduit les replis SMW prouvés inutiles et les allocations HTML, CSV et JSON-LD,
avec les comparaisons fonctionnelles et les mesures par scénario.
La [sélection mesurée des optimisations restantes](docs/native-php-measured-optimizations.md)
présente les parcours fusionnés, les titres natifs et les essais de cache,
avec les compromis de temps et de mémoire. Le [plafond des références a été retiré](docs/native-php-reference-cache.md) ; le cache existant est conservé.

Le PHP de `debatephp/src/` est désormais la source de référence des deux moteurs.
Les générateurs historiques et leurs tests propres sont archivés dans Git ;
voir [la méthode de migration native](debatephp/docs/native-php-migration-baseline.md).
Le contrôle PHP natif utilise un commit de référence fixe et ne régénère aucun fichier.
Les oracles Lua hors ligne conservés nécessitent Python et `lupa` :

```sh
python3 debatephp/tests/regenerate.py
python3 debatephp/tests/serialized_differential.py php
python3 debatephp/tests/differential_i18n.py php
python3 debatephp/tests/argument_differential.py php
```

La comparaison historique, les mesures et leurs commandes de reproduction sont
détaillées dans [le bilan de migration](docs/debatephp-upgrade.md). Les doubles
sémantiques et outils de test ne sont jamais chargés par le manifeste.

## Traductions, outils et provenance

Les textes affichés sont centralisés dans `i18n/en.json`, `i18n/fr.json` et
documentés dans `i18n/qqq.json`. PHP et JavaScript utilisent les messages
MediaWiki. Les identifiants du wiki sont séparés dans
`resources/wiki-names.json` et restent indépendants de la langue du visiteur.
`debatephp/resources/I18nData.php` conserve l’ancien format pour les outils et
tests ; les snapshots Lua restent des oracles hors ligne.

Voir [l’organisation et le bilan de migration i18n](docs/i18n-harmonization.md).

```sh
php tools/buildI18n.php --check
python3 tests/checkI18n.py
node tests/i18n.js
MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawikiI18n.php
```

L’importateur hors ligne des anciens dictionnaires est conservé uniquement pour les tests et la reproduction historique. Le texte courant se maintient directement dans les catalogues JSON ; l’ancien outil de synchronisation nécessitant Scribunto a été retiré.

```sh
php tools/compileI18n.php debatephp/resources/WD-I18N-Data.snapshot.lua debatephp/resources/WD-I18N.reference.lua > /tmp/I18nData.php
```

Le compilateur amont avec remplacement atomique reste sous `debatephp/tools/`.
S’il vise le point d’entrée actif, il met à jour les JSON sans l’écraser.
Les générateurs des moteurs, fixtures et références utiles remplacent les
dossiers provisoires `imports/`.

Voir les [empreintes recalculées](debatephp/UPSTREAM.json), la
[notice conservée](debatephp/NOTICE) et la [licence GPLv3](debatephp/LICENSE).
La distribution combinée est GPL-3.0-or-later ; les sources Wikidebates
préexistantes conservent leur licence MIT. Les snapshots conservent leurs
licences et attributions. Les révisions historiques sont maintenant conservées
dans `debatephp/tests/fixtures/revisions.json`.

## Optimisations de lecture et des formulaires

Le partage des requêtes, le cache des modifications récentes et les commandes
de mise à jour serveur sont décrits dans
[la note sur les optimisations](docs/optimisations-lecture-formulaires.md).

L'[étude des caches de lecture](docs/reading-cache-study.md) décrit les clés
partagées entre réponses et extraits, les mesures locales facultatives et la
comparaison avant/après dans Chromium. Les extraits restent conservés sans
limite ; les plafonds éventuels sont seulement simulés hors du parcours de lecture.

L'[étude des transferts d'arguments](docs/argument-transfer-study.md) compare les
volumes gzip sur les deux wikis et examine les chemins du parser cache MediaWiki.
Elle conserve la requête de lecture actuelle ; aucune API d'extraits n'est activée.

L'[étude du cache fichier Minerva](docs/minerva-file-cache-study.md) fournit un
prototype isolé, ses mesures avec parser cache chaud et les tests d'invalidation.
Ce prototype n'est pas chargé par l'extension et n'active aucun cache sur le site.

L'extension autonome [MinervaFileCache](extensions/MinervaFileCache/README.md)
fournit l'intégration installable séparément pour MediaWiki 1.43 et MobileFrontend.
Son activation couvre toutes les vues mobiles éligibles, avec un stockage distinct
du cache desktop ; la notice donne les réglages et la procédure de désactivation.

Le [cache d'extraits du mode lecture](docs/reading-fragment-cache.md) est intégré
à Wikidebates et actif par défaut. Il prépare le HTML utile, partage sa réponse
entre ordinateur et téléphone, navigation, préchargement et aperçus. En cas
d’absence, il réutilise d’abord les fichiers Vector, puis Minerva, avant le rendu
MediaWiki. La notice décrit les durées, les invalidations et la commande quotidienne.
