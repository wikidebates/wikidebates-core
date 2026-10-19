# Sélection mesurée des optimisations restantes — PR #3

Référence : `aca0e6de7f20e17ed650590ca7a20803740b388e`.
Cette phase mesure six pistes ; les gains des phases précédentes ne lui sont pas attribués.

**État actuel : le plafond du cache des références a été retiré après cette campagne.** Les tableaux ci-dessous conservent les mesures de `05d5483`, où le plafond était encore actif. Les trois autres optimisations sont conservées. Voir la [décision et la validation du retrait](native-php-reference-cache.md).

## Décisions

| Piste | Décision | Motif |
| --- | --- | --- |
| Parcours des données d’arguments | Retenue | Les quatre parcours Debate et les trois parcours Argument deviennent un parcours chacun, avec des accumulateurs PHP locaux. Les identités `Table`, les indices, les séparateurs, l’arrêt au premier trou et le comportement des champs `null` restent compatibles. |
| Vues natives d’arguments et d’introductions | Différée | Les prototypes isolés décodent plus vite, mais les consommateurs actuels exposent des `Table` partagées et des méthodes protégées surchargeables. Le retour aux objets est mesuré séparément : son surcoût annule le bénéfice du prototype. Aucune nouvelle API de records n’est activée sur les pages. |
| Fragments HTML locaux | Retenue | Accumulateurs natifs dans les sous-sections d’introduction, les éléments des listes d’arguments et les enveloppes de bibliographie/webliographie/vidéographie. Catégories, objets partagés et appels avec effets restent en place. |
| Normalisation des titres de cartes | Retenue | Opérations textuelles PHP, mêmes expressions régulières et même ordre de décodage des entités. Cache limité à 200 titres de 1 024 octets au maximum, remis à zéro à chaque rendu ; les titres simples ne sont pas mis en cache. Les appels MediaWiki qui enregistrent les dépendances restent exécutés. |
| Mémoire des références décodées | Retirée après évaluation | Le plafond expérimental limitait la rétention, mais imposait du redécodage aux grandes listes relues. Le cache partagé existant est rétabli ; les mesures du compromis sont conservées ci-dessous. |
| Requêtes SMW des parents non vides | Écartée dans cette phase | Une limite globale sur une union ne reproduit pas deux limites par branche. Un contre-exemple est testé avec le véritable magasin SMW. Le formatage des valeurs et les replis historiques restent inchangés. Aucun gain supplémentaire de requêtes n’est revendiqué. |

Les deux modes reçoivent ces calculs purs. `reference` reste la valeur par défaut. Hooks, traductions, paramètres et chargement unique de Wikidebates sont conservés. Aucune dépendance supplémentaire.

## Mesures des calculs PHP

| Sonde | Avant, ms | Après, ms | Variation | Pic supplémentaire avant → après, octets |
| --- | ---: | ---: | ---: | ---: |
| argument-data-debate-100 | 0,12888 | 0,05838 | -54,70 % | 77912 → 77688 |
| mocked-rich-debate | 1,66962 | 1,43752 | -13,90 % | 356472 → 356472 |
| argument-data-argument-100 | 0,12938 | 0,07777 | -39,89 % | 46440 → 46272 |
| mocked-rich-argument | 2,54265 | 2,21032 | -13,07 % | 361648 → 360984 |
| introduction-html-40 | 0,09208 | 0,05080 | -44,82 % | 49104 → 49048 |
| title-plain-100 | 0,04732 | 0,01804 | -61,88 % | 1624 → 1536 |
| title-encoded-100 | 0,22467 | 0,01824 | -91,88 % | 2032 → 1024 |
| title-cold-unique-encoded-100 | 0,22556 | 0,10637 | -52,84 % | 3496 → 24808 |
| references-normal | 0,05088 | 0,05825 | 14,48 % | 50120 → 50184 |
| references-pressure | 5,84892 | 5,36229 | -8,32 % | 4736360 → 465464 |
| references-reused-list-40 | 0,03499 | 0,03794 | 8,41 % | 39320 → 39384 |
| references-reused-list-4000 | 3,68074 | 6,96304 | 89,18 % | 3631552 → 3516136 |
| prototype-arguments-native-100 | 0,04310 | 0,02959 | -31,35 % | 64664 → 59064 |
| prototype-introductions-native-40 | 0,01841 | 0,01429 | -22,38 % | 25928 → 23688 |
| prototype-arguments-compatibility-roundtrip-100 | 0,04377 | 0,05966 | 36,28 % | 64664 → 96496 |
| prototype-introductions-compatibility-roundtrip-40 | 0,01823 | 0,02607 | 43,04 % | 25928 → 38248 |

Les sondes utilisent **les sources exactes de la référence**, chargées sous un autre namespace, et vérifient les sorties avant de chronométrer. Les deux côtés utilisent les interfaces natives déjà présentes dans `aca0e6d`. Les prototypes de records sont explicitement séparés du code de production. Chaque sonde comporte 31 échantillons mélangés, dix opérations par échantillon et des médianes temps mural/CPU. L’échauffement du cache des titres est volontaire pour la sonde répétée ; la sonde de titres uniques recrée un moteur à chaque opération. Les rendus simulés ne constituent pas une mesure MediaWiki.

La sonde de **4 000 listes de référence uniques** passe de 4736360 à 465464 octets de pic supplémentaire, soit **90,17 % de moins**. Le coût de la petite sonde et celui des grandes listes relues restent visibles dans le tableau ; ce compromis a finalement conduit au retrait du plafond, sans modifier ces mesures historiques.

Pendant cette campagne, le plafond bornait la rétention, avec un compromis explicite : la sonde d’une liste de **4 000 références relue deux fois** est sensiblement plus lente, car elle dépasse le budget de cases et doit être redécodée. Ce scénario figure dans les résultats et ne doit pas être présenté comme une accélération.

Le plafond des chaînes n’est pas une limite de mémoire PHP totale : les tableaux PHP, les objets de compatibilité, les arguments en cours et le résultat rendu occupent aussi de la mémoire. Les limites d’entrées et de cases bornent également les tableaux retenus par le cache natif. Les listes trop grandes doivent être redécodées à la lecture suivante ; le tableau ci-dessus chiffre ce compromis. Le cache historique des objets `Table`, utilisé par des appels de compatibilité, conserve ses identités et reste sans plafond d’éviction. Le plafond expérimental a depuis été retiré du chemin natif intégré ; une page volumineuse peut consommer davantage de mémoire. Le cache reste propre à un rendu.

## Rendus avec les véritables extensions

MediaWiki **1.43.11**, PHP **8.3.35**, véritables **Semantic MediaWiki 6.0.1**, **SemanticScribunto 2.3.3**, **VariablesLua 1.7.0**, Scribunto REL1_43 et Variables **2.6.0-beta**. PageForms **5.8.1**, ShortDescription **4.0.2** et WikiSEO **2.7.0** sont aussi chargés.

| Exécution | Cas | Comparaisons Lua/PHP | Natif avant/après | Variantes persistées | Profils comparés |
| --- | ---: | ---: | ---: | ---: | ---: |
| PHP 8.3 / SQLite 3.46.1 | 144 | 288 | 288 | 70 | 24 |
| PHP 8.3 / MariaDB 10.11.19 | 144 | 288 | 288 | 70 | 24 |

Les quatre cas ajoutés couvrent, en français et en anglais, des listes de **100 arguments de chaque côté** ; les débats comportent aussi **40 sous-sections d’introduction**. Les propriétés de ces pages sont effectivement enregistrées puis comparées dans SQLStore. Les tests comparent les cinq variantes Lua, PHP historique reference/optimized et PHP courant reference/optimized.

Le HTML complet, les graphes JSON-LD, les propriétés sémantiques typées, les catégories, liens, modules, variables et dépendances/paramètres de cache passent. Le comparateur Lua conserve uniquement ses normalisations historiques documentées ; aucune nouvelle différence de sortie n’est autorisée. Les écritures différées, mises à jour/suppressions, jobs, hooks de parenté et partage de variables Lua/PHP passent. Les files sont vides en fin de test.

**30 échantillons par scénario et variante sous SQLite.** Médianes en millisecondes.

| Scénario | Reference avant → après | Variation | Optimized avant → après | Variation |
| --- | ---: | ---: | ---: | ---: |
| rich native argument en | 24,426 → 24,479 | 0,22 % | 24,366 → 25,026 | 2,71 % |
| rich native debate en | 53,114 → 54,648 | 2,89 % | 54,904 → 54,892 | -0,02 % |
| measured lists debate en | 85,139 → 86,000 | 1,01 % | 84,453 → 160,420 | 89,95 % |
| measured lists argument en | 62,553 → 62,899 | 0,55 % | 62,450 → 62,701 | 0,40 % |
| rich native argument fr | 25,764 → 25,843 | 0,30 % | 25,707 → 25,139 | -2,21 % |
| rich native debate fr | 62,129 → 66,017 | 6,26 % | 63,684 → 59,689 | -6,27 % |
| measured lists debate fr | 89,252 → 86,470 | -3,12 % | 92,787 → 89,380 | -3,67 % |
| measured lists argument fr | 68,277 → 68,368 | 0,13 % | 67,914 → 66,286 | -2,40 % |
| parents en | 21,104 → 21,212 | 0,51 % | 21,447 → 20,939 | -2,37 % |
| parents fr | 21,715 → 22,170 | 2,09 % | 21,769 → 22,615 | 3,88 % |
| initialization | 16,923 → 16,716 | -1,23 % | 17,537 → 17,233 | -1,73 % |
| cards | 21,275 → 21,429 | 0,72 % | 21,046 → 20,882 | -0,78 % |

La plage de variations des médianes est de -6,27 % à 89,95 %. Ces résultats concernent les scénarios synthétiques du laboratoire ; ils ne constituent pas une promesse de gain uniforme sur le site.

Une première série SQLite a échoué à la limite de 512 Mio pendant l’encodage en bloc du corpus de comparaison, après les comparaisons natives et les profils. Ce résultat incomplet n’est pas compté comme une réussite et ses temps ne sont pas publiés. Le banc écrit désormais chaque ligne du corpus avant les benchmarks et libère les rendus retenus ; les contrôles de contenu restent identiques. Les séries finales sont relancées avec la même limite de 512 Mio.

Les mesures sont des parsings CLI échauffés, sans cache de page servant une réponse déjà rendue. L’ordre des variantes est mélangé. Le profilage est désactivé pendant les mesures principales et testé séparément. Les temps SQL et MediaWiki font partie de ces durées ; une variation sur une page ne mesure pas isolément le changement PHP. Tous les échantillons, médianes, P95 et profils sont conservés dans le fichier de résultats. La série MariaDB de cinq échantillons sert principalement à valider les comportements et reste exploratoire pour les performances.

## Diagnostic du scénario instable

Une hausse de médiane de **+89,95 %** apparaît sur `measured lists debate en/optimized` dans la série principale : les échantillons alternent entre environ 85 et 240 ms. Cette série est conservée intégralement. Un diagnostic supplémentaire mesure le temps du collecteur de cycles PHP **à l’intérieur du parsing**, dans une nouvelle base SQLite et un nouveau processus, après la matrice de correction complète. Il compare 41 échantillons par mode et politique de collection, soit 328 parsings supplémentaires, avec égalité exacte des sorties.

| Politique / mode | Médiane, ms | P95, ms | Échantillons avec GC | Temps cumulé du collecteur pendant les parsings, ms |
| --- | ---: | ---: | ---: | ---: |
| natural / before-optimized | 86,149 | 222,189 | 11 / 41 | 1397,690 |
| natural / before-reference | 85,944 | 240,048 | 12 / 41 | 1660,789 |
| natural / optimized | 86,735 | 229,096 | 10 / 41 | 1341,512 |
| natural / reference | 85,697 | 225,201 | 11 / 41 | 1390,807 |
| collect-before-render / before-reference | 83,852 | 96,110 | 0 / 41 | 0,000 |
| collect-before-render / reference | 84,380 | 97,239 | 0 / 41 | 0,000 |
| collect-before-render / before-optimized | 84,168 | 95,225 | 0 / 41 | 0,000 |
| collect-before-render / optimized | 83,455 | 97,396 | 0 / 41 | 0,000 |

La politique `collect-before-render` appelle le collecteur **hors de la fenêtre chronométrée** avant chaque parsing : elle sert à diagnostiquer les pointes, pas à annoncer un gain utilisateur. Son coût n’est pas inclus dans les médianes et aucun appel au collecteur n’est ajouté au moteur de production. Les résultats naturels de la série principale ne sont ni remplacés ni filtrés.

Dans le diagnostic naturel, les médianes passent de **85,944 à 85,697 ms** en reference (**−0,29 %**) et de **86,149 à 86,735 ms** en optimized (**+0,68 %**). Les quatre variantes présentent des pointes : les échantillons avec collecte prennent en moyenne environ 210 à 225 ms, contre 84 à 85 ms sans collecte ; le temps mesuré du collecteur explique environ **127 à 138 ms** de cet écart. La hausse de 89,95 % de la série principale ne se reproduit pas dans cette nouvelle série. Le diagnostic étaye un effet de répartition des collectes sur les médianes, sans prouver rétrospectivement la cause de chaque échantillon initial. Aucun réglage GC ni gain HTTP n’en est déduit.

## Parenté SMW : limite démontrée

Deux pages synthétiques possèdent chacune une propriété de parenté différente pointant vers la même cible. Une union limitée à **un résultat** renvoie une page et signale des résultats supplémentaires ; les deux recherches séparées, chacune limitée à un, renvoient deux pages au total. Le test utilise cette petite limite pour démontrer le mécanisme. Il ne prétend pas avoir créé 201 parents : à la limite de production de 200, la même différence de contrat interdit de supprimer les replis sans une preuve supplémentaire sur l’exhaustivité, l’ordre et le formatage.

Les profils avant/après de cette phase doivent garder exactement les mêmes lectures logiques, écritures, échecs, succès de cache, traductions et prétraitements.

Lectures logiques SMW des cas de parenté et de cartes (identiques dans les deux modes) :

| Scénario | Avant | Après |
| --- | ---: | ---: |
| parents en | 8 | 8 |
| parents fr | 8 | 8 |
| cards | 9 | 9 |

Les replis vides certifiés de la phase précédente restent actifs, avec leurs gardes sur le cache SMW, les erreurs et la pagination.

## Tests supplémentaires et limites

- **7 040 assertions nouvelles**, sous PHP 8.3 et 8.2 : identités partagées, octets des cartes et séparateurs, champs nuls, trous dans les indices, ordre des entités, durée de vie et limites des caches, listes et blocs trop gros. Elles comprennent 2 400 comparaisons de titres avec l’algorithme historique et 2 400 comparaisons des prototypes de records.
- Suites existantes conservées : **36 438** assertions de collections, **8 047** de chemins natifs, **8 484** de lectures natives, **1 652** équivalences de références, **32** de réutilisation des moteurs, **80** contrôles SQL sous PHP 8.3 et **70** sous PHP 8.2, avec adaptateurs, FIFO, télémétrie et i18n.
- Oracles Lua : **3 888** conditions, **3 301** opérations sérialisées, **174** rendus Argument avec effets, **2 510** traductions. Régénération exacte des deux moteurs et **10** cas de captures lexicales.
- Chargement unique de Wikidebates, **13** fixtures dans les deux modes, **18** opérations natives avec/sans extensions optionnelles, six langues ResourceLoader avec contenus EN/FR, **390** messages documentés et **12** combinaisons JavaScript.
- Lint PHP 8.3 de tous les fichiers PHP ajoutés/modifiés, absence de nouveaux espaces d’indentation, diff propre et empreintes locales vérifiées. Les empreintes de l’import 0.8 et les instantanés Lua restent inchangés.

La campagne avec les extensions réelles vise PHP 8.3 ; PHP 8.2 reçoit les suites autonomes et ne constitue pas une seconde campagne complète MediaWiki/SMW.

Aucun accès à la production, aucune base de production, aucun déploiement, aucune modification de `LocalSettings.php`. Les conteneurs du laboratoire n’ont pas d’accès réseau ; MariaDB est joignable uniquement par un socket local de test. Les bases synthétiques sont supprimées à la fin des essais.

Les résultats ne certifient pas les pages réelles du site. Une validation représentative demanderait un export anonymisé de pages de débats/arguments et de leurs modèles utiles, les résultats attendus pour les propriétés sémantiques, ainsi que les versions et réglages non secrets des extensions. Le laboratoire créerait sa propre base à partir de cet export. Aucune copie de la base de production n’est nécessaire pour ces essais.

Aucune mesure HTTP/FPM, OPcache en service web ou concurrence multi-utilisateurs n’a été réalisée dans cette phase. Aucun gain de latence en production ni de capacité simultanée n’est annoncé.

## Reproduction

Créer une copie de travail détachée de la référence, sans toucher aux modifications locales :

```sh
git worktree add --detach /chemin/laboratoire/avant aca0e6de7f20e17ed650590ca7a20803740b388e
php tests/run.php
php tests/benchmarkMeasuredCandidates.php --baseline=/chemin/laboratoire/avant --output=/chemin/laboratoire/mesures.json
python3 debatephp/tests/regenerate.py
```

Pour la comparaison réelle, utiliser le laboratoire décrit dans `docs/debatephp-fullstack-validation.md`, avec PHP 8.3 et les extensions testées, et définir `WD_OPTIMIZATION_BASELINE` vers cette copie, `WD_NATIVE_BENCHMARK_REPEATS=30` et `MW_INSTALL_PATH`. Lancer `tests/fullstack.py` ; son initialisation crée une base jetable via `MW_CONFIG_CALLBACK`, sans charger les réglages d’un site existant. MariaDB nécessite en plus `WD_TEST_DISPOSABLE_MARIADB=1` et `WD_TEST_MARIADB_SOCKET` vers un répertoire local `wikidebates-lab-*`. Ne pas utiliser ces commandes avec une configuration ou une base de production.

Pour le diagnostic GC, conserver la même configuration isolée et lancer :

```sh
WD_FOCUSED_BENCHMARK_OUTPUT=/chemin/laboratoire/gc.json php -d memory_limit=512M tests/focusedNativeBenchmark.php
```

Ce diagnostic exécute d’abord la matrice complète avec cinq répétitions des benchmarks, puis les deux politiques de collection. Il ne change aucun réglage du moteur.

Résultats bruts : [`tests/results/native-php-measured-optimizations.json`](../tests/results/native-php-measured-optimizations.json).
