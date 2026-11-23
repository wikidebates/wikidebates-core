# Préparation coordonnée des caches HTML anonymes

L'architecture vise trois caches distincts, sans durée maximale implicite : le HTML Vector natif, le HTML mobile MinervaFileCache et les fragments de lecture Wikidebates. Les consultations anonymes peuvent remplir leurs caches indépendamment du cron.

## Consultation et préparation anticipée

- Lors d'une consultation, Vector et Minerva continuent à créer leur propre fichier sur une absence admissible. Le fragment Lecture recherche son propre cache, puis un fichier Vector valide, puis Minerva, et utilise en dernier recours le parseur MediaWiki. **Ne pas supprimer ce fallback utilisateur.**
- Lors d'un préchauffage Lecture, le parseur n'est **pas** utilisé pour construire artificiellement un fragment si aucun HTML complet valide et exploitable n'existe. Le résultat `deferred-no-html` reporte cette préparation sans bloquer les autres pages.
- La préparation coordonnée depuis la file suit l'ordre **Vector → Minerva → Lecture**, avec vérifications de révision et d'accès anonyme entre les phases.
- Chaque cache reste responsable de sa propre validation. Vector annonce `REUSED` si son fichier est valide ; Minerva annonce `HIT` sur son HTTP de préchauffage ; Lecture annonce `already-valid`. Ces résultats ne signifient pas que les autres caches de la page sont valides.
- Les fichiers ne doivent pas être renouvelés en raison d'une modification SMW indirecte. Les invalidations directes et les contrôles de confidentialité demeurent nécessaires.

## Périmètre des trois caches et catégories localisées

- **Lecture** prépare uniquement les pages non redirigées de l'espace principal
  qui sont membres **directs** de la catégorie des arguments.
  L'identifiant `categorylinks.cl_to` provient du message dédié
  `wikidebates-reading-argument-category` dans la langue **du contenu** du wiki,
  sans préfixe de namespace. L'ancien paramètre de configuration
  `WikidebatesReadingArgumentCategory` reste prioritaire s'il est renseigné,
  afin de prendre en charge les noms de catégorie personnalisés.
  Ne pas confondre ce nom de catégorie avec le libellé d'interface « Arguments ».
- Le coordinateur vérifie cette appartenance sur la base primaire via la table
  indexée `categorylinks` avant de lancer l'étape Lecture. Les autres pages
  renvoient `not-an-argument` **sans nouveau sous-processus PHP**. Les
  contrôles de révision et de visibilité continuent avant et après la phase.
- Lors d'une visite anonyme avec un véritable rendu de page, l'extraction
  opportuniste ne se déclenche que pour une page de l'espace principal dont le
  `ParserOutput::getCategoryNames()` contient la catégorie des arguments.
  Cela évite à la fois une nouvelle requête SQL et une analyse DOM inutile des
  pages de débats, de projet, de catégorie et des messages MediaWiki.
- **MinervaFileCache** n'est pas limité à l'espace principal : les pages de
  projet, d'aide, de catégories, de discussion et de descriptions de fichiers
  peuvent être préparées. Chaque réponse reste soumise aux vérifications
  publiques, aux permissions, aux en-têtes (y compris Set-Cookie) et à la
  cacheabilité de MediaWiki. Un BYPASS justifié **ne crée aucun fichier**.
- **Vector natif** couvre les pages et actions admises par
  `HTMLFileCache::useFileCache` et `Article::isFileCacheable`, pas
  exclusivement l'espace principal. En revanche, notre commande de
  préchauffage Vector conserve volontairement la sélection des espaces de
  contenu ; elle possède déjà l'option ciblée `--all` pour examiner des
  espaces non contenus dans cette sélection en respectant les règles natives.
  Son extension au préchauffage automatique des catégories requiert une
  validation explicite de l'invalidation des listes de membres, car le cache
  fichier natif ne couvre pas nécessairement ces dépendances indirectes.

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

## Remplissage initial complet, puis entretien économique

Le préchauffage ciblé `MinervaFileCache:prewarm --page-id` n'attend plus six
secondes après sa seule requête HTTP. La commande autonome **multipages**
conserve sa temporisation par défaut de 6000 ms. Pour la préparation coordonnée,
`--minerva-delay-ms` impose une pause entre deux variantes mobiles qui effectuent
un GET ; `--delay-ms` espace les pages ayant nécessité un rendu ou un GET, y
compris celles ayant reçu un BYPASS de politique. `LOCAL_HIT` n'effectue aucun
GET et ne déclenche pas cette attente.

Le script `maintenance/backfillDailyCaches.sh` constitue un **remplissage unique**,
redémarrable depuis le curseur privé, jusqu'au rapport `cycle-complete`.
Il lance des lots plafonnés à 100 pages, donne la priorité aux éditions,
s'arrête sur un échec, attend 120 secondes après une surcharge et ne remet
jamais le curseur à zéro lui-même. Il refuse un deuxième backfill simultané
du même wiki via un verrou de processus situé dans `$HOME/.cache`.
Exécuter depuis la racine de la ferme MediaWiki, en tant qu'utilisateur qui
exécute normalement la maintenance :

~~~sh
sh extensions/Wikidebates/maintenance/backfillDailyCaches.sh fr android,iphone
sh extensions/Wikidebates/maintenance/backfillDailyCaches.sh en android,iphone
~~~

Ces commandes sont **à lancer séparément**, éventuellement via `nohup` avec
journaux privés. Ne pas programmer les deux instances du même wiki
simultanément ; mettre temporairement en pause le cron de balayage historique
pour éviter les conflits de verrous. Le seuil de charge par défaut est 4 ;
les pauses de préparation sont de 1500 ms entre variantes ayant effectué
un GET et de 1500 ms entre pages ayant eu du travail. Ajustements facultatifs,
uniquement après tests de charge et des limites HTTP :

- `WD_BACKFILL_MAX_LOAD=4`
- `WD_BACKFILL_MINERVA_DELAY_MS=1500`
- `WD_BACKFILL_PAGE_DELAY_MS=1500`

Lorsqu'un cycle est terminé, le curseur revient automatiquement à zéro. Une
exception de stockage ou un HTTP de refus inconnu stoppe le script ; on doit
inspecter le dernier JSON avant de le relancer, sans effacer son état.
`BYPASS-header-set-cookie` est une exclusion documentée **sans fichier Minerva**,
pas un échec ni un fichier créé. Les autres politiques de confidentialité
continuent de s'appliquer. Le terme « complet » s'entend exclusivement des
pages et variantes éligibles ; Vector ne couvre pas les pages non contenues
dans sa politique, Lecture ne couvre que les arguments et Minerva conserve des
clés distinctes pour chaque User-Agent. Les deux User-Agents canoniques
`android,iphone` ne représentent pas toutes les variantes possibles.

Après ce remplissage, l'entretien rapide peut traiter les éditions directes
**sans parcourir toute la table `page`**, grâce à `--queue-only` :

~~~sh
php maintenance/run.php Wikidebates:prepareDailyCaches \
	--wiki=fr --execute --queue-only --limit=40 --queue-limit=40 \
	--max-seconds=900 --max-load=4 \
	--minerva-devices=android,iphone \
	--minerva-delay-ms=1500 --delay-ms=1500 --json
~~~

Un **audit historique à cadence réduite** doit continuer avec le mode
standard (sans `--queue-only`) pour retrouver les fichiers manquants ou
invalidés hors des éditions connues. Cet audit bénéficie des vérifications
locales `REUSED` et `LOCAL_HIT`, mais n'est pas gratuit : chaque page passe
par les contrôles de sécurité et certains sous-processus. La file des éditions
reste prioritaire avant le balayage. Aucun de ces crons n'est installé par le
script de remplissage.

## Limites encore ouvertes

- Des lots réels de préparation ont été validés en production en novembre 2026 sur les deux wikis ; l'optimisation de la pause cible, le mode queue-only et le remplissage intégral doivent encore être validés après déploiement.
- Le contrôle Minerva sans HTTP est réservé au mode ciblé : des variantes issues d'autres User-Agents ne sont pas considérées comme équivalentes.
- L'installation du cron quotidien, le choix des limites de charge et le déploiement ne sont **pas** accomplis par les commandes ci-dessus.
- Les essais jetables MediaWiki 1.43 / PHP 8.4, SQLite et MariaDB, sont nécessaires avant activation automatique. Les tests autonomes ne prouvent pas à eux seuls le comportement en production.

## Sécurité

Le fichier publié peut être ancien, mais ne doit pas devenir accessible après suppression, masquage d'une révision ou retrait des permissions. Les mécanismes existants d'invalidation et les contrôles `AnonymousCacheSafety` doivent être conservés. Aucun rendu ne doit réutiliser les fichiers HTML anonymes pour un utilisateur connecté.

Les anciennes procédures de suivi des dépendances SMW et de réparation des purges sémantiques sont historiques, non requises pour la politique ci-dessus et ne doivent pas être réactivées dans ce chantier.
