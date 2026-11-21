# Contrôler la progression de la file de purges SMW (lecture seule)

Commande disponible sur MediaWiki 1.43. Aucun travail n'est réservé,
acquitté, supprimé ou lancé. L'outil lit la table SQL primaire `job`.

Depuis la racine de la ferme :

```sh
php maintenance/run.php Wikidebates:InspectSemanticPurgeQueue \
	--wiki=en --limit=1000 --claim-age-seconds=3600 --json

php maintenance/run.php Wikidebates:InspectSemanticPurgeQueue \
	--wiki=fr --limit=1000 --claim-age-seconds=3600 --json
```

La dernière ligne est le résumé JSON. `total_jobs_in_sql_queue` est
le **nombre réel de jobs de ce type dans la table SQL**, indépendamment de
`--limit`, `--after-id` et `--match`. Il distingue la disparition
de quelques identifiants d'une file réellement vide. `has_more` et
`resume_after_id` permettent une pagination bornée.

## Interprétation des alertes

- `claimed_jobs` : tâches portant un jeton de réservation SQL.
- `claim_age_seconds` : durée depuis **job_token_timestamp** (date
	de la réservation), pas depuis la création de la tâche.
- `old_claim` et `claims_older_than_threshold` : réservations
	plus anciennes que le seuil choisi, nécessitant vérification.
- `claims_without_valid_timestamp` : jetons réservés sans horodatage
	valide ; une vérification manuelle est indispensable.
- `queue_age_seconds` : durée depuis l'enregistrement du job,
	distincte de la réservation.
- `multiple_cursor_groups` : mêmes titre, dépendances et révision
	sémantique avec plusieurs curseurs ; une continuation peut être normale.
- `repeated_cursor_groups` et `repeated_cursor_jobs` :
	mêmes titre, dépendances, révision sémantique **et curseur** sur plusieurs
	entrées ; vérifier la déduplication avant de modifier quoi que ce soit.
- `jobs_with_multiple_attempts` : tâches ayant déjà été réservées
	plusieurs fois ; ce n'est pas une preuve d'échec définitif.

L'identifiant `continuation_group` est une empreinte tronquée de
(titre, IDs de dépendance triés, revisionToken), **sans exposer les IDs**.
Elle ne vaut que pour l'ensemble effectivement examiné ; un compteur
à zéro ne prouve rien si une partie de la file échappe à la pagination
ou est stockée dans une file externe.

Un job marqué ancien n'est **pas automatiquement bloqué** : MediaWiki peut
réessayer ou libérer une réservation selon son mode de fonctionnement.
Pour établir l'absence de progrès, comparer plusieurs diagnostics successifs
et les logs du worker, sans modifier la file. Ne jamais purger ou forcer
un acquittement sur la seule base de ces chiffres.

La commande `--match=Basic_income` filtre littéralement les titres
des résultats affichés ; le nombre total de jobs n'est pas filtré.
`--claim-age-seconds` accepte 60 à 2 592 000 secondes (30 jours).
Le compte des anciens jobs réservés s'applique uniquement aux entrées
effectivement parcourues et retenues par le filtre.

## Vérifications en environnement jetable

Le test `tests/queueInspector.php` lance la vraie maintenance MediaWiki
dans un processus séparé sur une base SQLite temporaire. Il inclut une
réservation artificiellement ancienne, une réservation récente, deux
continuations portant le même curseur et une entrée sérialisée malformée.
Il vérifie les compteurs, la pagination et que toutes les colonnes SQL
demeurent identiques avant et après la commande.
