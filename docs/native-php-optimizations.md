# Optimisations natives — PR #3, suivi #2

Cette phase prolonge les cinq commits préexistants de
`codex/native-php-optimizations`, conservés sans réécriture. La référence
avant/après est leur dernier commit, `88bfffb704228671604c6b268d2b79e4f1e96b15`.
Les gains ci-dessous concernent donc les changements supplémentaires de cette
phase, pas l'ensemble des migrations depuis Lua ou DebatePHP 0.6.

MediaWiki reste en 1.43, le mode `reference` reste le défaut et les deux modes
partagent les mêmes optimisations vérifiées. Aucun accès au serveur du site,
aucune utilisation de sa base, aucune modification de `LocalSettings.php`,
aucun déploiement et aucune fusion de PR ne font partie de ce travail.

## Changements et invariants

### Requêtes sémantiques observables

`DebatePHPProfiling`, toujours désactivé par défaut, expose maintenant
`semantic` et `semantic_cache_hits` dans les événements privés `DebatePHP`.
Les origines sont une liste fermée : `debate-parents`, `argument-parents`,
`breadcrumb`, `cards`, `initialization`, `writes`, `other`.

Pour chaque origine et phase, le profil rapporte `calls`, `failures`,
`milliseconds` cumulés et `max_ms`. Les phases sont :

- `read` : appel complet à l'adaptateur, y compris conversion des résultats ;
- `query` : construction et exécution de la requête via `LibraryFactory` ;
- `result` : formatage SemanticScribunto et conversion vers les tables attendues ;
- `write` : préparation et ajout des annotations au résultat du parseur.

`read` inclut `query` et `result` : ne pas additionner ces trois durées.
La matérialisation des valeurs SMW peut effectuer des lectures paresseuses
pendant `result` : `query` ne représente donc pas tout le temps SQL.
Les compteurs mesurent des appels logiques à l'adaptateur, pas un nombre de
requêtes SQL, de lignes ni de trajets réseau. Aucun SQL supplémentaire n'est
exécuté pour les observations. Une écriture observée n'est pas encore une
persistance SQL ; celle-ci reste effectuée par les mises à jour/jobs normaux.

Un échec désigne une exception, un résultat de requête de type inattendu ou un
message d'erreur renvoyé par l'écriture. Un résultat vide reste un succès.
Les avertissements internes contenus dans un `QueryResult` valide ne sont pas
comptés comme des exceptions.

Les succès de cache sont séparés par couche `query`, `titles`, `card` et par
origine demandeuse. Un succès de cache de titres ne déclenche pas un second
compteur de cache de requêtes. Un résultat nul mémorisé conserve son comportement
et compte comme succès de cache. Les cartes HTML peuvent avoir des succès de
cache sans requête SMW : ce compteur ne prouve pas une économie SQL.
La provenance est rétablie dans un `finally`, y compris après une exception.

Les origines/phases/couches sont filtrées ; ni texte de requête, ni propriété,
ni valeur, ni message/trace d'exception n'est ajouté au profil. Le contexte
page/mode déjà existant est conservé. En désactivé, les horloges, compteurs et
collections de profil restent inactifs.

### Références et citations décodées une seule fois

`DecodedReferences` appartient à un rendu et est réinitialisé à chaque appel
à `render()`. Le résultat de découpage est partagé par les consommateurs HTML
et JSON-LD. Un cache par bloc permet aussi de réutiliser les références déjà
décodées quand les côtés pro/contre/neutre sont regroupés pour le JSON-LD Débat.

Seul le découpage de chaînes déjà développées est mémorisé. Le développement
wiki, les ajouts de catégories, les annotations sémantiques et les écritures
`WD_QUOTES_*`/`WD_QUOTE_*` restent à leur emplacement et dans leur ordre initial.
Les tables de records partagées sont internes et lues sans modification par
les consommateurs audités. Aucun cache inter-page ni cache persistant n'est ajouté.

Les règles de marqueurs, éléments vides, champs manquants/surnuméraires,
espaces ASCII, espaces insécables et octets nuls sont préservées. La citation
exige toujours un séparateur de champs triple ; les autres références peuvent
être admises avec un séparateur d'éléments seul.

### PHP natif, par étapes vérifiables

Les accumulateurs de fragments finaux `pieces`, certaines listes HTML locales,
les ensembles de déduplication locaux et les accumulateurs du décodeur utilisent
des tableaux PHP. Les interfaces qui attendent des `Table` continuent de
recevoir des `Table` avec leur indexation historique.

Le comptage limité des segments de fil d'Ariane utilise `strpos`/`substr` et
conserve l'arrêt au sixième segment non vide ; il ne découpe pas entièrement
une très longue chaîne. Les listes partagées, les règles de vérité Lua,
les tables associatives JSON et les caches FIFO restent conservés.
La couche `Lua` n'est pas supprimée et aucune conversion générale risquée
des objets partagés n'est engagée.

`nativeOptimizations.py` réapplique ces transformations après les générateurs
historiques. Les deux moteurs reconstruits dans un répertoire temporaire ont
été comparés octet pour octet aux fichiers livrés. `UPSTREAM.json` conserve les
empreintes amont et répertorie séparément les adaptations locales.

## Audit des erreurs SQL

Tous les `catch` de `src/` et `debatephp/src/`, ainsi que les appels `Lua::pcall`,
ont été examinés. `DatabaseFailure::rethrow` laisse remonter l'exception reçue,
avec son identité et sa chaîne de causes, pour `DBError` et ses sous-classes,
`PDOException`, `mysqli_sql_exception` et les exceptions qui les enveloppent.

| Frontière | Résultat |
| --- | --- |
| `smwAsk` | Erreur SQL propagée ; erreur ordinaire récupérable conserve le repli nul |
| `smwSet` | Erreur SQL propagée ; annotations et écritures différées conservées |
| `seoSet` | Erreur SQL propagée, y compris depuis le hook SEO |
| `variableSet` / `variableGet` | Erreur SQL propagée ; repli ordinaire conservé |
| `Lua::pcall` | Ne transforme plus une erreur SQL en succès de traitement vide |
| `RecentDebateChanges::render` | Ne convertit plus une erreur SQL en HTML rouge |
| `Hooks::execute` | Relance déjà l'exception ; finalisation du profil conservée |
| `Telemetry::emit` | Échec du seul journal volontairement absorbé pour ne pas masquer l'erreur du rendu |
| `AutoParentLink` et opérations sans récupération | Exceptions non interceptées par l'extension |

SMW 6.0.1 présente une difficulté spécifique :
`SMW\MediaWiki\Connection\Database::select` transforme `DBError` en
`RuntimeException` sans `previous`. Ce chemin a été reproduit avec une vraie
requête en échec. L'extension reconnaît la frontière dans la pile, sans analyser
le message SQL, et relaie l'exception restante. Elle ne peut pas reconstituer
l'objet SQL original détruit par SMW. Les erreurs de sections transactionnelles
de `TransactionHandler` sont également propagées. Cette reconnaissance est
liée à ces méthodes SMW et devra être revue lors d'une mise à jour de SMW.

La suppression du masquage a révélé un ancien problème : les contributions
récentes interrogeaient les tables SMW même sans SMW chargé. Cette absence
normale est maintenant traitée avant toute requête, avec le message traduit
d'absence d'identifiant sémantique. Un SMW chargé mais mal initialisé produit
toujours une erreur SQL visible par le gestionnaire MediaWiki.

L'incident intermittent `DBTransactionStateError` mentionné dans #2 n'a pas été
reproduit comme incident métier sur une nouvelle page et sa cause n'est pas
attribuée. Les tests prouvent la propagation des erreurs et les créations de
pages synthétiques, pas la résolution certaine de cet incident.

## Étude des API PHP directes de SMW et Variables

L'adaptateur courant est déjà du PHP natif : il n'instancie aucun interpréteur
Lua pour lire/écrire les propriétés. Dans SemanticScribunto 2.3.3,
`LibraryFactory::newQueryResultFrom` appelle principalement :

1. `SMWQueryProcessor::getComponentsFromFunctionParams` et `addThisPrintout` ;
2. `getProcessedParams` et `createQuery`, avec `QueryContext::SPECIAL_PAGE` ;
3. l'option de provenance `SSC.LibraryFactory` lorsqu'elle est disponible ;
4. `Store::getQueryResult`.

Un prototype **réservé aux tests** construit directement cette requête et
retrouve le résultat du chemin actuel sur la sonde typée enregistrée. Il conserve
`LuaAskResultProcessor` pour comparer le formatage ; ce n'est pas une preuve
qu'une consommation directe des `DataItem` reproduirait l'ensemble des sorties.
Ce processeur gère notamment booléens, nombres, libellés, valeurs multiples et
texte wiki des valeurs. Le remplacer demande un corpus dédié de types,
sous-objets, paramètres d'impression, limites, tri et erreurs.

Pour les écritures, `LibraryFactory` construit le `SetParserFunction` et son
traitement de paramètres via les services SMW. Contourner le collecteur du
parseur avec une écriture directe dans le magasin risquerait de changer les
effacements, annotations, sous-objets et mises à jour différées. Aucun tel
contournement n'est introduit. Les dépendances de cache doivent aussi être
vérifiées explicitement avant tout changement de chemin de lecture.

Les variables passent déjà par les fonctions PHP de Variables, avec la garde
de compatibilité VariablesLua. Écrire directement dans son stockage interne
pourrait contourner le traitement des paramètres et la volatilité des frames.
Cette modification, ainsi que le retrait éventuel de SemanticScribunto, reste
une décision pour une PR distincte. Aucun gain de cette refonte non réalisée
n'est annoncé ici.

## Résultats exécutés

Les essais ont utilisé le cœur **MediaWiki 1.43.11**, SMW **6.0.1**, SemanticScribunto **2.3.3**, VariablesLua **1.7.0** et les dépendances épinglées du laboratoire existant. PHP 8.3 provient de l’image de test `mediawiki:1.43` ; le cœur effectivement chargé est le checkout explicite 1.43.11. Les conteneurs PHP et MariaDB fonctionnent avec `--network none`.

| Exécution | Comparaisons Lua/PHP | Comparaisons natives avant/après | Variantes persistées | Résultat |
| --- | ---: | ---: | ---: | --- |
| PHP 8.3.35 / SQLite 3.46.1 — série 1 | 280 sur 140 cas | 280 | 50 | Réussi |
| PHP 8.3.35 / SQLite 3.46.1 — série 2 | 280 sur 140 cas | 280 | 50 | Réussi |
| PHP 8.3.35 / MariaDB 10.11.19 | 280 sur 140 cas | 280 | 50 | Réussi |
| PHP 8.2.32 / SQLite 3.46.1 | 280 sur 140 cas | 0 | 30 | Réussi |

Les comparaisons natives vérifient les sorties HTML complètes, scripts JSON-LD compris, les propriétés de page, les valeurs sémantiques typées, les variables récapitulatives de citations, les catégories, liens, modules, dépendances de modèles et paramètres de cache. Seule la durée est exclue de cette égalité. La comparaison historique avec Lua conserve ses deux différences intentionnelles déjà documentées : encodage JSON ancien et retrait des quatre dépendances de sources Lua obsolètes.

Le banc vérifie aussi créations/modifications/suppressions de valeurs, écritures réellement différées, job `smw.update`, partage des variables entre Lua et PHP, isolation entre parses, révisions de modèles, hook de liens parentaux et contributions récentes. Les 50 sauvegardes incluent les moteurs avant/après et les cas riches. Les files de jobs sont vides avant les essais d’échecs SQL contrôlés.

Autres contrôles réussis :

- Tests autonomes sous PHP 8.2 et 8.3 : 2 492 comparaisons de traduction, 33 assertions d’adaptateur, 12 assertions FIFO, 1 652 équivalences de records ; 64 contrôles SQL sous PHP 8.3 et 56 sous PHP 8.2, où mysqli n’est pas installé.
- Oracles Lua indépendants : 3 888 comparaisons de conditions, 3 301 de découpages/textes/fils d’Ariane, 174 de rendu Argument avec effets et variables complets, 2 510 de traductions.
- MediaWiki réel : 13 fixtures dans les deux modes et 14 cas d’opérations natives, chargement unique de Wikidebates, puis même banc avec Scribunto/PageForms/ShortDescription/WikiSEO/Variables.
- Internationalisation : 390 messages documentés, 623 entrées historiques, 12 combinaisons de langue à la frontière JavaScript simulée ; six langues ResourceLoader réelles pour chacun des contenus anglais et français.
- Reconstruction identique des deux moteurs, fichiers i18n générés à jour, syntaxe et empreintes locales vérifiées. Aucun changement de `LocalSettings.php`.

Les mesures brutes, profils et résumés se trouvent dans [native-php-optimizations.json](../tests/results/native-php-optimizations.json).

### Mesures ciblées du décodage

31 échantillons par version, chacun moyennant 20 traitements, ordre mélangé, données synthétiques identiques. Les records obtenus sont comparés avant le chronométrage. Ces chiffres couvrent le nouveau chemin de décodage seul, sans parseur ni SMW.

| Cas | Médiane avant (ms) | Après (ms) | Réduction | P95 avant → après (ms) | CPU médian avant → après (ms) |
| --- | ---: | ---: | ---: | ---: | ---: |
| argument | 0.2083 | 0.0969 | 53.45 % | 0.4224 → 0.1290 | 0.2084 → 0.0971 |
| debate | 0.4653 | 0.1222 | 73.73 % | 0.6314 → 0.1761 | 0.4656 → 0.1224 |

Les blocs effectivement décodés passent de **152 à 76** pour la sonde Argument et de **384 à 64** pour la sonde Débat (références identiques réutilisées entre côtés). Le temps économisé reste de l’ordre de **0,11 ms** et **0,34 ms** sur ces sondes. Il ne s’agit pas d’une réduction de 53–74 % du temps d’une page.

| Pic mémoire supplémentaire du décodage isolé | Avant | Après | Écart |
| --- | ---: | ---: | ---: |
| argument | 59.85 Kio | 102.41 Kio | +42.56 Kio |
| debate | 175.48 Kio | 92.11 Kio | -83.37 Kio |

Ce pic est l’allocation PHP supplémentaire du traitement isolé, pas la mémoire totale du processus ni le pic d’une page MediaWiki. La conservation des records augmente le pic de la sonde Argument ; le partage des records du regroupement Débat le réduit dans cette autre sonde.

### Parsing complet avec le véritable magasin SMW

Deux séries successives de 15 échantillons par couple scénario/mode, soit **30 échantillons** agrégés par ligne. L’ordre avant/après est mélangé à chaque répétition après échauffement. Le P95 utilise le rang supérieur. Aucun autre banc de cette tâche n’est lancé simultanément pendant ces chronométrages. Le profilage est désactivé pour ce tableau.

| Scénario | Mode | Médiane avant → après (ms) | Réduction médiane | P95 avant → après (ms) |
| --- | --- | ---: | ---: | ---: |
| rich native argument en | reference | 28.563 → 28.596 | -0.12 % | 92.366 → 92.341 |
| rich native argument en | optimized | 28.620 → 27.973 | +2.26 % | 99.040 → 39.120 |
| rich native debate en | reference | 55.542 → 53.055 | +4.48 % | 130.172 → 128.766 |
| rich native debate en | optimized | 53.895 → 56.095 | -4.08 % | 135.272 → 128.108 |
| rich native argument fr | reference | 28.523 → 28.450 | +0.26 % | 136.972 → 128.354 |
| rich native argument fr | optimized | 27.628 → 28.945 | -4.77 % | 124.901 → 118.032 |
| rich native debate fr | reference | 58.054 → 56.675 | +2.38 % | 191.854 → 178.817 |
| rich native debate fr | optimized | 59.574 → 57.671 | +3.19 % | 188.531 → 186.891 |
| parents en | reference | 21.162 → 21.044 | +0.56 % | 155.306 → 152.403 |
| parents en | optimized | 20.651 → 20.936 | -1.38 % | 152.599 → 150.021 |
| parents fr | reference | 20.916 → 20.905 | +0.05 % | 163.911 → 40.294 |
| parents fr | optimized | 21.332 → 20.754 | +2.71 % | 169.898 → 157.815 |
| initialization | reference | 19.180 → 19.118 | +0.32 % | 151.096 → 165.959 |
| initialization | optimized | 19.637 → 20.068 | -2.19 % | 157.011 → 155.263 |
| cards | reference | 21.754 → 21.593 | +0.74 % | 170.806 → 28.986 |
| cards | optimized | 21.613 → 22.253 | -2.96 % | 168.058 → 173.055 |

Un pourcentage négatif indique un ralentissement mesuré. Les médianes agrégées vont ici de **−4,77 % à +4,48 %** : aucun gain global uniforme n’est établi. Certains P95 augmentent, notamment l’initialisation en `reference` et les cartes en `optimized`. Les distributions sont dispersées et parfois bimodales ; les médianes d’une série à l’autre peuvent changer de sens. Les échantillons par série restent fournis pour éviter de cacher cette variabilité par l’agrégation.

Le banc conserve de nombreux rendus dans un même processus et réutilise les services chauds ; il inclut les lectures SMW locales, mais pas un cycle HTTP complet. Les allocations, le ramasse-miettes et l’environnement partagé peuvent affecter les mesures. Le coût très faible du décodage n’autorise pas à promettre un gain visible pour chaque visiteur. Le défaut `reference` est donc conservé.

Les profils réels ont couvert les six origines métier. Dans la sonde `parents en`, le profil `reference` observe huit lectures logiques (deux de fil d’Ariane, trois de parents débats et trois de parents arguments), une écriture d’annotations et un succès du cache de cartes. Les replis de recherche peuvent donc représenter plusieurs lectures pour une seule tâche métier. Cela fournit une piste à mesurer avec des données représentatives avant de changer les requêtes.

Les profils avant/après et les séries avec profilage actif sont conservés dans les résultats. Les sorties profilées et non profilées sont comparées sur les huit scénarios et quatre variantes, sans différence. Leur mesure est effectuée dans des blocs successifs : elle ne constitue pas une estimation robuste du surcoût de profilage sous charge.

Les mesures MariaDB avec cinq échantillons par couple servent de complément fonctionnel et exploratoire ; elles ne sont pas utilisées pour annoncer un gain de performance MariaDB.

## Reproduction

Le laboratoire et les versions épinglées des dépendances sont décrits dans
`debatephp-fullstack-validation.md`. PHP 8.3 CLI avec SQLite et mysqli permet
les deux variantes SQL. Le cœur reste le checkout MediaWiki 1.43.11 de ce banc.

```sh
git worktree add --detach ../wikidebates-before 88bfffb704228671604c6b268d2b79e4f1e96b15
php tests/run.php
MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawiki.php
MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawikiDatabaseErrors.php
MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawikiI18n.php
WD_OPTIMIZATION_BASELINE=/absolute/path/to/wikidebates-before MW_INSTALL_PATH=/path/to/mediawiki-1.43 WD_FULLSTACK_AUTOLOAD=/path/to/dependencies/vendor/autoload.php python3 tests/fullstack.py --php php --output /tmp/wikidebates-native.json
php tests/benchmarkNativeDecoding.php --baseline=/absolute/path/to/wikidebates-before --output=/tmp/wikidebates-decoding.json
```

Le banc complet conserve les artefacts de plusieurs moteurs simultanément ;
son lanceur fixe une limite CLI de 512 Mio, modifiable par `--memory-limit`.
Ce réglage du banc n'est pas une exigence mémoire annoncée pour chaque page.
Les fixtures supplémentaires sont synthétiques, dans `nativeOptimizationCases.php`.

Pour MariaDB, démarrer une instance **jetable**, sans accès réseau, avec un
socket dans un dossier nommé `wikidebates-lab-*`. Le PHP de test doit voir ce
socket ; aucun hôte TCP ni identifiant d'une base existante n'est accepté par
ce chemin de test. Ajouter ces variables à la commande du banc complet :

```sh
WD_TEST_DISPOSABLE_MARIADB=1
WD_TEST_MARIADB_SOCKET=/path/to/wikidebates-lab-mariadb/mysqld.sock
```

Ces variables doivent être exportées ou placées avant la commande. Le serveur
de laboratoire accepte uniquement une connexion locale `root` sans mot de passe,
sa création doit donc être réservée à cette instance sans réseau. Chaque
exécution crée un nom de base aléatoire `wd_isolated_*`, installe les tables,
puis supprime cette base à sa fin. SQLite reste la variante par défaut et crée
son propre fichier temporaire. Aucun `LocalSettings.php` n'est lu.

## Risques et limites

- La mémorisation des records conserve leurs données jusqu'à la fin du rendu :
  les gains de décodage ont un coût mémoire, mesuré séparément ci-dessus.
- Les benchmarks du laboratoire ne prouvent aucun gain HTTP global sur le site.
  Ils n'incluent ni proxy HTTP, ni cache distribué, ni réplication, ni charge
  concurrente représentative. Le profilage activé a lui-même un coût.
- La reconnaissance de l'exception SMW sans cause dépend des méthodes auditées
  de SMW 6.0.1. Les erreurs déjà entièrement absorbées à l'intérieur d'une autre
  extension ne peuvent pas être retrouvées par ce mécanisme.
- Les données du site, tous ses types de propriétés, ses modèles personnalisés,
  les formulaires réellement soumis et une navigation de navigateur complète
  ne sont pas couverts. Les besoins d'un export anonymisé cohérent sont détaillés
  dans le rapport de validation initial.
- Aucun changement de schéma métier n'est introduit. Un retour au commit
  précédent peut rétablir le code, mais ne doit pas être présenté comme une
  annulation des écritures wiki/sémantiques effectuées entre-temps. Répéter les
  vérifications en recette avant toute décision de déploiement.
