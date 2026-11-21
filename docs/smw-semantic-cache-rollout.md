# Migration prudente des caches sémantiques (MediaWiki 1.43 / SMW 7.3.1)

> **Statut : expérimental, DÉSACTIVÉ PAR DÉFAUT.** Les suites SMW
> et HTTP multiprocessus sur SQLite et MariaDB, y compris Vector,
> MobileFrontend et MinervaFileCache, passent sur des wikis jetables.
> Ne pas fusionner le suivi expérimental sans vérification des requêtes
> réelles, des performances et d'un pilote en préproduction.
> Sur les wikis réels, `__NOCACHE__` a **déjà été retiré** du modèle
> Argument ; les anciennes étapes de retrait plus bas sont historiques.
> Aucun changement automatique des paramètres de production.

## Architecture expérimentale

### 1. Lecture sémantique PHP traçable

Le chemin historique `LibraryFactory::newQueryResultFrom()` de
SemanticScribunto 2.3.3 crée une requête sans page d'origine. Lorsque
`WikidebatesSemanticCacheTracking` est activé, `TrackedSemanticQuery`
reproduit sa construction de requête (`QueryContext::SPECIAL_PAGE`,
`addThisPrintout`) en passant un `contextPage` à SMW 7.3.1.
Le traitement du résultat reste assuré par le convertisseur
SemanticScribunto existant : aucun nouveau moteur Lua ni changement
intentionnel du format de réponse.

Les profils de requêtes `_ASK` sont écrits dans le `ParserOutput`
à l'aide de `ParserData` et du `ProfileAnnotatorFactory` de SMW.
Ces profils permettent à SMW de retrouver les requêtes attachées à
une page. Si le contexte ne permet pas une traçabilité sûre, le rendu
est marqué non cacheable : ne jamais publier un résultat inobservable.

### Optimisation pendant la migration massive du modèle Argument (20 novembre 2026)

La suppression de `__NOCACHE__` dans `Modèle:Argument` provoque la
réactualisation de nombreuses pages et la création de nombreux jobs
`refreshLinks`. Cette cascade attendue ne doit pas être confondue avec
une boucle infinie. Chaque mise à jour du magasin SMW continue d'avancer
les compteurs transactionnels `wd_smw_entity_revisions`.

Le paramètre `$wgWikidebatesSemanticCacheEagerPurge` vaut désormais
**false par défaut**, indépendamment du suivi sémantique lui-même. Avec
`false`, le système conserve les empreintes et les vérifications à chaque
lecture mais **ne programme plus de purges anticipées**. Les jobs déjà
enregistrés renvoient un échec réessayable plutôt que d'être acquittés
sans purge. Les workers généraux doivent néanmoins exclure ce type : des
échecs répétés peuvent provoquer un abandon. Activer les purges
explicitement, après un contrôle `CheckSemanticCache --require-purge-ready`
et une validation des caches représentatifs. À réserver aux périodes contrôlées
pendant lesquelles le suivi est actif, les profils `_ASK` sont valides,
les fichiers HTML des pages concernées sont effectivement refusés,
et aucun cache HTTP/CDN externe ne force leur conservation.

Les jobs de purge effectivement exécutés ne passent plus par
`WikiPage::doPurge()`, qui déclenche `ArticlePurge` dans SMW et peut
marquer la page pour une nouvelle actualisation sémantique. Ils
révoquent directement les fragments, actualisent `page_touched`,
vident les fichiers HTML, purgent les URL CDN via `HTMLCacheUpdater`
(et le hook `TitleSquidURLs`), et révoquent la clé du cache du parseur.
Les purges *strictement identiques* sont dédupliquées ; des ensembles
d'entités ou des curseurs distincts ne sont jamais fusionnés.

Une fois la migration terminée : remettre `true` si l'on souhaite
reprendre les purges anticipées ; contrôler la validité des profils
et les caches externes, puis rétablir la configuration SMW souhaitée.
Ne pas supprimer ou réinitialiser la table des révisions sémantiques.

### 2. Après modification sémantique

Le hook `SMW::SQLStore::AfterDataUpdateComplete` récupère les ID
d'entités réellement modifiées à partir de `ChangeOp` (valeurs
créées, supprimées et propriétés). Un travail différé, réalisé après
l'opération de stockage, envoie des jobs ciblés interrogeant les
dépendances `smw_query_links`. Les jobs parcourent les IDs des
requêtes en lots et purgent leurs pages propriétaires via MediaWiki.

Une purge MediaWiki déclenche les mécanismes de cache du parseur,
les fichiers HTML ordinaires et `TitleSquidURLs`. Le hook Wikidebates
révoque les générations des fragments ; MinervaFileCache utilise
également `TitleSquidURLs` pour invalider ses fichiers.

### 3. Contrôle avant lecture des fragments

Avant un `fragment-cache` hit, l'API vérifie la présence de profils
`_ASK`, l'activation du suivi natif SMW et une empreinte durable
des liens de dépendance et révisions transactionnelles. Cette
vérification remplace, dans l'API, le simple comparateur
`smw_touched` : les essais HTTP ont révélé que ce dernier pouvait
rejeter à tort une requête pourtant fraîche. Toute absence
d'annotation requise, tout lien de requête manquant ou toute erreur
SQL impose un rendu sans cache de fragment. Le cache déjà préparé
n'est accepté que si son empreinte correspond à l'état actuel.

Les pages d'**arguments** sans profil `_ASK` restent exclues du cache
partagé tant que leurs requêtes ne sont pas traçables. Au contraire,
les débats qui n'exécutent aucune recherche sémantique doivent conserver
leurs caches existants, même sans `_ASK`. Un débat qui contient
réellement des requêtes (`_ASK` présent ou argument imbriqué)
reste protégé. Les marqueurs
`semanticTracking` et `semanticRevision` de l'identité empêchent
le réemploi d'anciens fragments. En cas d'empreinte douteuse,
l'API exécute une analyse `ApiParse` sans lecture du cache du parseur.
Les vérifications de permission restent faites AVANT le fragment.

## 4. Compteurs de révision et prévention des purges tardives

La table expérimentale `wd_smw_entity_revisions` associe chaque
entité SMW modifiée à un compteur de révision croissant. Le hook
`SMW::SQLStore::AfterDataUpdateComplete` l'incrémente dans la même
transaction SQL que les données sémantiques. Il ne dépend pas de la
précision à la seconde de `smw_touched`, ni de l'exécution immédiate
des jobs d'invalidation.

Pour chaque page interrogée, `SemanticRevisionStore::fingerprint()`
calcule une empreinte SHA-256 des **triplets exacts** (identifiant de
requête, identifiant de dépendance, révision de l'entité). Un fragment
ancien ne correspond plus à cette identité si l'une des dépendances a
changé, même si le nombre de résultats reste identique. Une requête
`_ASK` sans lien enregistré interdit la réutilisation du fragment.
Une seconde lecture du compteur avant publication réduit le risque
de stocker un rendu obsolète produit pendant une modification.

**Migration de schéma obligatoire en préproduction :** les fichiers
`sql/mysql/wd_smw_entity_revisions.sql` et
`sql/sqlite/wd_smw_entity_revisions.sql` sont déclarés via le hook
`LoadExtensionSchemaUpdates`. Exécuter le mécanisme standard
`update.php` pour **chaque wiki** du laboratoire avant d'activer le
nouveau suivi. En absence de table, le mode actif ne peut pas assurer
les lectures mises en cache. Ne pas lancer de migration de production
avant la validation générale.

## 5. Pages HTML complètes (Vector et Minerva)

Un fichier HTML complet peut être envoyé avant
`ArticleViewHeader`. `SemanticPageCacheHooks` oppose donc,
**uniquement en mode opt-in**, un veto temporaire à la lecture et
à l'écriture des fichiers HTML pour les pages contenant des requêtes
sémantiques. Les pages non concernées conservent leur cache de fichiers.

Pour ces pages, un marqueur de révision sémantique est enregistré dans
`ParserOutput` à la fin du traitement PHP. À la lecture du cache du
parseur, le hook `RejectParserCacheValue` compare ce marqueur aux
dépendances actuelles et rejette une version divergente ou non tracée.
Les pages jamais enregistrées dans le suivi doivent initialement être
recalculées.

Le hook `BeforePageDisplay` retire également la possibilité de
mettre en cache publiquement le HTML complet des pages sémantiques
concernées, afin de ne pas laisser un proxy ou CDN conserver des
relations périmées sans consulter le serveur.

**Coût connu :** ce mode n'utilise pas le cache de fichiers HTML
complets pour les débats et arguments concernés. Cette limitation est
délibérée : ce cache ne contient pas encore la nouvelle empreinte, et
le réutiliser permettrait de renvoyer de vieilles relations en attendant
les jobs. Les gains des fragments et le surcoût du suivi sont mesurés
ci-dessous sur des petites pages synthétiques ; les performances
des pages complètes du site restent à quantifier.

## Parcours utilisateur : débat cacheable et argument chargé séparément

Le rendu `#debate:render` produit les **titres et liens** des arguments
pour et contre, pas leurs contenus. Le clic sur un titre est intercepté
par `modules/features/view.ns0.js`, ouvre le mode lecture dans
`modules/reading/core.js`, puis passe par `modules/common/parse.js`.
L'API `action=wikidebatesreading` demande **la page d'argument** :
seul le fragment compris entre `Summary` et `Parent_debates` est
injecté dans l'interface. Le débat ne relance pas `#debate:render`
lorsque son lecteur ouvre un argument.

Le suivi et l'invalidation concernent alors les dépendances SMW de
**l'argument**, tandis que la page de débat sans requête `_ASK`
reste indépendante. Le rendu normal d'un débat ne transclut jamais
`#argument:render` ; les combinaisons artificielles des deux appels
ne représentent qu'un test défensif, pas l'architecture normale.

Le scénario `tests/fullstackSemanticHttp.php` a été étendu à un
vrai `#debate:render` français qui expose les titres « pour » et
« contre », et à un vrai `#argument:render` chargé séparément.
Une relation parentale modifiée, avec purge bloquée, invalide le
fragment de l'argument sans toucher au HTML du débat. Une édition
du résumé de cet argument est visible au prochain chargement par
l'API, sans rendre le débat à nouveau. Le test JS simule également
le clic réel dans `tests/js/parse.test.cjs`.

**Validation :** [6 jobs sur 6, SQLite et MariaDB
(20 novembre 2026)](https://github.com/wikidebates/wikidebates-core/actions/runs/44732408006).

**Cache HTML et laboratoire :** le test compare une même page de débat
avec suivi activé/désactivé. La politique HTTP public/privé ne régresse
pas ; dans ce laboratoire, l'en-tête constaté côté suivi activé
est `private, must-revalidate, max-age=0`. Ce résultat **ne prouve
pas** que la page HTML complète profite d'un cache HTTP public ;
la politique réelle et MinervaFileCache doivent être quantifiés
en préproduction, séparément du cache de `ParserOutput`.

### Cache JavaScript et enregistrement des formulaires

Le code propre à Wikidébats (`modules/features/editForm.js`) prépare
les résumés d'édition avant la soumission de `#pfForm`, sans bloquer
l'envoi normal du formulaire. Dans Page Forms pour MediaWiki 1.43,
`PF_AutoeditAPI::doStore()` redirige après une création ou une
mise à jour réussie. Le navigateur charge donc un **nouveau document**,
avec de nouvelles `Map()` vides pour les deux caches JavaScript :
la prochaine ouverture d'un argument interroge de nouveau le serveur.

Les tests JavaScript vérifient que le formulaire n'est pas intercepté
par Wikidébats et qu'une nouvelle navigation repart sans ancien
fragment ni ancienne réponse API. Ils simulent le changement de
document ; ils ne prouvent pas à eux seuls la configuration exacte
de chaque formulaire installé sur le site.

Page Forms prévoit également des exceptions : « Save and continue »
enregistre en AJAX sans quitter le formulaire ; `#autoedit` peut agir
sans rechargement (sauf option de rechargement) ; les formulaires
contextuels ont leur propre gestion de l'actualisation. Il faut
vérifier quels boutons sont réellement utilisés sur la préproduction,
mais ne pas imposer de révalidation systématique des fragments dans
le parcours standard.

### Limite : caches JavaScript dans un onglet qui reste ouvert

Le navigateur peut réutiliser les réponses de `wkPageParseCache`
(cache LRU de 50 entrées) et les fragments de `wkArgEmbedCache`
sans demander de nouveau le serveur. `wkArgEmbedCache` n'a actuellement
**pas de durée d'expiration**. Ainsi, si un autre contributeur
modifie les relations ou le résumé d'un argument pendant qu'un
lecteur conserve son onglet ouvert, une réouverture locale peut
afficher l'ancienne version : l'empreinte transactionnelle serveur
ne sera pas consultée.

Cela ne remet pas en cause les tests de fraîcheur HTTP **lorsqu'une
requête atteint le serveur**. Une consultation restée ouverte pendant
qu'un autre utilisateur enregistre une modification peut afficher
l'ancienne version jusqu'à une navigation/relecture effective. Ce
compromis est acceptable pour le mode lecture actuel, qui ne promet
pas une synchronisation en temps réel. Il n'est donc pas nécessaire
d'ajouter un TTL ou une révalidation réseau à chaque réouverture.
Les modifications dans un formulaire AJAX intégré à la page, sans
navigation, restent à vérifier séparément sur la préproduction.

### Essai HTTP avec 25 titres d'arguments issus du corpus Wikidébats

Le laboratoire utilise le fichier déjà conservé
`debatephp/tests/fixtures/pages.json` pour extraire les 12 arguments
pour et 13 arguments contre du débat « Dieu existe-t-il ? ». Il
les insère dans un rendu natif `#debate:render` et ouvre séparément
le fragment de l'argument « Dieu est une invention ». Les vérifications
confirment que les titres sont bien présents dans le débat, que le
résumé de l'argument n'y est pas inclus, et qu'une édition de cet
argument invalide son fragment avant purge sans modifier le débat.

Le HTML du débat représente environ 63 Ko. Pour mesurer l'effet
propre du suivi sémantique sur les pages de débat, les essais
préchauffent les deux modes et alternent huit requêtes par mode,
dans un ordre croisé A/B puis B/A :

| Base | Suivi actif (médiane) | Suivi inactif (médiane) | Écart |
| --- | ---: | ---: | ---: |
| SQLite | 8,07 ms | 6,48 ms | +1,59 ms |
| MariaDB | 14,20 ms | 10,67 ms | +3,53 ms |

[Validation complète : 6 jobs / 6, 20 novembre 2026](https://github.com/wikidebates/wikidebates-core/actions/runs/44735081479).

### Profilage ciblé de la vérification des pages de débats

Une instrumentation **temporaire et limitée au laboratoire jetable** a
enregistré les appels exacts à `SemanticPageCacheHooks::isQueryPage()`
sur cinq réponses HTTP répétées du débat de 25 titres, pour chaque base.
Le diagnostic n'était activé que sur ces cinq requêtes et ne faussait
pas les médianes du banc A/B.

| Vérification | SQLite : moyenne | MariaDB : moyenne |
| --- | ---: | ---: |
| Lecture des profils SMW `_ASK` | 0,76 ms | 0,82 ms |
| Chargement et inspection du wikicode | 1,27 ms | 2,08 ms |
| **Classification totale** | **2,31 ms** | **3,10 ms** |

Les cinq réponses ont déclenché chacune **un seul appel** depuis
`HTMLFileCache::useFileCache`, sans requête `_ASK` enregistrée sur
le débat, ni classification positive. Les hooks ultérieurs n'ont pas
refait ce contrôle sur ces réponses servies par le cache de fichier.
Les durées du contrôle comprennent une légère instrumentation : ne
pas les comparer directement comme des benchmarks absolus.

[Journal de profilage : CI 6/6 SQLite et MariaDB](https://github.com/wikidebates/wikidebates-core/actions/runs/44738225385).

**Conclusion :** le coût provient principalement de la lecture du
wikicode pour détecter un appel Argument **avant l'enregistrement de son
premier profil `_ASK`**. Il ne suffit pas de supprimer cette vérification :
cela exposerait des arguments fraîchement créés ou réindexés à un cache
potentiellement périmé. Aucun raccourci de ce type n'est accepté dans
la PR. Le code d'instrumentation temporaire a été retiré après mesure.

**Interprétation prudente :** ces écarts positifs signalent un coût
possible des contrôles activés sur une page qui n'effectue pas de
recherche sémantique. Il faut profiler précisément les accès
`_ASK` et les appels répétés aux hooks, puis quantifier ce coût
sur la préproduction avant de chercher à le supprimer. Ne pas
introduire de raccourci qui ignorerait une vraie requête `_ASK`
ou ferait réutiliser un fichier HTML périmé.

Le corpus apporte des **titres réels**, mais les pages de test et leur
configuration restent synthétiques et indépendantes du site.
Les durées de GitHub Actions ne sont ni des mesures de production
ni un critère de performance absolu. Le suivi expérimental reste
désactivé par défaut ; `__NOCACHE__` a déjà été retiré du modèle Argument
sur les wikis réels.

## Limites exigeant validation

- **Course avec les jobs différés :** les tests utilisent de véritables
  requêtes HTTP servies par plusieurs processus PHP indépendants et vérifient
  les modifications de parent pendant qu'un job de purge est bloqué.
  Les scénarios simultanés avec modifications SQL concurrentes, les caches
  CDN/proxy réels et une éventuelle réplication restent à éprouver.
- **Détection avant le premier profil :** les pages d'arguments utilisant
  le modèle `Argument`, `#argument:render` ou l'ancien
  `#invoke:Argument|render` sont protégées même sans `_ASK`.
  Le seul modèle `Débat` ou `#debate:render` n'implique PAS une
  requête sémantique ; ces pages conservent leurs caches ordinaires.
  Un débat contenant aussi un argument rendu, une requête `#ask`
  enregistrée ou une autre requête tracée est protégé.
  Les modèles intermédiaires et alias non inventoriés restent à étudier.
- **Requêtes non traçables :** les recherches avec comparateurs non
  indexables, certaines requêtes par espace de noms et `limit=0`
  sont exclues du suivi selon la documentation de SMW 7.3.1.
  Énumérer toutes les requêtes utilisées par Débat et Argument avant
  de leur permettre le cache.
- **Cas de panne :** si les jobs échouent ou que les métadonnées
  sémantiques ne sont pas écrites, le système doit refuser les fragments
  risquant de devenir obsolètes. Des essais avec panne forcée de job
  et de base doivent précéder tout déploiement.
- **Extensions de rendu :** les appels HTTP `action=wikidebatesreading`
  rendent `parser` puis `fragment-cache` sur des pages synthétiques.
  Les pages complètes Vector et MinervaFileCache sont contrôlées lors
  d'un job en attente, avec refus de fichier mobile périmé. Reste à
  comparer les vrais modèles Débat/Argument, Cite, les variantes
  complètes de langue et de session, et l'intégration CDN.
- **Suppression et renommage :** les tests isolés couvrent le changement
  d'identité à résultat de même cardinalité et la suppression. Ajouter les
  variantes avec redirections, jobs perdus et les deux wikis.

## Essais automatisés

Le workflow `.github/workflows/native-html.yml` exécute le job
`smw-143` sur **deux bases indépendantes** : SQLite et MariaDB
conteneurisée, isolée du réseau et jointe uniquement par socket Unix.
Chaque laboratoire utilise MediaWiki 1.43.11, SMW 7.3.1 et
SemanticScribunto 2.3.3, puis exécute :

```sh
php -d memory_limit=768M tests/fullstackSemanticCache.php
php -d memory_limit=768M tests/fullstackSemanticHttp.php
```

Ces suites testent les profils de requêtes, le passage de vide à non
vide, les changements de parent, les suppressions, les fragments
et l'absence d'invalidation en l'absence de changement sémantique.
La seconde démarre un serveur HTTP local à trois workers PHP,
vérifie deux processus distincts ou plus à l'aide de connexions
simultanées, les cookies, les redirections, les droits, les pages
complètes Vector et MinervaFileCache et les réponses avant purge.
Elle emploie les scripts SQL de schéma propres à chaque moteur et
n'accède jamais aux bases de données des sites réels.

Les tests rapides et les autres régressions existantes tournent
sur les deux versions de PHP de la CI.

## Mesures HTTP sur pages synthétiques

Le passage GitHub Actions
[44706689452](https://github.com/wikidebates/wikidebates-core/actions/runs/44706689452)
a comparé 15 lectures anonymes HTTP pour chaque scénario, sans imposer
de seuil rigide sur des runners partagés. Les mesures sont des temps de
réponse **complets** (médianes) et non des mesures isolées du SQL :

| Scénario | SQLite | MariaDB |
|---|---:|---:|
| Fragment stable, suivi désactivé | 7,57 ms | 10,51 ms |
| Fragment stable, suivi activé | 10,57 ms | 16,51 ms |
| Rendu PHP forcé, suivi activé | 29,03 ms | 41,85 ms |
| Surcoût observé du suivi sur un hit | +3,00 ms | +6,00 ms |

L'expérience alterne **des séries distinctes** de hits et de
rendus forcés : un rendu forcé peut actualiser `page_touched` et
créer artificiellement un miss au tour suivant. La première analyse
sémantique peut aussi actualiser `page_touched`, d'où la phase de
stabilisation préalable à la mesure. Une empreinte est calculée
sur la base primaire pour éviter de valider une réponse ancienne
sur une éventuelle réplique.

Un autre passage
[44707122137](https://github.com/wikidebates/wikidebates-core/actions/runs/44707122137)
valide également le rendu complet mobile avec MobileFrontend,
MinervaNeue et MinervaFileCache avant exécution des purges
sémantiques. Il vérifie les réponses actuelles et le veto du
fichier HTML mobile obsolète.

**À ne pas extrapoler :** ce sont des petites pages de test en
anglais. Les requêtes imbriquées, longs arbres d'arguments,
habillages réels, files d'attente et effets de Cloudflare peuvent
modifier fortement ce bilan. Une mesure sur les pages de production
reproduites en préproduction, avec suivi des requêtes SQL et taux
de hits, est nécessaire avant migration.

## Inventaire sans modification des pages (outil préparatoire)

**Règle de base :** en production, `__NOCACHE__` concerne les pages
d'arguments, pas les pages de débats. La migration ne doit pas retirer
ni modifier un mot magique sur les débats, mais vérifier que leurs caches
restent utilisables. Les occurrences du mot magique dans le seul
wikicode d'une page ne révèlent pas les mots magiques transmis par un modèle
transclus ; les résultats d'inventaire ne doivent pas être interprétés
comme un relevé exhaustif du comportement effectif des pages.

Le script `maintenance/AuditSemanticCacheSources.php` lit les dernières
révisions et les profils `_ASK` sans lancer de rendu, de sauvegarde, de job,
de purge ou de migration. Les sorties JSONL donnent les titres, tailles et
indicateurs ; **aucun wikicode ni contenu privé n'est exporté**.

Exemples à exécuter **uniquement sur une préproduction isolée** depuis la
racine MediaWiki 1.43 :

```sh
php maintenance/run.php Wikidebates:AuditSemanticCacheSources --wiki=fr --limit=200
php maintenance/run.php Wikidebates:AuditSemanticCacheSources --wiki=en --limit=200
```

Reprendre avec `--after=ID` (valeur `resume_after` du dernier résumé),
et adapter `--namespaces=0,10` ou `--ids=123,456` pour des sondages ciblés.
L'option `--limit` est bornée à 5000 pages par invocation afin de ne pas
lancer accidentellement une analyse sans limite sur une grosse base.

Pour obtenir une estimation **en lecture seule** du coût du classement
sémantique des pages, ajouter `--profile-reads` sur un lot réduit,
par exemple :

```sh
php maintenance/run.php Wikidebates:AuditSemanticCacheSources --wiki=fr --limit=25 --profile-reads
php maintenance/run.php Wikidebates:AuditSemanticCacheSources --wiki=en --limit=25 --profile-reads
```

L'option ajoute `semantic_check_ms` aux pages signalées et
`semantic_check_timings` dans le résumé (arguments, débats, autres).
Elle appelle le contrôle sur la page **avant de lire son contenu pour
l'inventaire**, mais augmente le nombre de lectures SQL. Ne pas
l'exécuter sans limite ni sur la production : une mesure CLI n'est
pas directement comparable à une requête HTTP publique, et les
caches/connexions propres à la préproduction influencent le résultat.

Le rapport distingue `argument_reader` (requêtes parentes réelles)
et `debate_renderer` (écriture sémantique, sans requête de parent).
`unprofiled_argument_reader` relève les arguments sans `_ASK`
qu'il faudra examiner avant toute migration de `__NOCACHE__`.
`debate_only_without_profiles` est au contraire un état **normal** :
ne pas traiter ces débats comme des erreurs ni désactiver leurs caches.
`nocache_in_debate_only_source` signale un mot magique écrit
explicitement dans le texte source d'un débat, à vérifier sans supposer
que la transclusion est représentative. `profile_without_direct_reader`
peut provenir d'une inclusion indirecte, d'une autre extension ou
d'une ancienne annotation : ce n'est pas une preuve à lui seul.
Les alias et modèles intermédiaires restent à étudier manuellement.

## Vérifications manuelles EN PRÉPRODUCTION UNIQUEMENT

1. Reproduire la configuration MediaWiki 1.43 / SMW 7.3.1 et
   les modèles d'Argument / Débat, avec une copie **non publique,
   nettoyée** de la structure sémantique si nécessaire.
2. Exécuter **uniquement en laboratoire / préproduction** la migration
   des tables MediaWiki, par exemple
   `php maintenance/run.php update --wiki=fr`, puis recommencer pour
   `--wiki=en` si les deux wikis sont présents. Vérifier la table
   `wd_smw_entity_revisions`. Ensuite seulement, activer
   `$wgWikidebatesSemanticCacheTracking = true;` sur les wikis pilotes,
   **sans retirer `__NOCACHE__`**.
3. Recalculer les annotations `_ASK` des pages pilotes en utilisant
   les moyens de mise à jour SMW adaptés au site, puis contrôler
   `smw_fpt_ask` et `smw_query_links`.
4. Contrôler le cache du parseur, les fragments, le cache HTML Vector
   et MinervaFileCache à chaque ajout, déplacement, suppression,
   renommage et modification de propriété.
5. Vérifier que deux lectures anonymes identiques après une purge
   peuvent servir `parser` puis `fragment-cache`, et qu'une mise
   à jour distante est visible sans attendre le TTL ; si ce n'est
   pas le cas, garder `__NOCACHE__`.
6. Tester les révisions supprimées, les pages non lisibles, les
   cookies et utilisateurs authentifiés, ainsi que les wikis
   `fr` et `en` indépendamment.

## Migration des mots magiques

Le fichier source `debatephp/resources/Template-Argument.php.wikitext`
ne contient pas `__NOCACHE__`. Son insertion est donc à identifier
dans le wikicode effectivement stocké sur le site (modèle, sous-modèle,
système de pages) avant toute opération. Ne pas entreprendre de
remplacement global sans cette vérification.

Après une validation isolée et un pilote concluant : supprimer le
mot magique sur un petit ensemble d'arguments, reconstruire les
profils SMW et purger toutes les couches HTML correspondantes, puis
rejouer les cas de régression et comparer les réponses dans chaque
langue. Ne jamais confondre déploiement du code (mode désactivé)
et activation de cette migration.


## Mesures et retour arrière

Le recalcul de l'empreinte sémantique nécessite une lecture des liens SMW.
Comparer en préproduction les latences des pages de débats volumineuses,
le temps des requêtes SQL et la fréquence des caches réellement utilisés.
Le contrôle renforcé des fichiers HTML ne doit viser que les pages qui
exécutent des requêtes sémantiques. Mesurer séparément les taux de hits
des débats ordinaires (qui doivent rester cacheables) et des arguments
suivis avant de proposer de supprimer `__NOCACHE__`.

En cas de régression, remettre `$wgWikidebatesSemanticCacheTracking = false;`,
puis purger les caches du parseur, les fragments, les fichiers HTML et tout
cache HTTP/CDN concerné. La table peut rester installée, inutilisée. Ne pas
supprimer la table ni ses données alors qu'un wiki utilise encore le suivi.
