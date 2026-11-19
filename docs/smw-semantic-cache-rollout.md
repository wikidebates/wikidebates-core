# Migration prudente des caches sémantiques (MediaWiki 1.43 / SMW 7.3.1)

> **Statut : expérimental, DÉSACTIVÉ PAR DÉFAUT.** Ne pas retirer
> \`__NOCACHE__\` ni fusionner cette PR sans une suite SMW complète réussie
> sur un wiki jetable et un pilote sur environnement de préproduction.
> La configuration de production actuelle doit rester inchangée.

## Architecture expérimentale

### 1. Lecture sémantique PHP traçable

Le chemin historique \`LibraryFactory::newQueryResultFrom()\` de
SemanticScribunto 2.3.3 crée une requête sans page d'origine. Lorsque
\`WikidebatesSemanticCacheTracking\` est activé, \`TrackedSemanticQuery\`
reproduit sa construction de requête (\`QueryContext::SPECIAL_PAGE\`,
\`addThisPrintout\`) en passant un \`contextPage\` à SMW 7.3.1.
Le traitement du résultat reste assuré par le convertisseur
SemanticScribunto existant : aucun nouveau moteur Lua ni changement
intentionnel du format de réponse.

Les profils de requêtes \`_ASK\` sont écrits dans le \`ParserOutput\`
à l'aide de \`ParserData\` et du \`ProfileAnnotatorFactory\` de SMW.
Ces profils permettent à SMW de retrouver les requêtes attachées à
une page. Si le contexte ne permet pas une traçabilité sûre, le rendu
est marqué non cacheable : ne jamais publier un résultat inobservable.

### 2. Après modification sémantique

Le hook \`SMW::SQLStore::AfterDataUpdateComplete\` récupère les ID
d'entités réellement modifiées à partir de \`ChangeOp\` (valeurs
créées, supprimées et propriétés). Un travail différé, réalisé après
l'opération de stockage, envoie des jobs ciblés interrogeant les
dépendances \`smw_query_links\`. Les jobs parcourent les IDs des
requêtes en lots et purgent leurs pages propriétaires via MediaWiki.

Une purge MediaWiki déclenche les mécanismes de cache du parseur,
les fichiers HTML ordinaires et \`TitleSquidURLs\`. Le hook Wikidebates
révoque les générations des fragments ; MinervaFileCache utilise
également \`TitleSquidURLs\` pour invalider ses fichiers.

### 3. Contrôle avant lecture des fragments

Avant un \`fragment-cache\` hit, l'API de lecture utilise la validation
des dépendances de SMW, car elle n'exécute pas le hook
\`ArticleViewHeader\` lors d'un hit. En présence d'une dépendance
devenue archaïque, elle purge le titre et retombe sur le rendu normal.

Les arguments dépourvus de profil \`_ASK\` ne sont pas admis dans le
cache de fragments au cours de la migration. Le nouveau marqueur
\`semanticTracking\` de leur identité empêche le réemploi des anciens
fragments après basculement de l'option. Les vérifications et erreurs
de permission restent exécutées AVANT toute lecture de fragment.

## Limites exigeant validation

- **Course avec les jobs différés :** entre une écriture SMW réussie
  et le job de purge, la vérification native SMW protège certains hits,
  mais il faut démontrer les cas de requêtes initialement vides, ainsi
  que les modifications dans la même seconde (\`smw_touched\`).
  Une garantie d'invalidation immédiatement synchronisée n'est pas
  encore établie.
- **Requêtes non traçables :** les recherches avec comparateurs non
  indexables, certaines requêtes par espace de noms et \`limit=0\`
  sont exclues du suivi selon la documentation de SMW 7.3.1.
  Énumérer toutes les requêtes utilisées par Débat et Argument avant
  de leur permettre le cache.
- **Cas de panne :** si les jobs échouent ou que les métadonnées
  sémantiques ne sont pas écrites, le système doit refuser les fragments
  risquant de devenir obsolètes. Des essais avec panne forcée de job
  et de base doivent précéder tout déploiement.
- **Extensions de rendu :** vérifier un vrai \`action=wikidebatesreading\`
  avec \`source=fragment-cache\` après un premier rendu ; contrôler
  les trois sources HTML, les habillages, les langues et les utilisateurs.
- **Suppression et renommage :** les tests doivent contrôler les titres
  anciens et nouveaux en présence d'une mise à jour SMW différée.

## Essais automatisés

Le workflow \`.github/workflows/native-html.yml\` contient un job
\`smw-143\` qui installe un MediaWiki 1.43.11 jetable, SMW 7.3.1
et SemanticScribunto 2.3.3, puis exécute :

```sh
php -d memory_limit=768M tests/fullstackSemanticCache.php
```

La suite teste les profils de requêtes, le passage de vide à non
vide, les changements de parent, les suppressions, les fragments
et l'absence d'invalidation en l'absence de changement sémantique.
Elle n'emploie jamais la base de données des sites réels.

Les tests rapides et les autres régressions existantes tournent
sur les deux versions de PHP de la CI.

## Vérifications manuelles EN PRÉPRODUCTION UNIQUEMENT

1. Reproduire la configuration MediaWiki 1.43 / SMW 7.3.1 et
   les modèles d'Argument / Débat, avec une copie **non publique,
   nettoyée** de la structure sémantique si nécessaire.
2. Sur cet environnement, mettre \`$wgWikidebatesSemanticCacheTracking = true;\`
   dans les paramètres du wiki, en gardant \`__NOCACHE__\` tant que
   l'essai n'a pas été jugé concluant.
3. Recalculer les annotations \`_ASK\` des pages pilotes en utilisant
   les moyens de mise à jour SMW adaptés au site, puis contrôler
   \`smw_fpt_ask\` et \`smw_query_links\`.
4. Contrôler le cache du parseur, les fragments, le cache HTML Vector
   et MinervaFileCache à chaque ajout, déplacement, suppression,
   renommage et modification de propriété.
5. Vérifier que deux lectures anonymes identiques après une purge
   peuvent servir \`parser\` puis \`fragment-cache\`, et qu'une mise
   à jour distante est visible sans attendre le TTL ; si ce n'est
   pas le cas, garder \`__NOCACHE__\`.
6. Tester les révisions supprimées, les pages non lisibles, les
   cookies et utilisateurs authentifiés, ainsi que les wikis
   \`fr\` et \`en\` indépendamment.

## Migration des mots magiques

Le fichier source \`debatephp/resources/Template-Argument.php.wikitext\`
ne contient pas \`__NOCACHE__\`. Son insertion est donc à identifier
dans le wikicode effectivement stocké sur le site (modèle, sous-modèle,
système de pages) avant toute opération. Ne pas entreprendre de
remplacement global sans cette vérification.

Après une validation isolée et un pilote concluant : supprimer le
mot magique sur un petit ensemble d'arguments, reconstruire les
profils SMW et purger toutes les couches HTML correspondantes, puis
rejouer les cas de régression et comparer les réponses dans chaque
langue. Ne jamais confondre déploiement du code (mode désactivé)
et activation de cette migration.
