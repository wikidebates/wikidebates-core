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

## Limites encore ouvertes

- La file traite les éditions directes ; **elle ne parcourt pas encore toutes les pages historiques sans fichier**. Un parcours global borné, reprenable et partagé avec ce coordinateur reste à développer et tester.
- La prévalidation Minerva sans HTTP, notamment pour les variantes de User-Agent, reste à ajouter après vérification des clés de stockage et de la politique de cache.
- L'installation du cron quotidien, le choix des limites de charge et le déploiement ne sont **pas** accomplis par les commandes ci-dessus.
- Les essais jetables MediaWiki 1.43 / PHP 8.4, SQLite et MariaDB, sont nécessaires avant activation automatique. Les tests autonomes ne prouvent pas à eux seuls le comportement en production.

## Sécurité

Le fichier publié peut être ancien, mais ne doit pas devenir accessible après suppression, masquage d'une révision ou retrait des permissions. Les mécanismes existants d'invalidation et les contrôles `AnonymousCacheSafety` doivent être conservés. Aucun rendu ne doit réutiliser les fichiers HTML anonymes pour un utilisateur connecté.

Les anciennes procédures de suivi des dépendances SMW et de réparation des purges sémantiques sont historiques, non requises pour la politique ci-dessus et ne doivent pas être réactivées dans ce chantier.
