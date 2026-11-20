# Migration prudente des caches sémantiques (MediaWiki 1.43 / SMW 7.3.1)

> **Statut : expérimental, DÉSACTIVÉ PAR DÉFAUT.** Les suites SMW
> SQLite et MariaDB sont passées, mais ne pas retirer `__NOCACHE__`
> ni fusionner cette PR sans essais HTTP multiprocessus, mesures de
> performances et pilote de préproduction. La production reste inchangée.

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

Avant un `fragment-cache` hit, l'API de lecture utilise la validation
des dépendances de SMW, car elle n'exécute pas le hook
`ArticleViewHeader` lors d'un hit. En présence d'une dépendance
devenue archaïque, elle purge le titre et retombe sur le rendu normal.

Les arguments **et les débats** dépourvus de profil `_ASK` ne sont pas
admis dans le cache partagé au cours de la migration. Les marqueurs
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
les jobs. Un cache complet versionné pourra être étudié après la
validation HTTP. Le gain de performance escompté dépend du cache du
parseur et des fragments ; il n'est pas encore mesuré.

## Limites exigeant validation

- **Course avec les jobs différés :** le laboratoire couvre les requêtes
  initialement vides et les modifications réalisées alors qu'un job de purge
  est volontairement bloqué. Il reste à vérifier par de véritables requêtes
  HTTP multiprocessus et avec les caches CDN/proxy que cette protection
  conserve sa fraîcheur en situation de concurrence.
- **Requêtes non traçables :** les recherches avec comparateurs non
  indexables, certaines requêtes par espace de noms et `limit=0`
  sont exclues du suivi selon la documentation de SMW 7.3.1.
  Énumérer toutes les requêtes utilisées par Débat et Argument avant
  de leur permettre le cache.
- **Cas de panne :** si les jobs échouent ou que les métadonnées
  sémantiques ne sont pas écrites, le système doit refuser les fragments
  risquant de devenir obsolètes. Des essais avec panne forcée de job
  et de base doivent précéder tout déploiement.
- **Extensions de rendu :** vérifier un vrai `action=wikidebatesreading`
  avec `source=fragment-cache` après un premier rendu ; contrôler
  les trois sources HTML, les habillages, les langues et les utilisateurs.
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
```

La suite teste les profils de requêtes, le passage de vide à non
vide, les changements de parent, les suppressions, les fragments
et l'absence d'invalidation en l'absence de changement sémantique.
Elle emploie les scripts SQL de schéma propres à chaque moteur et
n'accède jamais aux bases de données des sites réels.

Les tests rapides et les autres régressions existantes tournent
sur les deux versions de PHP de la CI.

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
Le contrôle renforcé des fichiers HTML peut réduire temporairement leur taux
de hits ; mesurer ce coût avant de proposer de supprimer `__NOCACHE__`.

En cas de régression, remettre `$wgWikidebatesSemanticCacheTracking = false;`,
puis purger les caches du parseur, les fragments, les fichiers HTML et tout
cache HTTP/CDN concerné. La table peut rester installée, inutilisée. Ne pas
supprimer la table ni ses données alors qu'un wiki utilise encore le suivi.
