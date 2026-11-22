# Provenance des invalidations sémantiques (étape préparatoire)

L'objectif futur est de distinguer les renouvellements sémantiques indirects
des événements pour lesquels il faut absolument retirer les fichiers HTML :
édition directe, purge explicite, suppression, masquage de révision,
modification d'autorisation ou changement de configuration.

Cette étape **ne change aucun comportement de cache**. Elle ajoute
`SemanticPurgeScope` uniquement autour de l'appel
`HtmlCacheUpdater::purgeTitleUrls()` émis par
`SemanticCachePurgeJob`. Les hooks synchrones `TitleSquidURLs` de cette
opération peuvent ainsi savoir qu'il s'agit de cette purge SMW précise.
L'état est propre à la requête PHP et est toujours restauré, y compris
en cas d'exception. Les éditions et purges ordinaires ne sont pas marquées.

## Portée réelle

| Événement | Marqueur | Comportement actuel |
|---|---|---|
| Job de purge des dépendances de Wikidebates | Actif le temps du dispatch HTML/CDN | Purge inchangée |
| Modification directe d'une page | Inactif | Invalidation habituelle |
| Purge explicite, suppression, déplacement | Inactif | Invalidation habituelle |
| RefreshLinks, tâche MediaWiki ou SMW externe | Inactif | Invalidation habituelle |
| Incident ou exception dans le job | Restauration garantie | Échec et nouvelle tentative |

Le marqueur n'est **pas une preuve** que la page doit être conservée.
Il indique seulement l'origine d'une opération. Une opération SMW peut
avoir des effets sur la visibilité de données ; aucune suppression
d'invalidation n'est autorisée par cette étape.

## Ce qu'il reste à résoudre avant d'ignorer les purges indirectes

1. Le cache Vector natif vérifie encore `page_touched` dans
	`HTMLFileCache::isCacheGood()`. Supprimer un événement
	`TitleSquidURLs` ne suffirait donc pas à préserver le HTML.
2. L'identité du cache Minerva et des fragments Lecture inclut aussi
	`page_touched`, en plus de l'ID de révision, des époques
	et de l'identité de variante.
3. Il faut distinguer les changements SMW du contenu des suppressions,
	masquages de révision et changements de droits qui peuvent laisser
	le même `page_latest`, sans exposer du HTML devenu privé.
4. L'exemption éventuelle doit rester limitée au HTML **anonyme et
	publiquement partageable** ; une requête avec cookie, une session,
	un utilisateur enregistré ou un droit de lecture particulier
	doit toujours suivre le chemin normal.
5. Définir une liste d'exceptions mesurable : pages dynamiques,
	règles de sécurité, catégories générées, pages de discussion
	et tous les rendus dont la validité dépasse la seule révision.

## Validation

Le test autonome vérifie l'imbrication des scopes et la restauration
en cas d'exception. Les tests d'intégration SMW réels sous MediaWiki
1.43.11, SQLite et MariaDB vérifient que les callbacks d'une purge
sémantique portent le marqueur, que les fichiers sont **toujours
invalidés**, et qu'une purge HTTP explicite reste non marquée.

La prochaine étape est un diagnostic des mutations de `page_touched`
sur une page avec révision inchangée, puis un plan d'invalidation
anonyme distinct. Aucun comportement de production n'a été modifié ici.
