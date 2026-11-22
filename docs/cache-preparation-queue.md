# File persistante de préparation des caches anonymes — première étape

Cette fonctionnalité enregistre uniquement les **modifications directes** des pages
afin de préparer, plus tard, les fichiers HTML Vector, Minerva et Lecture sans
parcourir la totalité des pages chaque nuit.

## Activation (désactivée par défaut)

L'extension expose le paramètre suivant :

```php
$wgWikidebatesCachePreparationDirectory = false;
```

Laisser la valeur `false` jusqu'à validation du consommateur coordonné.
La queue ne déclenche actuellement **aucun préchauffage** ni aucune tâche cron.

Pour l'activer ultérieurement, créer un répertoire privé en dehors de l'espace
web, avec les droits `0700`, puis y définir un chemin absolu :

```php
$wgWikidebatesCachePreparationDirectory = '/chemin/prive/cache-preparation';
```

Chaque wiki est isolé sous un répertoire dérivé de son identifiant, et chaque
enregistrement ne contient qu'un identifiant de page, l'ID de sa révision et la
date de mise en attente. Aucune donnée HTML, URL, titre de page ni information
personnelle n'y est stockée.

## Contrôle sans intervention sur les caches

Depuis le répertoire racine de MediaWiki, la commande en lecture seule est :

```sh
php maintenance/run.php Wikidebates:inspectCachePreparationQueue --wiki=fr --limit=100 --json
php maintenance/run.php Wikidebates:inspectCachePreparationQueue --wiki=en --limit=100 --json
```

Selon l'installation, utiliser directement le script de maintenance de
l'extension si la commande nommée n'est pas enregistrée ; dans tous les cas,
aucune entrée n'est consommée par l'inspection.

Les événements `PageSaveComplete` ajoutent une entrée ou actualisent sa révision,
y compris lorsque l'ancienne version du même article était déjà en attente.
Les modifications d'autres pages, les recalculs SMW et les purges de dépendances
n'émettent pas directement de nouvel événement `PageSaveComplete` sur l'article
inchangé, donc ne doivent pas l'inscrire dans cette file.

## Préparation ciblée d'un fragment de lecture

Le script `Wikidebates:prepareReadingCache` peut maintenant inspecter exactement
une page de catégorie Arguments sans parcourir les autres :

```sh
php maintenance/run.php Wikidebates:prepareReadingCache --wiki=fr --page-id=1220 --json --sleep-ms=0
```

Cela exige les paramètres existants `WikidebatesReadingFragments` et
`WikidebatesReadingPrewarm` activés. Seules les pages de l'espace principal
directement membres de la catégorie d'arguments sont concernées ; les autres
identifiants produisent un rapport `inspected: 0` sans entraîner le
préchauffage d'une page voisine.

Le mode `--page-id` refuse les curseurs globaux `--after`,
`--resume-file` et une limite multipage. Le verrou de préparation
existant et les contrôles des fragments réutilisables sont conservés.

## Concurrence, reprise et futurs consommateurs

- Une entrée n'est retirée que si le consommateur confirme exactement la
	révision préparée. Une édition intervenue entre la lecture et la fin
	du traitement reste donc en attente.
- Les verrous `flock` sont propres aux pages et ne sont jamais supprimés pendant
	les opérations, afin d'éviter deux domaines de verrouillage concurrents.
- Les écritures utilisent un fichier privé temporaire suivi d'un renommage
	atomique ; un arrêt avant confirmation laisse l'entrée en attente.
- La liste des travaux peut être lue par lots bornés, sans charger toute la
	queue en mémoire. Aucun curseur global susceptible d'être perdu n'est requis
	pour les éléments non acquittés.
- L'espace est partitionné par wiki. Si plusieurs serveurs PHP écrivent
	dessus, il faut un système de fichiers partagé avec `flock` et renommage atomique.

**Limite actuelle :** aucun consommateur coordonnant Vector, Minerva et Lecture
n'est encore relié à la queue. Il faudra gérer les suppressions, déplacements,
changements de droits et exceptions de pages séparément. Aucune politique
d'invalidation sémantique n'est modifiée ici.

Le banc de test autonome couvre les reprises, la protection contre un
acquittement obsolète, la partition par wiki, la résistance à la corruption
et les permissions. Une intégration HTTP/MediaWiki 1.43 jetable vérifie
également que la modification d'un modèle n'inscrit pas automatiquement
l'argument dépendant.
