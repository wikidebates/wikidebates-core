# Caches anonymes lies a la page, independants des resultats SMW

**Statut : proposition experimentale ; ne pas activer directement sur les sites publics.**

## Regle fonctionnelle

Un fichier HTML Vector, un HTML Minerva ou un fragment Lecture contient un
**instantane du resultat des recherches semantiques au moment du rendu**.
Une modification de ces resultats sur une autre page **ne doit pas invalider**
cet instantane ni lancer un nouveau prechauffage pour sa page proprietaire.

En revanche, une edition directe de cette page doit invalider ses trois caches.
Suppression, masquage de revision, deplacement et retrait du droit de lecture
restent des exceptions de securite et de validite de l'identite de la page.

Le mode accepte donc deliberement que le HTML conserve d'anciens resultats
SMW jusqu'a la prochaine modification directe de la page.

## Mise en oeuvre (option desactivee par defaut)

```php
$wgWikidebatesPageLocalCacheOnly = true;
```

Cette option a priorite sur les anciens reglages de suivi semantique pour les
trois caches. Elle :

- supprime le veto de l'extension Wikidebates contre HTMLFileCache Vector pour
	les pages contenant des requetes SMW, sans contourner les autres vetoes de
	MediaWiki, les controles de visibilite ni la politique d'utilisation anonyme ;
- ne pose plus de marqueur de revision semantique dans ParserOutput et
	n'invalide plus ce cache en fonction des dependances SMW ;
- ne bloque plus le rendu de TrackedSemanticQuery si l'enregistrement de
	profils _ASK est impossible : la requete est toujours executee en
	utilisant la bibliotheque originale SemanticScribunto ;
- desactive l'enregistrement de nouveaux jobs de purge des pages dependantes
	dans SemanticCacheHooks et empeche l'execution d'anciens jobs de ce type ;
- autorise la verification des fragments sans interroger le graphe SMW ;
- selectionne les demandes d'edition directe (schemas 1/2) dans le lot de
	prechauffage, en laissant les anciens schemas 3 visibles a l'inspection.

Elle ne modifie **ni** les controleurs d'acces anonymes, **ni** les
protections RevisionDelete, **ni** les hook d'invalidation normale de
MediaWiki. Elle n'installe pas de cron et ne purge aucun fichier.

L'ancien reglage de suivi SMW peut rester present pendant le test ; ce mode
l'emporte sur les operations d'invalidation de l'extension Wikidebates.

## Limites connues : controle a effectuer AVANT production

**Attention : cette modification neutralise les invalidations semantiques
produites par Wikidebates, mais ne suffit pas a garantir a elle seule qu'un
fichier ne sera jamais regenere sans edition directe.**

En MediaWiki 1.43 :

- le cache fichier natif Vector depend aussi de `page_touched`, qui peut
	changer apres une modification de modele ou une autre dependance ;
- les caches Minerva et Lecture peuvent expirer selon leur TTL propre, meme
	sans evenement d'invalidation ;
- le cache global `CacheEpoch`, une purge explicite, ou un traitement natif
	SMW/MediaWiki peuvent egalement provoquer un renouvellement ;
- un cache HTTP Cloudflare ou une regle Apache de service direct des fichiers
	ne doivent pas court-circuiter les controles de droits.

**La garantie absolue "aucun renouvellement sans modification directe"
ne sera donc acquise qu'apres un test qui distingue explicitement ces cas
et, si necessaire, la correction des mecanismes externes au present depot.**

## Validations proposees

1. Environnement jetable MediaWiki 1.43.11, PHP 8.4, SMW 7.3.1,
	SemanticScribunto et les trois caches. Ne rien modifier sur la production.
2. Lancer `php tests/readingFragments/pageLocalCachePolicy.php` et
	`php tests/readingFragments/coordinatedPreparation.php`.
3. Sur une copie isolee configuree par tests/mediawikiEnvironment.php,
	lancer `php tests/fullstackPageLocalCache.php` (SQLite puis MariaDB).
4. Sur une page Argument avec une recherche SMW : verifier deux GET Vector
	anonymes dont le second lit effectivement le fichier ; idem Minerva
	avec son HIT et Lecture avec `source=fragment-cache`.
5. Ajouter puis retirer un debat parent en editant uniquement une autre
	page. Verifier que les fichiers, leurs generations et leur contenu
	restent inchanges, qu'aucun job semantique n'est emis et que `page_touched`
	de l'argument ne bouge pas.
6. Editer directement l'argument : verifier l'invalidation des trois caches,
	leur nouveau rendu et le fait que le HTML connecte reste personnalise.
7. Tester RevisionDelete, suppression, deplacement, restrictions de lecture,
	changements de modele, purge explicite et expiration des TTL ; documenter
	toute exception a la regle.
8. Comparer cout SQL/CPU, latence et taux de HIT, sans extrapoler des chiffres.

## Retour arriere

Pour revenir immediatement a l'ancien comportement, supprimer cette option
de la configuration ou la mettre a `false`. Aucune migration SQL n'est
requise par cette proposition.

**Ne pas fusionner avant les tests d'integration, et ne pas activer de
preparation automatique tant que les controles des trois caches ne passent pas.**
