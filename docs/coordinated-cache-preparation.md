# Préparation coordonnée des caches HTML anonymes

L'architecture vise trois caches distincts, sans durée maximale implicite : le HTML Vector natif, le HTML mobile MinervaFileCache et les fragments de lecture Wikidebates. Les consultations anonymes peuvent remplir leurs caches indépendamment du cron.

## Consultation et préparation anticipée

- Lors d'une consultation, Vector et Minerva continuent à créer leur propre fichier sur une absence admissible. Le fragment Lecture recherche son propre cache, puis un fichier Vector valide, puis Minerva, et utilise en dernier recours le parseur MediaWiki. **Ne pas supprimer ce fallback utilisateur.**
- Lors d'un préchauffage Lecture, le parseur n'est **pas** utilisé pour construire artificiellement un fragment si aucun HTML complet valide et exploitable n'existe. Le résultat `deferred-no-html` reporte cette préparation sans bloquer les autres pages.
- La préparation coordonnée depuis la file suit l'ordre **Vector → Minerva → Lecture**, avec vérifications de révision et d'accès anonyme entre les phases.
- Chaque cache reste responsable de sa propre validation. Vector annonce `REUSED` si son fichier est valide ; Minerva annonce `HIT` sur son HTTP de préchauffage ; Lecture annonce `already-valid`. Ces résultats ne signifient pas que les autres caches de la page sont valides.
- Les fichiers ne doivent pas être renouvelés en raison d'une modification SMW indirecte. Les invalidations directes et les contrôles de confidentialité demeurent nécessaires.

## File des éditions directes

`WikidebatesCachePreparationDirectory` est désactivé par défaut. Pour l'activer, choisir un répertoire privé (`0700`) hors de l'arborescence web. Le chemin doit être vérifié sur le serveur. Une modification de `LocalSettings.php` peut aussi faire évoluer `CacheEpoch`.

Aperçu, sans création de cache :

```sh
php maintenance/run.php Wikidebates:preparePendingCaches --wiki=fr --limit=10 --json
php maintenance/run.php Wikidebates:preparePendingCaches --wiki=en --limit=10 --json
```

Après validation du déploiement et sur un lot restreint :

```sh
php maintenance/run.php Wikidebates:preparePendingCaches --wiki=fr --limit=3 --max-seconds=600 --execute --json
```

La commande contrôle la visibilité sur la base primaire, utilise un verrou exclusif par wiki et conserve une entrée si la révision a changé pendant la préparation. Une erreur de stockage ou HTTP arrête le lot.

## Parcours quotidien des pages historiques

La commande `Wikidebates:prepareDailyCaches` réutilise la même file et le même
verrou exclusif que `preparePendingCaches`. Elle consomme d'abord un lot
d'éditions directes, puis parcourt progressivement les pages non redirigées
selon `page_id`, même si elles n'ont jamais été modifiées récemment.

Le curseur atomique `daily-cursor.json` est conservé dans le dossier privé
propre à chaque wiki. Il avance seulement après une préparation terminée
ou une exclusion de sécurité avérée. Une erreur de préparation ou une
modification concurrente interrompt le passage sans avancer le curseur
sur la page concernée. À la fin de la table `page`, le curseur revient à
zéro pour une nouvelle vérification légère au cycle suivant.

Aperçu entièrement en lecture seule :

```sh
php maintenance/run.php Wikidebates:prepareDailyCaches --wiki=fr --limit=10 --json
php maintenance/run.php Wikidebates:prepareDailyCaches --wiki=en --limit=10 --json
```

Exécution limitée, **uniquement après installation des versions coordonnées**
de Wikidebates et MinervaFileCache et vérification des permissions :

```sh
php maintenance/run.php Wikidebates:prepareDailyCaches --wiki=fr \
	--execute --limit=10 --queue-limit=5 --max-seconds=1200 \
	--minerva-devices=android --minerva-delay-ms=6000 --delay-ms=1000 --json
```

Pour préparer aussi iPhone, utiliser `--minerva-devices=android,iphone`.
Chaque variante Minerva est contrôlée localement via `--skip-valid` ;
une clé identique donne `LOCAL_HIT` sans requête HTTP ni écriture.
Une variante manquante passe toujours par le GET anonyme normal.

Le coordinateur Lecture utilise `deferred-no-html` si aucun fichier complet
n'est exploitable. Le parseur direct reste accessible pour l'API de
consultation, mais pas pour le cron. Les pages non éligibles au fragment
sont simplement ignorées.

Le seuil optionnel `--max-load` interrompt le traitement avant une nouvelle
page si la charge moyenne Linux sur une minute le dépasse. La valeur `0`
désactive ce contrôle ; choisir un seuil **après mesure du nombre de CPU et
de la charge habituelle sur le serveur**, par exemple en ajoutant
`--max-load=4` uniquement si cette limite est pertinente. Une interruption
pour `system-load` conserve le curseur et les éditions non traitées.

Le cron n'est **pas** installé par ces commandes. Avant toute programmation,
vérifier les tâches déjà présentes sur le serveur, les versions déployées,
les autorisations `0700` du dossier privé et les éventuelles invalidations
globales de `CacheEpoch` provoquées par `LocalSettings.php`.

## Limites encore ouvertes

- Le nouveau parcours quotidien doit encore être validé sur un lot réel avant activation automatique, avec contrôle des fichiers, du curseur et de la charge.
- Le contrôle Minerva sans HTTP est réservé au mode ciblé : des variantes issues d'autres User-Agents ne sont pas considérées comme équivalentes.
- L'installation du cron quotidien, le choix des limites de charge et le déploiement ne sont **pas** accomplis par les commandes ci-dessus.
- Les essais jetables MediaWiki 1.43 / PHP 8.4, SQLite et MariaDB, sont nécessaires avant activation automatique. Les tests autonomes ne prouvent pas à eux seuls le comportement en production.

## Sécurité

Le fichier publié peut être ancien, mais ne doit pas devenir accessible après suppression, masquage d'une révision ou retrait des permissions. Les mécanismes existants d'invalidation et les contrôles `AnonymousCacheSafety` doivent être conservés. Aucun rendu ne doit réutiliser les fichiers HTML anonymes pour un utilisateur connecté.

Les anciennes procédures de suivi des dépendances SMW et de réparation des purges sémantiques sont historiques, non requises pour la politique ci-dessus et ne doivent pas être réactivées dans ce chantier.
