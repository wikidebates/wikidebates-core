# Préparer le vrai cache Vector sans reconstruire les historiques

MediaWiki 1.43 fournit `maintenance/rebuildFileCache.php`, mais ce programme
reconstruit pour chaque page à la fois la vue `action=view` et la page
`action=history`, même quand seule la vue manque.

Le script `maintenance/prepareVectorViewCache.php` prépare **une seule page**
avec le cache HTML natif `HTMLFileCache` en conservant les contrôles de
partage de MediaWiki et en ne sauvegardant que `action=view`.

## Commandes ciblées

```sh
php maintenance/run.php Wikidebates:prepareVectorViewCache --wiki=fr --page-id=1220 --json
php maintenance/run.php Wikidebates:prepareVectorViewCache --wiki=en --page-id=1220 --dry-run --json
```

Les identifiants peuvent correspondre à des pages différentes sur les deux
wikis. Les pages manquantes, les redirections, les pages non publiques ou
les espaces de noms non destinés au contenu sont ignorés. Pour contrôler
explicitement les espaces supplémentaires, utiliser `--all` tout en laissant
la politique native refuser les pages dangereuses.

États JSON : `CREATED`, `REUSED`, `MISSING_FILE` (simulation),
`MISSING`, `SKIPPED_NONPUBLIC`, `SKIPPED_NONCONTENT`,
`SKIPPED_REDIRECT_OR_INVALID`, `SKIPPED_CACHE_POLICY`, et erreurs de rendu/édition concomitante.

`SKIPPED_NONPUBLIC` signifie que les vérifications de visibilité et de droits
anonymes ont échoué. `SKIPPED_CACHE_POLICY` signifie que la page est publique
mais que le cache natif a été explicitement refusé par un hook (par exemple
pour protéger la fraîcheur des requêtes SMW lorsque le suivi sémantique est
activé). Le préchauffage ne contourne jamais ce refus.

Les fichiers déjà valides ne sont pas régénérés. Le script utilise le mode
`HTMLFileCache::MODE_REBUILD`, un contexte Vector, une capture complète du HTML
et le mécanisme natif de stockage. Il ne construit jamais la vue d'historique.

## Limite à résoudre lors de la politique sémantique durable

Le cache natif contrôle actuellement `page_touched` et `CacheEpoch`.
Un changement sémantique indirect peut donc rendre un fichier Vector invalide
même si l'identifiant de la dernière révision n'a pas changé. Ce script
n'empêche pas cette invalidation : il évite uniquement de reconstruire
l'historique et de parcourir toutes les pages.

La séparation entre invalidations indirectes acceptables pour les anonymes
et protections obligatoires des utilisateurs connectés et des révisions masquées
doit être validée séparément. Cette préparation ciblée ne modifie **aucun**
fichier du cœur de MediaWiki et n'active ni cron ni préchauffage automatique.
