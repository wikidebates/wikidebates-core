# Cinq chemins natifs de lecture — PR #3

Comparaison avec `7681ca6dd775bc64ccc586b46ec09e804f4a2931`.
Cette phase prolonge les rapports précédents sans leur réattribuer leurs gains.

## Changements retenus

1. **Replis SMW des parents.** Après une recherche groupée vide, les deux
	recherches unitaires ne sont plus exécutées si `EmptyParentQuery` vérifie
	la forme interne exacte, le mode instances, la limite 200, l'offset zéro,
	l'absence de résultats supplémentaires, une réponse hors cache SMW et une disjonction de deux
	`SomeProperty` portant sur la même page avec un comparateur d'égalité.
	Une lecture vide ne suffit pas. Les erreurs, les descriptions inconnues,
	les types non page, les résultats incomplets et les réponses du cache SMW conservent les replis.
	SMW 6.0.1 émet les deux avertissements JSON `smw_unexpectedpart` pour les
	parenthèses extérieures de cette syntaxe historique. Seule cette paire
	exacte est acceptée, après les autres contrôles ; aucun autre avertissement
	n'est accepté. Le texte des requêtes et leurs paramètres restent inchangés.
	Les résultats non vides gardent leur classification et leurs replis historiques.
2. **Résultats SMW natifs.** L'interface optionnelle `NativeSemanticContext`
	fournit un `SemanticReadResult` contenant des tableaux PHP en lecture seule.
	Le traitement des valeurs reste celui de `LuaAskResultProcessor` : types,
	libellés, valeurs multiples, texte wiki, nombres et booléens sont conservés.
	La conversion garde le réindexage historique des clés numériques à partir
	de 1. Un callback pur partagé utilise `array_map`, sans créer une enveloppe
	`Table` à chaque niveau. Une première boucle PHP plus lente a été écartée.
	Le parcours conserve l'arrêt au premier trou ou `null`, même en présence
	de champs nommés. Le cache natif FIFO est borné à 250 résultats et remis
	à zéro à chaque rendu ; les erreurs SQL ne deviennent jamais des résultats vides.
	L'API existante `smwAsk()` et les contextes tiers restent compatibles.
	Le chemin d'initialisation historique conserve son accès `Table`.
3. **HTML local.** Les accumulateurs des mots-clés et rubriques des deux
	moteurs, des débats connexes et des avertissements de titre d'Argument
	utilisent des tableaux PHP, `count()` et `implode()`. Les catégories et
	structures sémantiques partagées restent des objets compatibles. L'ordre
	des traductions, appels au parseur et effets est inchangé.
4. **CSV en lecture seule.** `RenderData::csvValues()` partage le cache
	borné existant avec `csv()`. Les boucles directes et certains consommateurs
	JSON-LD parcourent les tableaux PHP sans objet ni générateur intermédiaire.
	`csv()` renvoie toujours une nouvelle `Table` ; la copie sur écriture
	isole les mutations de chaque vue. Les budgets, admissions, compteurs,
	séparateurs et règles de découpage restent identiques.
5. **JSON-LD interne.** Les nœuds associatifs du graphe sont construits en
	tableaux PHP pendant l'opération native. Les enveloppes nécessaires aux
	listes et aux contrats historiques restent disponibles. Le mode temporaire
	est restauré dans un `finally`, y compris après une exception. L'interface
	optionnelle `NativeJsonContext` encode le graphe et insère le script en une
	opération, sans décoder immédiatement le JSON que l'encodeur vient de produire.
	`jsonLd(string)` continue de valider les appels externes. Les deux chemins
	partagent l'échappement de `<`, `>` et `&`, la protection de la balise script
	et l'insertion MediaWiki. `TextEscaper::jsonValue()` garde son contrat externe ;
	`JsonData` traite explicitement les arbres internes mêlant tableaux et `Table`.

`nativeReadPass.py` reproduit les deux moteurs à partir des snapshots Lua
inchangés. Aucune nouvelle dépendance, aucun changement de configuration ni
de schéma. Les deux modes bénéficient des chemins équivalents ; `reference`
reste le défaut et un seul `wfLoadExtension( 'Wikidebates' )` suffit.

## Validation

MediaWiki **1.43.11**, PHP **8.3.35**, véritables **SMW 6.0.1**, **SemanticScribunto 2.3.3**, **VariablesLua 1.7.0**, Variables **2.6.0-beta** et Scribunto REL1_43. PageForms **5.8.1**, ShortDescription **4.0.2**, WikiSEO **2.7.0** sont chargés. Conteneurs sans réseau, bases synthétiques jetables ; MariaDB uniquement sur socket du laboratoire.

| Exécution finale | Cas de rendu | Lua/PHP | Natif avant/après | Variantes persistées | Profils comparés |
| --- | ---: | ---: | ---: | ---: | ---: |
| PHP 8.3 / SQLite 3.46.1 | 140 | 280 | 280 | 50 | 16 |
| PHP 8.3 / MariaDB 10.11.19 | 140 | 280 | 280 | 50 | 16 |

HTML complet, JSON-LD, annotations SMW typées, variables, catégories, liens, modules et dépendances/paramètres de cache sont comparés. Les corrections historiques d’encodage face à Lua restent normalisées par le comparateur indépendant ; aucune nouvelle différence n’est acceptée. Les écritures différées, jobs, mises à jour/suppressions, partage VariablesLua/PHP, modifications de modèles et hooks de parenté passent. Les files sont vides et les bases supprimées en fin de test.

Les nouvelles sondes utilisent les vraies classes SMW : cinq comparaisons de résultats natifs/compatibles, booléen faux, zéro, décimal, valeurs multiples, pages formatées et dates. Elles vérifient une recherche vide puis l’ajout et la suppression d’une relation persistée. Les gardes couvrent erreurs supplémentaires, avertissements malformés, pagination, résultat incomplet ou issu du cache SMW, cibles différentes et ordre inattendu des paramètres.

Autres vérifications réussies :

- **8 484** nouvelles assertions : index sémantiques, ordre/octet JSON, mutations, interfaces optionnelles, replis certifiés et caches ; **8 047** assertions natives précédentes et **36 438** assertions de collections conservées.
- Suites autonomes PHP **8.3 et 8.2** : respectivement **80 et 70** contrôles SQL (mysqli absent en 8.2), **1 652** équivalences de références, **32** assertions de réutilisation, adaptateurs, FIFO, profilage et i18n. Le banc complet de cette phase cible PHP 8.3.
- Oracles Lua : **3 888** conditions, **3 301** opérations de texte, **174** rendus Argument avec effets et **2 510** traductions.
- Régénération exacte des deux moteurs, **10** cas lexicaux, lint PHP 8.3, tabulations et empreintes de provenance vérifiés.
- Chargement unique de Wikidebates, **13** fixtures dans les deux modes et **18** opérations natives avec/sans extensions optionnelles ; les deux chemins JSON protègent le script, le chemin externe refuse le JSON invalide.
- Surcharges MediaWiki/ResourceLoader en six langues pour les contenus EN/FR ; **390** messages documentés, **623** entrées historiques et **12** combinaisons JavaScript. Aucun catalogue modifié.


## Mesures ciblées

Le banc charge réellement les sources du commit de comparaison dans un
espace de noms séparé. La sonde de conversion SMW fige l'ancien callback
identifié dans ces sources ; elle mesure uniquement la transformation des
valeurs déjà formatées, sans base. La sonde JSON utilise l'encodeur PHP avec
les mêmes options des deux côtés et ne mesure pas les services MediaWiki.
Les deux sondes de rendu complet utilisent des contextes simulés. Ces
mesures ne sont pas des temps HTTP ni des garanties de rapidité pour le wiki.

31 échantillons mélangés, 5 itérations par mesure, PHP 8.3 CLI ; égalité exacte
vérifiée avant chaque scénario. Les pics supplémentaires sont mesurés après
préparation des entrées et échauffement ; ce ne sont pas les consommations
mémoire totales d'une page.

| Sonde | Avant, ms | Après, ms | Variation | Pic supplémentaire avant → après, Kio |
| --- | ---: | ---: | ---: | ---: |
| Conversion de 100 résultats SMW | 0,07707 | 0,06812 | -11,62 % | 74,59 → 63,59 |
| CSV : 160 lectures avec parcours | 0,35485 | 0,19162 | -46,00 % | 132,14 → 131,77 |
| Construction/encodage JSON : 40 citations | 0,10069 | 0,03765 | -62,61 % | 88,00 → 44,06 |
| HTML : 40 mots-clés | 0,16941 | 0,16187 | -4,45 % | 46,56 → 46,45 |
| HTML : 40 débats connexes | 0,01407 | 0,01168 | -16,98 % | 14,14 → 14,09 |
| HTML : 40 rubriques | 0,00864 | 0,00326 | -62,27 % | 4,29 → 4,23 |
| Rendu Debate simulé | 0,70892 | 0,59627 | -15,89 % | 109,24 → 104,88 |
| Rendu Argument simulé | 0,89415 | 0,73600 | -17,69 % | 97,38 → 93,01 |

## Parsing complet avec les vraies extensions

Une série finale de **30 échantillons par scénario et variante** sous SQLite, en ordre mélangé, après échauffement. Profilage désactivé pour les mesures principales ; bloc profilé séparé. Médianes en millisecondes.

| Scénario | Reference avant → après | Variation | Optimized avant → après | Variation |
| --- | ---: | ---: | ---: | ---: |
| rich native argument en | 29,126 → 26,747 | -8,17 % | 29,720 → 25,141 | -15,40 % |
| rich native debate en | 52,138 → 52,419 | 0,54 % | 51,572 → 52,701 | 2,19 % |
| rich native argument fr | 31,906 → 26,270 | -17,66 % | 29,864 → 27,149 | -9,09 % |
| rich native debate fr | 55,745 → 56,923 | 2,11 % | 55,316 → 56,481 | 2,11 % |
| parents en | 21,372 → 21,838 | 2,18 % | 22,310 → 21,259 | -4,71 % |
| parents fr | 22,595 → 22,259 | -1,49 % | 23,234 → 22,583 | -2,80 % |
| initialization | 20,437 → 16,474 | -19,39 % | 20,337 → 16,176 | -20,46 % |
| cards | 20,818 → 20,612 | -0,99 % | 20,374 → 21,085 | 3,49 % |

**Aucun gain uniforme sur toutes les pages.** Les variations de médiane vont de -20,46 % à +3,49 %. Les pages sans parent et l’initialisation bénéficient de la suppression des replis ; plusieurs autres scénarios restent proches ou légèrement moins favorables. Les P95 sont irréguliers : Argument riche EN/reference passe par exemple de 37,623 à 88,312 ms. Aucun lien causal avec le code, le GC ou le magasin SMW n’est établi pour ces pointes. Tous les échantillons et P95 sont conservés dans le JSON.

Les cinq échantillons MariaDB par variante servent surtout à la validation fonctionnelle ; leur performance reste exploratoire. Les séries de développement ne sont pas agrégées dans ces tableaux.

Lectures logiques SMW en `reference` ; les mêmes réductions sont vérifiées en `optimized` :

| Scénario | Avant | Après | Replis évités |
| --- | ---: | ---: | ---: |
| rich native argument en | 7 | 3 | 4 |
| rich native debate en | 0 | 0 | 0 |
| rich native argument fr | 7 | 3 | 4 |
| rich native debate fr | 0 | 0 | 0 |
| parents en | 8 | 8 | 0 |
| parents fr | 8 | 8 | 0 |
| initialization | 8 | 4 | 4 |
| cards | 9 | 9 | 0 |

Le bloc optionnel `semantic_fallbacks_skipped` ne contient que des origines filtrées et des nombres de replis évités. Les profils exigent exactement cette différence de lectures, phase par phase ; les écritures, échecs, traductions, prétraitements et succès des caches restent identiques. Aucun regroupement général des requêtes n’est imposé : limites, ordre et replis non vides sont conservés.

## Risques et limites

- La preuve d'absence de parents est volontairement étroite. Une réponse du
	cache interne SMW conserve les replis potentiellement plus frais. Un autre format
	d'avertissements ou une autre structure SMW conserve les requêtes de repli.
	Elle suppose, comme les caches existants du rendu, un état sémantique stable
	pendant la sélection des parents ; aucun cache interutilisateur n'est ajouté.
- Les nombres de lectures sont des appels logiques à l'adaptateur SMW,
	pas des nombres de requêtes SQL. Une lecture peut déclencher plusieurs SQL
	ou profiter des caches internes de SMW.
- Les accès directs aux `DataItem` et les écritures directes dans le Store ne
	sont pas introduits. Le formateur SemanticScribunto, le collecteur du parseur,
	les écritures différées et la compatibilité VariablesLua restent nécessaires.
- La migration `Table`/`Lua::` reste progressive. Les identités partagées,
	annotations, listes compatibles et interfaces tierces sont préservées.
- Les fixtures sont synthétiques. Aucune copie ni base de production n'est
	utilisée. Pour une recette représentative : exports anonymisés de pages
	et modèles, valeurs sémantiques attendues et versions/configurations non
	secrètes des extensions, à importer dans une nouvelle base de test.
- Le laboratoire mesure du parsing CLI avec services chauds et compare
	rendus et métadonnées ; il ne mesure pas PHP-FPM, OPcache sous charge,
	les accès HTTP, les caches partagés ou plusieurs visiteurs simultanés.
	Les petits écarts et les P95 ne démontrent pas leur cause.

Les résultats bruts, durées individuelles, profils et empreintes des sources
sont conservés dans [native-php-read-optimizations.json](../tests/results/native-php-read-optimizations.json).

## Reproduction

Utiliser le laboratoire isolé MediaWiki 1.43/PHP 8.3 et ses vraies extensions,
comme dans [le rapport du banc complet](debatephp-fullstack-validation.md).
Les benchmarks doivent être exécutés successivement.

```sh
git worktree add --detach ../wikidebates-before-reads 7681ca6dd775bc64ccc586b46ec09e804f4a2931
php tests/run.php
python3 debatephp/tests/regenerate.py
php tests/benchmarkNativeReads.php --baseline=/absolute/path/to/wikidebates-before-reads --output=/tmp/wikidebates-reads.json
WD_OPTIMIZATION_BASELINE=/absolute/path/to/wikidebates-before-reads WD_NATIVE_BENCHMARK_REPEATS=30 MW_INSTALL_PATH=/path/to/mediawiki-1.43 WD_FULLSTACK_AUTOLOAD=/path/to/dependencies/vendor/autoload.php python3 tests/fullstack.py --php php --output /tmp/wikidebates-reads-fullstack.json
```

Aucun accès serveur ni déploiement en production, aucune modification de
`LocalSettings.php` et aucune fusion de PR ne sont effectués.
