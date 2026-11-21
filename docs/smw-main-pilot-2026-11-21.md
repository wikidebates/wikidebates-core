# Pilotage des purges SMW depuis le main recent

Cette branche pilote (`pilot/smw-cache-main-2026-11-21`) reporte les
optimisations de la PR #11 sur `main` sans remplacer les correctifs
recents du mode lecture, de Minerva et de la fiabilite de l'interface.

**Ne pas fusionner dans main.** Un test automatique ne prouve pas que
les caches de production, le CDN et les dependances SQL sont frais.

## Avant installation sur le serveur

Depuis `extensions/Wikidebates`, sauvegarder la modification locale
de `modules/reading/minerva.css` (un stash avec pathspec convient),
puis basculer vers la branche pilote apres verification de CI.
Ne pas effacer ni remplacer les jobs existants.

Les workers anglais habituels doivent toujours exclure
`smw.update` et `wikidebatesSemanticCachePurge`. Pour toute
execution des purges, utiliser `WD_SEMANTIC_PURGE_MANUAL=1`.
Lancer un purge job sans cette variable lorsque EagerPurge=false
l'acquitte sans purger.

## Diagnostic et lot de cinq jobs

Depuis la racine MediaWiki :

```sh
WD_SEMANTIC_PURGE_MANUAL=1 php maintenance/run.php Wikidebates:CheckSemanticCache \
	--wiki=en --require-purge-ready

php maintenance/run.php Wikidebates:InspectSemanticPurgeQueue \
	--wiki=en --limit=200

WD_SEMANTIC_PURGE_MANUAL=1 php maintenance/run.php runJobs \
	--wiki=en --type=wikidebatesSemanticCachePurge --maxjobs=5 --maxtime=60

php maintenance/run.php Wikidebates:InspectSemanticPurgeQueue \
	--wiki=en --limit=200
```

Les inspections ne reconstruisent pas les jobs et ne les exécutent pas.
Un compteur inchangé est attendu si chaque job donne une continuation
nouvelle. Comparer curseurs ET identifiants des jobs pour les mêmes titres.
Ne pas traiter de lots plus importants avant de contrôler la fraîcheur
de pages représentatives dans les deux wikis.
