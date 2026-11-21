# Inspecter les jobs de purge SMW en lecture seule

Cet outil est disponible sur la branche `main` sans activer le pilote des
caches sémantiques (PR n°11 et n°21), sans changer `LocalSettings.php`,
sans migration et sans toucher aux jobs enregistrés.

La commande `Wikidebates:InspectSemanticPurgeQueue` consulte uniquement
la table SQL `job` du wiki sélectionné. Elle lit les paramètres des jobs
de type `wikidebatesSemanticCachePurge`, sans instancier leur classe et
sans requérir leur présence dans `JobClasses`.

Elle ne déclenche ni traitement, ni réservation, ni acquittement, ni purge.

## Exécution sur le serveur

Après la mise à jour habituelle de l'extension :

```sh
cd /home/users/webmaster/www/farm.wikidebates.org/w

php maintenance/run.php Wikidebates:InspectSemanticPurgeQueue \
	--wiki=en --limit=200 --json

php maintenance/run.php Wikidebates:InspectSemanticPurgeQueue \
	--wiki=fr --limit=200 --json
```

Les champs utiles sont `job_id`, `title`, `cursor`,
`dependency_count`, `claimed`, `attempts` et `valid_payload`.
Le champ `resume_after_id` indique le prochain point de reprise si
d'autres lignes existent.

La pagination est possible avec `--after-id=12345` et la recherche
par titre avec `--match=Basic_income`.

Important : `claimed=true` signifie seulement qu'un jeton de
réservation est présent en base. Il ne prouve pas que le job progresse.
Ce diagnostic ne découvre pas les jobs d'une autre file externe ni
ceux déjà abandonnés, et un état peut changer entre deux lectures.
Un résultat favorable ne prouve pas la fraîcheur des pages.

## Validation automatisée

`tests/queueInspector.php` crée une base SQLite isolée et insère :
- un job valide avec curseur et dépendances ;
- un job avec charge sérialisée invalide ;
- un job d'un autre type, qui doit être exclu.

L'essai contrôle la lecture sans enregistrement du JobFactory, le
filtrage, la pagination et **l'identité exacte des lignes SQL avant
et après l'inspection**. GitHub Actions exécute ces vérifications
avec MediaWiki 1.43.11 sous PHP 8.3 et 8.4.

Aucune configuration de cache, schéma SQL, page ou job de production
n'est modifié par la mise à jour de cette commande.
