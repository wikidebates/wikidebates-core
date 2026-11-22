# Coordination expérimentale des trois caches anonymes

**Statut : développement et validation en laboratoire. Ne pas activer en production.**

La commande `Wikidebates:preparePendingCaches` travaille sur les pages
présentes dans la file persistante : éditions directes et, lorsque les
options SMW sont explicitement actives, dépendances sémantiques invalidées.

Elle prépare successivement, par `page_id` :

1. `Wikidebates:prepareVectorViewCache` (vrai fichier natif `action=view`, aucun historique).
2. `MinervaFileCache:prewarm` (un seul GET anonyme, contrôles HTTP complets).
3. `Wikidebates:prepareReadingCache` (un fragment si la page appartient à la catégorie Arguments).

## Protection contre les mises à jour sémantiques sans nouvelle révision

Les anciens enregistrements de file (`schema: 1`) ne connaissaient que
`pageId` et `revisionId`. Or un changement de débat parent via SMW peut
modifier le HTML d'un argument sans modifier sa révision MediaWiki.

Les enregistrements d'éditions directes (`schema: 2`) contiennent un identifiant
`generation` aléatoire, renouvelé à chaque demande. Un worker ne peut confirmer
que le triplet `(pageId, revisionId, generation)` qui lui a été remis.
Si une modification directe ou indirecte arrive pendant le rendu, la file
conserve le nouvel événement et le worker devra recommencer. L'écriture et
l'acquittement restent protégés par le verrou exclusif de cette page.

La méthode `CachePreparationQueue::enqueueInvalidation()` renouvelle le
marqueur même à révision inchangée, tout en conservant la révision la
plus récente connue.

Lorsque **les trois options** `WikidebatesCachePreparationDirectory`,
`WikidebatesSemanticCacheTracking` et
`WikidebatesSemanticCacheEagerPurge` sont activées, le job
`SemanticCachePurgeJob` enregistre les pages dépendantes uniquement après
l'invalidation HTML/CDN réussie et avant la certification des reçus de
purge. Une panne d'écriture du journal fait échouer le job de manière
réessayable. Les purges déjà coalescées ne créent pas de doublons.

Ces fonctionnalités sont **désactivées par défaut** et ne changent pas
la validité, la sécurité, les droits de lecture ni la durée de rétention
des caches. Les anciens fichiers `schema: 1` restent lisibles.

### Barrière post-commit sur `page_touched`

MediaWiki 1.43 valide d'abord le job puis traite les écritures différées
`DeferredUpdates::PRESEND` de `Title::invalidateCache()`.
Une autre commande de maintenance pourrait lire la file pendant cet intervalle.

Les demandes **sémantiques** utilisent désormais `schema: 3` avec :
- `touchAfter` : horodatage UTC strictement postérieur à la valeur
	initiale de `page_touched` et à la seconde d'ordonnancement ;
- `ready: false` : aucun rendu ni acquittement possible, même si
	le numéro de révision est inchangé ;
- le même `generation` jusqu'à confirmation explicite.

Un callback `PRESEND`, placé après les mises à jour
`Title::invalidateCache` du job, relit `page_touched` depuis la
**base primaire**, contrôle le numéro de révision, réexécute la purge HTML
afin de fermer la fenêtre de création de fichiers, puis marque **seulement
cette génération** prête. Les reçus de coalescence sont enregistrés après
cette confirmation et la dernière purge. Une erreur ou une suppression
laisse l'entrée en attente : la file ne doit jamais la considérer comme
préparée. Les générations devenues obsolètes ne peuvent pas être rouvertes.

Le diagnostic `Wikidebates:inspectCachePreparationQueue --json`
indique le nombre `awaiting_deferred_touch`. Une entrée
durablement bloquée ne doit **jamais** être forcée à `ready: true`
manuellement : son `page_touched` seul ne prouve pas que
les fichiers recréés entre les purges ont été éliminés.

### Reprise manuelle d'une demande bloquée (laboratoires)

La commande **sans** `--execute` effectue un aperçu strictement
en lecture seule, par exemple :

```sh
php maintenance/run.php Wikidebates:recoverSemanticTouch --wiki=fr --limit=20 --json
php maintenance/run.php Wikidebates:recoverSemanticTouch --wiki=en --page-id=42 --json
```

Une demande âgée d'au moins 600 secondes peut être réessayée **une seule
page à la fois**, en connaissance de cause :

```sh
php maintenance/run.php Wikidebates:recoverSemanticTouch \
	--wiki=fr --page-id=42 --min-age-seconds=600 --execute --json
```

Préconditions : dossier de file privé configuré, suivi SMW et purge
sémantique activés dans un environnement de test, aucun job de purge
sémantique dans la file en attente, site non placé en mode lecture seule.
Le verrou global de préparation par wiki évite de lancer deux
opérateurs/préchauffeurs concurrents. **Aucun cron n'est installé.**

La reprise vérifie la révision et l'identité en base primaire, ainsi
que le droit de lecture anonyme, puis remplace la génération bloquée
par une nouvelle génération **toujours bloquée**, de manière atomique.
Elle réinvalide le fragment de lecture, le cache Parser et le HTML,
programme un nouveau `Title::invalidateCache`, exécute
les écritures différées puis refait une purge HTML sous provenance SMW.
Le callback contrôle à nouveau la révision, le titre courant, l'accès
anonyme et `page_touched` avant d'ouvrir la nouvelle génération.

Si une page a été supprimée, masquée, déplacée, rendue non publique,
éditée entre-temps ou si une purge/une écriture échoue, l'opération
n'acquitte aucun enregistrement. Une nouvelle tentative explicite
peut repartir d'une entrée encore bloquée. Les erreurs renvoyées
sont limitées au type de l'exception, sans titre ou contenu privé.

Cette procédure ne prépare pas les caches : elle sécurise uniquement
la remise en attente *éligible* d'une page. Le préchauffage des trois
caches, la politique des contenus dynamiques, les permissions et la
reprise automatique ne sont toujours pas autorisés en production.

Ce verrou ne remplace ni l'attente de fin des jobs SMW, ni les contrôles
de confidentialité, ni la politique d'invalidation des pages dynamiques.
Aucun cron n'est installé ; le mode `--execute` reste réservé aux
laboratoires tant que ces contrôles complémentaires ne sont pas validés.

Une préparation partielle **n'efface pas la demande**. Une interruption reprend
donc la même page ; les sous-caches déjà valides sont réutilisés. La file ne
retire une entrée qu'après confirmation explicite des trois étapes et
nouvelle vérification du `page_latest` dans la base primaire.

## Contrôle de visibilité des caches anonymes

Une révision peut être **masquée** par RevisionDelete, un wiki peut retirer
l'autorisation de lecture aux anonymes, ou une page peut changer de nom ou de
`page_touched` sans modifier son `page_latest`.

Le préchauffage coordonné utilise maintenant
`AnonymousCacheSafety::fingerprint()`, qui lit **la base primaire**
avant, entre et après chaque préparation Vector, Minerva et Lecture.
Il vérifie simultanément :
- l'existence et l'identité de la page (`page_id`, espace de noms,
	titre, révision, `page_touched`) ;
- l'existence de la ligne de révision correspondante et
	`rev_deleted = 0` ; un masquage du texte **ou de ses métadonnées**
	interdit de produire du HTML partagé ;
- le droit `read` de l'utilisateur anonyme, avec les contrôles
	de permissions normaux et sécurisés de MediaWiki.

La restriction `edit` d'une page ne la rend **pas** privée :
une page protégée contre l'édition reste éligible si sa lecture est
toujours publique.

Si ces contrôles échouent ou si l'identité publique change pendant
une phase, la page reste dans la file ; le coordinateur n'enchaîne
pas les autres rendus et ne l'acquitte pas. La commande Vector
vérifie en plus ces conditions **avant toute écriture HTML** et
après publication ; le mécanisme de récupération des barrières
différées refuse également les révisions masquées.

Ces garde-fous ne remplacent **jamais** les autorisations vérifiées
lors de chaque requête web, ni les purges normalement déclenchées
par MediaWiki lors d'une suppression, restriction ou RevisionDelete.
La validité d'un fichier déjà publié et les cas de course extrêmes
avec changement de droits doivent rester testés avant activation
des tâches nocturnes. Aucune politique de TTL n'est modifiée.

## Mode aperçu, sans aucun rendu

La commande sans `--execute` affiche simplement la file :

```sh
php maintenance/run.php Wikidebates:preparePendingCaches --wiki=fr --limit=10 --json
php maintenance/run.php Wikidebates:preparePendingCaches --wiki=en --limit=10 --json
```

La file doit être activée au préalable dans un **répertoire privé hors du web** :

```php
$wgWikidebatesCachePreparationDirectory = '/chemin/prive/cache-preparation';
```

Par défaut la configuration vaut `false` ; la commande refuse alors de
toucher au journal. Le mode aperçu ne lance ni processus fils ni requêtes HTTP,
ne produit aucun HTML et ne confirme aucune entrée.

## Exécution manuelle (réservée aux laboratoires validés)

L'option explicite `--execute` arme le travail :

```sh
php maintenance/run.php Wikidebates:preparePendingCaches \
	--wiki=fr --limit=3 --max-seconds=600 \
	--minerva-delay-ms=6000 --delay-ms=1000 --execute --json
```

La commande nécessite les trois caches opérationnels, les en-têtes de
diagnostic Minerva et le préchauffage Lecture activés. Elle exige en outre
des commandes `--page-id` disponibles pour les trois caches.

Les sous-processus sont lancés via des tableaux d'arguments, **sans shell**,
avec une limite d'exécution, une limite de taille des journaux et une validation
stricte de leurs résultats. L'interprétation ne considère pas un simple code
de sortie `0` comme une preuve : les réponses Minerva sans en-tête,
les erreurs HTTP, les fichiers non publiés ou les retours ambiguës restent
en attente.

La commande effectue ses vérifications de révision avec la **base primaire**.
Elle ne modifie ni MariaDB ni les sauvegardes ; ces lectures et la préparation
des fichiers doivent néanmoins être testées sous une charge maîtrisée avant
tout déploiement.

Un verrou global par wiki empêche deux coordinateurs de travailler en même
temps. Un seul échec de stockage, de traitement ou de requête arrête le lot
pour éviter une rafale de tentatives. La durée maximale du **processus**
ne doit jamais être confondue avec la durée de conservation des **fichiers**.

## Travaux encore indispensables

Le coordinateur n'est pas une politique complète d'invalidation :

- Le cache natif Vector dépend encore de `page_touched` et les hooks
	`TitleSquidURLs` peuvent invalider les caches lors d'une modification
	sémantique indirecte. Cette politique reste à corriger et tester sans
	affaiblir la sécurité des pages et révisions protégées.
- Les catégories/pages temporelles nécessitant des exceptions sont à définir.
- Les suppressions, changements de droits et masquages doivent être couverts
	par des tests de production simulée avant toute automatisation.
- Aucun cron n'est ajouté ou réactivé par ce chantier. Les anciennes tâches
	du 22 novembre restent désactivées.
- Une comparaison de performances avant/après avec une charge contrôlée est
	encore nécessaire.

Le test autonome `tests/readingFragments/coordinatedPreparation.php` simule
les erreurs, la concurrence et les résultats des trois commandes. Un second
test MediaWiki 1.43 PHP 8.4 vérifie que le **mode aperçu** ne modifie ni
fichiers HTML ni journal. L'intégration *réelle* des trois sous-processus
ensemble reste un contrôle indispensable avant fusion et production.
