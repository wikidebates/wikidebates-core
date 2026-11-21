# Mesurer le coût des vérifications sémantiques (sans purger)

Disponible sur MediaWiki 1.43 et SMW 7.3.1 après installation des tables
`wd_smw_entity_revisions` et `wd_smw_purge_state`. **Ne modifie ni la page,
ni le suivi, ni les caches, ni les jobs.** Ne nécessite pas d'activer
`WikidebatesSemanticCacheTracking` ou `WikidebatesSemanticCacheEagerPurge`.

Mesurer un argument représentatif et un débat complexe, séparément :

```sh
cd /home/users/webmaster/www/farm.wikidebates.org/w

php maintenance/run.php Wikidebates:MeasureSemanticCacheGuard \
	--wiki=fr --title="Dieu est une invention" \
	--samples=20 --warmups=3 --json

php maintenance/run.php Wikidebates:MeasureSemanticCacheGuard \
	--wiki=fr --title="Dieu existe-t-il ?" \
	--samples=20 --warmups=3 --json
```

Pour comparer à une préproduction, utiliser les mêmes titres et paramètres
avec un trafic similaire, puis répéter plusieurs fois en dehors des
reconstructions sémantiques. Limites : entre 1 et 30 échantillons,
entre 0 et 5 préchauffages, une seule page par commande.

## Trois étapes mesurées séparément

- `query_page_classification` : déterminer si la page a une requête
	sémantique ou un marqueur de source Argument ;
- `semantic_fingerprint` : lire les dépendances exactes et révisions
	sémantiques via la base primaire ;
- `cache_read_guard` : vérifier les conditions de validité et lire
	l'empreinte utilisée pour protéger les fragments du mode lecture.

Pour chacune, l'outil fournit nombre d'échantillons, médiane, p95 et
maximum, en millisecondes. Les temps **ne doivent pas être additionnés** :
la vérification complète peut inclure la classification et l'empreinte.
`guard_safe_to_read=false` signifie que le garde-fou n'a pas pu certifier la
lecture au moment du test ; cela n'autorise jamais à réutiliser un cache
potentiellement périmé. `fingerprints_stable=false` peut signaler une
modification sémantique concurrente ; refaire la mesure.

Ces statistiques ne mesurent **pas** le coût de rendu du parser,
la latence totale de MediaWiki, les caches MinervaFileCache ou le CDN,
le réseau, ni le nombre d'appels SQL exact par requête HTTP.
La suite de tests HTTP préproduction complète ces mesures.

## Pourquoi commencer par mesurer

Optimiser les requêtes SQL, les index ou la fréquence du contrôle **sans
mesure** risquerait de perdre la garantie de fraîcheur, notamment après
plusieurs modifications dans une même seconde. Comparer les p95 de ces
trois étapes et les observations de pages réelles avant de changer
`SemanticRevisionStore::fingerprint()`. Les tests SQL et de fraîcheur doivent
rester verts sur SQLite et MariaDB.
