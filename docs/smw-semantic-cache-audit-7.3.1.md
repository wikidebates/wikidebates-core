# Audit des caches sémantiques (SMW 7.3.1, MediaWiki 1.43)

> **Statut : diagnostic confirmé par lecture du code, correctif non livré.**
> Ne retirer **aucun** `__NOCACHE__` en production sur la base de ce document.
> Ne pas activer le cache de résultats sémantiques avant validation en wiki jetable.

## 1. Chaîne de requêtes effective

Dans `debatephp/src/MediaWikiContext.php::semanticQuery()`, les lectures
passent par `SMW\Scribunto\LibraryFactory::newQueryResultFrom()`.
Dans SemanticScribunto, `src/LibraryFactory.php` (version examinée sur sa
branche `master`), la méthode construit la requête avec
`QueryProcessor::createQuery(..., QueryContext::INLINE_QUERY, ..., $printouts)`,
**sans** dernier argument `$contextPage`.

Dans SMW **7.3.1**, `QueryDependencyLinksStore::canUpdateDependencies()`
refuse explicitement toute requête pour laquelle
`$query->getContextPage() === null`. De ce fait, ces appels natifs ne
permettent **pas**, en l'état, l'enregistrement des liens de dépendance
pour leur page appelante. Cela vaut aussi pour une requête initialement vide.

Attention : l'identité de la page n'est pas toute l'histoire. Dans
`src/ParserFunctions/AskParserFunction.php::addQueryProfile()`, SMW ajoute
un profil de requête à la donnée sémantique de la page via
`ProfileAnnotator::pushAnnotationsTo()`, ce qui permet notamment de
retrouver les requêtes embarquées par la propriété interne `_ASK`.
Un simple `setContextPage()` sur le résultat de `LibraryFactory`
ne reproduit **pas** automatiquement ces annotations.

La configuration `$smwgQueryProfiler = 0` doit être évaluée par les
comparaisons exactes de SMW : `=== false` désactive réellement les
profils ; `0 === false` est faux. La sémantique des options de détail
doit également être vérifiée sur l'installation.

## 2. Validation et invalidation SMW

Dans SMW 7.3.1 :

- `AfterQueryResultLookupComplete` appelle
  `QueryDependencyLinksStore::updateDependencies()`.
- Ce dernier dépend de `getContextPage()`, puis résout et enregistre
  des liens dans `smw_query_links` de manière différée.
- `DependencyLinksValidator::hasArchaicDependencies()` cherche les
  requêtes de la page via `_ASK`, puis compare leur date sémantique
  à celle des éléments suivis dans `smw_query_links`.
- `DependencyValidator` participe au rejet du cache du parseur ; cette
  vérification intervient dans le pipeline normal MediaWiki et **pas**
  lorsque Wikidebates renvoie directement un fragment déjà stocké.

Conséquence : la piste native est intéressante, mais elle nécessite
démonstration de l'écriture du profil, de la détection des requêtes vides,
de l'ordre des mises à jour et de la purge de toutes les couches HTML.

## 3. Caches HTML de Wikidebates

`src/Reading/FragmentService.php::read()` vérifie d'abord le cache privé
de fragments. Une entrée trouvée est renvoyée avec
`source = fragment-cache` sans demander le `ParserOutput`.
L'identité du fragment comprend `pageid`, révision, `page_touched`,
epochs et variante de rendu ; **aucun numéro de version sémantique
indépendant n'est inclus**.

`FragmentStore::invalidate()` avance une génération et empêche une
publication concurrente devenue obsolète. Cela protège **si et seulement
si** l'invalidation de la page dépendante est réellement appelée.

`ReadingHooks::onTitleSquidURLs()` invalide le fragment du titre purgé ;
une modification d'une autre page ne garantit pas à elle seule que ce hook
sera invoqué pour chaque page qui lui est liée par une requête sémantique.

`FileSources.php` exige une entrée de cache du parseur encore valide
avant de lire le cache HTML Vector ou Minerva. En revanche, une entrée
déjà dans le cache des fragments est servie avant cette vérification.

## 4. Critères d'acceptation obligatoires

Effectuer ces tests dans un MediaWiki 1.43 + SMW 7.3.1 + SemanticScribunto
et Wikidebates, **avec une base de données jetable** :

1. Vérifier que le rendu PHP inscrit les métadonnées `_ASK` sur la
   page appelante, puis les liens correspondants dans `smw_query_links`
   après la fin des mises à jour différées.
2. Vérifier qu'une requête sans résultat conserve un lien vers les
   propriétés/conditions nécessaires pour détecter l'apparition
   d'un premier résultat.
3. Tester création, suppression, déplacement d'argument, changement
   de parent, justifications et objections, renommage, suppression,
   et modification d'une propriété d'un autre sujet.
4. Contrôler le cache du parseur et la **génération** du cache des
   fragments avant/après chaque opération ; contrôler aussi les
   sources HTML Vector et Minerva.
5. Vérifier l'ordre des jobs et la lecture pendant que la mise à jour
   SMW est en attente ; ne pas servir d'HTML ancien dans cet intervalle.
6. Vérifier les protections de lecture, sessions connectées, révisions
   masquées, langues/variantes et séparation des wikis.
7. Sans changement, deux appels anonymes API successifs doivent
   produire `parser` (ou une source HTML autorisée), puis
   `fragment-cache` ; après changement distant, la réponse doit
   refléter les nouvelles relations, sans attendre le TTL.
8. Simuler un échec d'écriture du suivi de dépendances et un job échoué :
   le système doit échouer de façon prudente, sans publier d'HTML
   potentiellement périmé.

## 5. Architecture candidate (non encore implémentée)

- Réutiliser le parseur et l'enregistrement natif des profils SMW pour
  les requêtes PHP en conservant leur format de résultat. Reprendre la
  logique de `#ask` sans réintroduire Lua.
- Ne publier les fragments de pages dépendantes que lorsqu'un mécanisme
  explicite assure la fraîcheur de la dépendance **avant le hit** du
  cache de fragments. Le contrôle natif du cache du parseur, seul,
  ne suffit pas. L'invalidation sur évènement doit toucher
  `page_touched`, le cache du parseur, les fragments, le cache
  HTML natif et Minerva au bon moment.
- Prévoir un état de sécurité ou une non-cacheabilité lorsqu'un suivi
  de dépendances manque ou que la mise à jour SMW est différée.
- Préférer les primitives d'invalidation de MediaWiki/SMW à
  un second graphe propriétaire, sous réserve de tests de couverture
  pour créations et requêtes vides.

## 6. Conditions avant migration

**Aucune suppression de `__NOCACHE__`** et aucune modification de
`LocalSettings.php` dans cette étape d'audit. Avant la migration,
identifier le modèle/wikicode qui insère le mot magique, instrumenter
un petit lot pilote dans un environnement jetable, invalider les
anciennes entrées de cache et vérifier chaque famille de relations.

## Sources examinées

- Wikidebates `main` :
  `debatephp/src/MediaWikiContext.php`,
  `src/Reading/FragmentService.php`, `FragmentStore.php`,
  `ReadingHooks.php`, `FileSources.php`, `WarmFragmentsJob.php`,
  `extension.json`.
- SemanticMediaWiki **tag 7.3.1** :
  `src/ParserFunctions/AskParserFunction.php`,
  `src/SQLStore/QueryDependency/QueryDependencyLinksStore.php`,
  `src/SQLStore/QueryDependency/DependencyLinksValidator.php`,
  `src/MediaWiki/Hooks/AfterQueryResultLookupComplete.php`,
  `src/DependencyValidator.php`,
  `src/Query/QueryProcessor.php`.
- SemanticScribunto : `src/LibraryFactory.php` sur `master`.
  **À compléter :** contrôler le commit réellement installé de
  SemanticScribunto (2.3.3 selon le chemin de code documenté), avant
  de modifier la méthode.
