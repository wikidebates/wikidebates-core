# Coordination expérimentale des trois caches anonymes

**Statut : développement et validation en laboratoire. Ne pas activer en production.**

La commande `Wikidebates:preparePendingCaches` travaille uniquement sur des pages
directement éditées, présentes dans la file persistante de Wikidebates.

Elle prépare successivement, par `page_id` :

1. `Wikidebates:prepareVectorViewCache` (vrai fichier natif `action=view`, aucun historique).
2. `MinervaFileCache:prewarm` (un seul GET anonyme, contrôles HTTP complets).
3. `Wikidebates:prepareReadingCache` (un fragment si la page appartient à la catégorie Arguments).

## Protection contre les mises à jour sémantiques sans nouvelle révision

Les anciens enregistrements de file (`schema: 1`) ne connaissaient que
`pageId` et `revisionId`. Or un changement de débat parent via SMW peut
modifier le HTML d'un argument sans modifier sa révision MediaWiki.

Les nouveaux enregistrements (`schema: 2`) contiennent un identifiant
`generation` aléatoire, renouvelé à chaque demande. Un worker ne peut confirmer
que le triplet `(pageId, revisionId, generation)` qui lui a été remis.
Si une modification directe ou indirecte arrive pendant le rendu, la file
conserve le nouvel événement et le worker devra recommencer. L'écriture et
l'acquittement restent protégés par le verrou exclusif de cette page.

La méthode `CachePreparationQueue::enqueueInvalidation()` permet de
renouveler le marqueur même à révision inchangée, tout en conservant la
révision la plus récente connue. **Elle n'est pas encore reliée aux jobs
sémantiques** : l'intégration devra intervenir uniquement après une purge
confirmée et conserver le job en échec réessayable si l'écriture échoue.
Les anciens fichiers `schema: 1` restent lisibles ; seuls les
nouveaux événements adoptent le format 2.

Une préparation partielle **n'efface pas la demande**. Une interruption reprend
donc la même page ; les sous-caches déjà valides sont réutilisés. La file ne
retire une entrée qu'après confirmation explicite des trois étapes et
nouvelle vérification du `page_latest` dans la base primaire.

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
