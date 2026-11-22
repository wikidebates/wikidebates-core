# Cache Vector pour les pages SMW (laboratoire uniquement)

**Statut : expérimental, désactivé par défaut. Ne pas activer sur les sites publics avant les tests intégrés.**

Objectif : produire et réutiliser le vrai cache HTMLFileCache natif pour les visiteurs anonymes,
y compris sur les pages contenant des requêtes sémantiques SMW.

## Principe

- Le fichier Vector est marqué par un HMAC signé dans **le HTML lui-même**, sans sidecar.
- La signature lie page_id, page_latest, page_touched et empreinte transactionnelle SMW.
- Le ParserOutput doit porter l'empreinte du graphe réellement analysé ; avant le marquage
  le graphe primaire est recalculé et comparé à l'empreinte du rendu.
- À chaque HIT, IsFileCacheable vérifie la visibilité publique depuis la base primaire,
  le marqueur signé et l'empreinte sémantique courante ; les fichiers périmés sont invalidés.
- Sans profil _ASK, signature valide ou accès à SMW, les fichiers partagés sont refusés.
- Les pages SMW conservent leurs en-têtes HTTP interdisant un cache CDN/client autonome.
- Les sessions enregistrées, requêtes authentifiées et anonymes avec cookies ne
  peuvent pas emprunter le nouveau chemin Vector.

## Activation sur une copie de préproduction exclusivement

Après installation des tables de suivi SMW et reconstruction ciblée des profils _ASK :

    $wgWikidebatesSemanticCacheTracking = true;
    $wgWikidebatesSemanticCacheEagerPurge = false;
    $wgWikidebatesVectorSemanticFileCache = true;

Ne pas ajouter ces paramètres à la production à cette étape. L'option reste false
par défaut dans extension.json. Aucun cron ou purge de masse n'est ajouté.

**Condition indispensable** : s'assurer qu'Apache n'expose jamais directement
les fichiers HTML Vector et que Cloudflare ne met pas ces réponses en cache.
Un fichier servi sans passer par Article::tryFileCache() contournerait la protection
sémantique à la lecture ; la fonctionnalité doit alors rester désactivée.

## Validation bloquante en environnement jetable

1. Choisir un argument de test avec profils _ASK valides et dépendances renseignées.
2. Générer le fichier par Wikidebates:prepareVectorViewCache --wiki=fr --page-id=ID --json.
3. Vérifier HIT/MISS et contenu visible sur deux requêtes anonymes canoniques.
4. Ajouter, puis retirer un débat parent **sans nouvelle révision de l'argument**.
   Sans job de purge sémantique, le HIT suivant doit écarter l'ancien fichier et
   afficher la relation SMW actuelle.
5. Répéter après changements de même seconde, déplacements, suppressions,
   masquage de révision, restrictions de lecture et absence de profil _ASK.
6. Tester les sessions enregistrées et anonymes avec cookies, Minerva, Lecture,
   rendu concurrent, et intégration du script de préchauffage (mode aperçu inclus).
7. Comparer SQL, latence et CPU pour les HIT Vector protégés et le rendu complet.
   Sans amélioration nette, simplifier la stratégie avant déploiement.

## Désactivation immédiate

    $wgWikidebatesVectorSemanticFileCache = false;

Le veto antérieur est alors rétabli ; les anciens fichiers éventuellement présents
ne peuvent plus être servis par ce chemin.

Le mode --dry-run ne doit jamais supprimer de fichier. Il peut retourner
SKIPPED_CACHE_POLICY si un fichier déjà présent est sémantiquement invalide.

Test autonome : php tests/readingFragments/semanticVectorFileGuard.php.
Les tests d'intégration MediaWiki 1.43, SMW 7.3.1 et les mesures restent requis.
