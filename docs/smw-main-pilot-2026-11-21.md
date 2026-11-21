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
Le manifeste laisse desormais Tracking=false ET EagerPurge=false par defaut.
Lorsque EagerPurge=false, un job refuse l'execution avec une erreur
reessayable (il ne doit plus etre acquitte comme termine). La variable
WD_SEMANTIC_PURGE_MANUAL ne change pas a elle seule cette configuration :
CheckSemanticCache doit prouver les preconditions effectives avant tout essai.

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

## Protection contre la perte de jobs par erreur de configuration

MediaWiki 1.43 acquitte un job dont `run()` renvoie `true` ; l'ancien
comportement d'un purge job desactive supprimait donc silencieusement le
travail de la file. Le garde-fou renvoie maintenant `false` et conserve
`allowRetries()=true` lorsque Tracking ou EagerPurge sont desactives.
Ne pas laisser pour autant un worker general selectionner ce type : des
reprises repetees finissent par saturer la file ou etre abandonnees.
Le preflight doit afficher EagerPurge=true dans le processus manuel ; la
variable `WD_SEMANTIC_PURGE_MANUAL` seule ne modifie pas cette valeur.
