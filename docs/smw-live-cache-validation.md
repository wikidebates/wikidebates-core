# Pilote HTTP du suivi sémantique : Vector, Minerva et Cloudflare

Ce plan vérifie sur une **préproduction représentative** la fraîcheur d'une
page Argument utilisant SMW 7.3.1, MediaWiki 1.43, le cache de fragments,
les vues Vector/Minerva, MinervaFileCache et, si présent, Cloudflare.

**Aucune étape ne doit éditer, purger ou invalider automatiquement le wiki
public.** Le script `maintenance/probeSemanticHttp.cjs` n'émet que des GET
anonymes ; il ne possède aucune fonctionnalité d'édition, de POST ou
d'administration. Les réponses brutes ne sont pas enregistrées dans le
rapport : seuls les empreintes SHA-256, tailles, en-têtes de cache et
marqueurs attendus sont conservés.

## 1. Préparer une copie de préproduction

- Copier les **configurations et contenus représentatifs** des deux wikis
	sans exposer les informations privées, puis installer le dernier
	`wikidebates-core/main` et exécuter `maintenance/run.php update`
	sur la base de préproduction.
- Répliquer la configuration des caches HTML, du stockage des fragments,
	des workers, de Vector/Minerva, de MinervaFileCache et, si nécessaire,
	du CDN. Un environnement sans CDN ne vérifie pas Cloudflare.
- Sur cette **préproduction seulement**, configurer :

```php
$wgJobTypesExcludedFromDefaultQueue[] = 'wikidebatesSemanticCachePurge';
$wgWikidebatesSemanticCacheTracking = true;
$wgWikidebatesSemanticCacheEagerPurge = false;
```

- Vérifier en lecture seule `Wikidebates:CheckSemanticCache --json`,
	`Wikidebates:AuditSemanticFreshness --summary-only` et
	`Wikidebates:MeasureSemanticCacheGuard --title="..." --samples=20 --json`.
	`purge_preconditions_met=false` est alors **normal** puisque les purges
	anticipées restent désactivées.

Choisir au minimum une page Argument française, une anglaise et une page
Débat avec beaucoup d'arguments. Pour valider un ajout/suppression de
parent, utiliser une **page d'argument jetable en préproduction**.

## 2. Relever la situation initiale

Node.js 20+ requis. Remplacer l'origine par l'adresse de préproduction :

```sh
node maintenance/probeSemanticHttp.cjs \
	--base=https://staging-fr.example.org \
	--title="Argument de test" \
	--iterations=3 \
	--out=/tmp/smw-fr-before.json
```

Le script effectue 3 GET sur chacun des quatre points d'accès :

1. `/w/index.php?title=...&useskin=vector`
2. `/w/index.php?title=...&useskin=minerva`, avec User-Agent Android
3. `/w/api.php?action=wikidebatesreading&page=...&useskin=vector`
4. La même API avec `useskin=minerva`

Le script relève `CF-Cache-Status`, `Age`, `Cache-Control`, `X-Cache`,
`ETag` et `Vary` lorsqu'ils existent. L'absence d'un en-tête n'est
pas un résultat « HIT » implicite.

## 3. Changer une relation sémantique en préproduction uniquement

Dans la copie de préproduction, modifier une relation de parent sur une
page d'argument jetable, puis laisser SMW traiter ses mises à jour
ordinaires. Vérifier la propriété et les résultats directement dans
SMW : **la nouvelle relation doit être présente** avant d'attribuer
une incohérence au cache.

Relancer la sonde en demandant un marqueur visible propre au **HTML complet**
(Vector/Minerva) :

```sh
node maintenance/probeSemanticHttp.cjs \
	--base=https://staging-fr.example.org \
	--title="Argument de test" \
	--baseline=/tmp/smw-fr-before.json \
	--expect-html="NOUVEAU_PARENT_VISIBLE" \
	--require-changed=vector,minerva \
	--iterations=3 \
	--out=/tmp/smw-fr-after.json
```

Si une réponse HTML contient encore l'ancienne relation ou si le marqueur
attendu manque, `passed=false` et le processus quitte avec le code 2.
L'absence du marqueur ne doit jamais être masquée par un cache HIT ou
par une empreinte différente.

**Important :** le fragment de lecture exclut volontairement la section
« Débats parents ». Un changement de parent peut donc ne pas modifier le
HTML de ce fragment. Pour tester sa fraîcheur, modifier séparément une
information visible dans le fragment (par exemple un résumé de la page
jetable) et utiliser `--expect-fragment=MARQUEUR_VISIBLE` avec
`--require-changed=fragment_vector,fragment_minerva`. Ne pas exiger
un changement de fragment pour une donnée qu'il n'affiche pas.

Un SHA différent n'établit pas à lui seul la fraîcheur : le HTML peut
contenir des champs dynamiques. Il faut comparer la **valeur visible
attendue** et vérifier les réponses successives, y compris après HIT CDN.

## 4. Purges anticipées : test séparé et réversible

Uniquement après réussite du test précédent, effectuer un test manuel en
préproduction avec `WikidebatesSemanticCacheEagerPurge=true` dans
la configuration de **cette copie**. Le préflight
`Wikidebates:CheckSemanticCache --require-purge-ready` doit réussir
et les workers généraux doivent exclure les purges sémantiques.

Exécuter uniquement sur la copie, avec une limite très faible,
le traitement explicite `runJobs --type=wikidebatesSemanticCachePurge`.
Recontrôler le HTML et les en-têtes CDN après propagation.
Ramener ensuite `EagerPurge=false`.

Ne **jamais** employer cette procédure sur les jobs historiques en
production sans une décision et un plan de reprise séparés. Une CI verte
prouve le comportement sur le laboratoire jetable, **pas** la fraîcheur
des réponses Cloudflare sur le vrai site.

## 5. Critères bloquants avant activation publique

- Résultat SMW direct et HTML complet concordants après ajouts,
	suppressions, renommages et changements de parents.
- Les deux habillages et les fragments n'affichent aucune ancienne
	valeur **lorsque cette valeur fait partie de leur rendu**.
- Les en-têtes et cache HIT du CDN ne révèlent pas de pages périmées.
- Les mesures médiane/p95 SQL et HTTP sont acceptables pour les grands
	arguments, sans baisse silencieuse du taux de succès des caches.
- Le préflight valide les tables, profils et exclusions de workers ;
	aucun job historique n'est supprimé ou exécuté par accident.

**La livraison du script et de cette procédure ne vaut pas validation
effective d'une préproduction inaccessible depuis GitHub Actions.**
