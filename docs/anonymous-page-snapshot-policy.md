# Caches HTML anonymes : invalidation attachee a la page

## Regle

Les resultats de recherches Semantic MediaWiki sont un **instantane HTML** au
moment ou la page est rendue. Une modification des donnees semantiques ailleurs
ne doit declencher ni invalidation, ni reconstruction anticipee des caches
Vector, Minerva ou Lecture de cette page.

L'edition directe de la page continue de declencher les invalidations normales
de MediaWiki, puis la preparation anticipee des fichiers si elle est activee.
Les droits de lecture, la suppression, le masquage du contenu d'une revision
et les changements d'identite de la page restent controles pour qu'un
HTML devenu prive ne soit jamais diffuse.

Aucun nouveau parametre LocalSettings.php n'est ajoute. La ferme conserve ses
trois caches et les repertoires deja configures.

## Ce qui a ete retire

- Le veto Wikidebates contre le cache natif Vector sur une page executant SMW.
- Les empreintes de dependances SMW dans ParserOutput.
- L'invalidation indirecte des pages proprietaires de requetes SMW.
- La verification semantique supplementaire du fragment Lecture.
- Le profileur _ASK de la couche Wikidebates : la requete continue a utiliser
  le moteur SemanticScribunto natif avec ses parametres d'origine.

Les anciens jobs `wikidebatesSemanticCachePurge` deviennent des operations
sans effet et sont acquittes sans lancer de purge. Le type reste enregistre
pour assurer la compatibilite avec des jobs deja enregistres. Les anciens
enregistrements de prechauffage schema 3 restent consultables, mais le
traitement standard ne prepare que les demandes d'edition directe.

Les parametres historiques du suivi semantique peuvent exister encore
dans la configuration d'une ferme deja installee ; ils ne commandent plus
le fonctionnement de ces trois caches.

## Exceptions et limites explicites

**Aucune duree de vie maximale par defaut pour Vector, Minerva et Lecture.**
Le cache Vector natif de MediaWiki 1.43 suit `page_touched` et
`CacheEpoch`. Les caches Minerva et Lecture ne comportent plus de
plafond de 30 jours : seul un TTL explicitement choisi peut les faire expirer.

```php
// Facultatif : laisser ces parametres absents pour conserver les fichiers sans TTL.
$wgMinervaFileCacheTTL = 86400 * 90;
$wgWikidebatesReadingCacheTTL = 86400 * 90;
```

Les deux parametres existent deja ; leur valeur par defaut est **0**.
Le TTL, lorsqu'il est positif, s'applique a la duree du fichier lui-meme,
independamment du delai d'expiration du ParserOutput utilise lors du rendu.
Les anciens fichiers expirant apres 30 jours sont conserves si leur identite
et leurs permissions restent valides.

Les modifications de modele, les operations de maintenance, une purge
administrative ou une modification des droits peuvent encore affecter
l'identite du cache ou la securite. Ces comportements natifs restent distincts
des invalidations semantiques indirectes retirees.

## Verification du suivi natif de SMW

Le parametre existant `$smwgEnabledQueryDependencyLinksStore` de SMW est
encore active sur la ferme. SMW peut donc continuer a gerer une invalidation
de son propre ParserCache lors d'un changement de dependance, meme si les
hooks de Wikidebates ont ete retires. Une lecture dans le fichier HTML
Vector ou Minerva contourne normalement ce reparsing, mais la garantie
ne doit pas etre deduite du seul code de l'extension.

Le test d'integration doit verifier l'absence de mise a jour indirecte de
`page_touched` et la conservation des trois fichiers. Dans un deuxieme
scenario isole, comparer les requetes SQL et le comportement avec le
suivi natif de dependances SMW desactive. **Aucun changement de
LocalSettings.php n'est effectue par cette PR.**

Les controles de confidentialite restent limites au **contenu de la page
elle-meme**, a ses revisions masquees et aux droits de lecture. Les resultats
SMW publics inclus dans le rendu sont des instantanes ordinaires, sans
purge de pages dependantes.

## Validation sans risque

- Test unitaire : `php tests/readingFragments/semanticPurgesRetired.php`
  (anciens jobs innocents).
- Test unitaire : `php tests/readingFragments/semanticQuerySnapshot.php`
  (rendu SMW natif).
- Tests de coordination : `php tests/readingFragments/coordinatedPreparation.php`
  (la file ignore les anciens evenements indirects).
- Laboratoire jetable MediaWiki 1.43.11 / SMW 7.3.1 :
  `php tests/fullstackPageOwnedCache.php`, avec `MW_INSTALL_PATH` vers
  l'installation isolee. Ne jamais executer contre la production.
- HTTP : verifier un premier rendu puis un HIT Vector/Minerva/Lecture ;
  modifier seulement un debat parent et verifier que les trois HTML
  restent identiques ; modifier la page proprietaire et verifier les
  invalidations ; controler les comptes connectes, les droits et RevDel.
- Mesurer latence, CPU, SQL et taux de HIT avant un deploiement progressif.

La presente modification ne modifie pas LocalSettings.php, n'installe pas
de cron, ne purge aucun cache et n'efface aucune donnee SQL.
