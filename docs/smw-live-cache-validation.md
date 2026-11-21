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

## Option de sécurité transitoire (désactivée par défaut)

Le modèle `Argument` de Wikidébats **ne contient plus `__NOCACHE__`** :
les pages d'arguments sont maintenant susceptibles d'avoir des fichiers
HTML complets valides selon `page_touched` et `CacheEpoch`, sans que
leur fraîcheur par rapport aux relations SMW soit prouvée. C'est un
constat observé en production pour « Dieu est une invention » :
une consultation HTTP anonyme a renouvelé son fichier HTML, qui est
passé de `isCacheGood=false` à `isCacheGood=true`, avec Tracking=false.
Aucune édition de relation parentale n'a été réalisée sur le site public.

Lorsqu'il est impossible de valider immédiatement le suivi des dépendances SMW,
une option **distincte**, `$wgWikidebatesSemanticCacheSafeFallback = true;`,
permet de tester un comportement conservateur **en préproduction seulement**.
Elle n'active ni `WikidebatesSemanticCacheTracking`, ni les purges anticipées,
ni les anciens jobs. Tant que le suivi reste désactivé, elle :

- refuse les caches HTML natifs pour les pages reconnues comme exécutant
	des requêtes sémantiques (dont les pages `{{Argument}}`) ;
- refuse aussi ces pages dans MinervaFileCache, qui consulte le même veto ;
- rejette les anciens `ParserOutput` et empêche d'en stocker de nouveaux ;
- ignore les fragments préparés, le cache des fragments et les sources
	HTML sur disque ; force une analyse neuve pour l'API de lecture ;
- retire la cacheabilité HTTP publique de leurs vues complètes.

Les débats ne contenant aucune lecture sémantique restent éligibles aux
caches existants. Si SMW échoue à la classification, le traitement reste
conservateur : il renonce au cache plutôt que de réutiliser un contenu
potentiellement périmé.

**Limites et risques :** cette protection ajoute des lectures SMW et des
analyses PHP sur les arguments, donc doit être mesurée sur une préproduction
représentative avant déploiement. Elle reconnaît les profils `_ASK` ainsi
que les appels Argument connus dans la source ; une lecture sémantique
effectuée par un autre modèle sans profil pourrait échapper au diagnostic.
Les en-têtes applicatifs ne peuvent pas invalider une éventuelle règle
Cloudflare « Cache Everything » déjà forcée sur le domaine. Ne pas
activer `$wgUseCdn` ou une règle de cache HTML Cloudflare pour tester cette
option. Ne pas réintroduire `__NOCACHE__` comme substitut : il ne protège
pas à lui seul le cache HTML complet, et bloquerait inutilement le cache
du parseur. Ne pas modifier les règles Cloudflare en production sur la
base de cette expérience.

Le laboratoire HTTP jetable teste automatiquement Vector, Minerva et les
fragments, avec un changement réel de relation SMW pendant que les jobs
de purge restent en attente. Il vérifie aussi que le veto Minerva est
bien `BYPASS; reason=extension-veto` et non un contournement
accidentel lié à des en-têtes de débogage. Les pages sans requête
sémantique doivent conserver de vrais HIT MinervaFileCache.
Il ne remplace pas une validation de préproduction avec les
modèles et caches du site public.

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

## 1 bis. Amorcer les profils SMW des arguments historiques

**Attention : l'activation du suivi ne reconstruit pas à elle seule
les profils `_ASK` de toutes les pages déjà enregistrées.**

Un nouveau rendu anonyme par l'API ou une lecture canonique de la page
peut exécuter les requêtes tracées dans `ParserOutput`, sans enregistrer
leurs profils ni leurs liens de dépendance dans le stockage SQL SMW.
Le garde-fou doit alors maintenir le cache de lecture de l'argument
dans un état **non certifié** (`guard_safe_to_read=false`).

Un test d'intégration avec le vrai modèle `Argument`, sous SMW 7.3.1
et MediaWiki 1.43.11, reproduit ce cas sur SQLite et MariaDB. Dans
le laboratoire jetable, un **job MediaWiki standard `refreshLinks`
pour une seule page** reconstruit les données secondaires et enregistre
les profils `_ASK`, sans créer de nouvelle révision. Après cette
opération, le fragment suivi devient réutilisable et une modification
de parent invalide le cache même si la purge ciblée est différée.

Avant de commencer les tests HTTP, sur la **préproduction seulement** :

1. Choisir une page Argument historique sans `_ASK`, confirmer son
	`page_id`, sa dernière révision et sa configuration effective.
2. Avec Tracking=true et EagerPurge=false, exécuter une reconstruction
	**strictement ciblée sur ce page_id**, via le traitement MediaWiki
	`refreshLinks` (préférer une invocation contrôlée et documentée plutôt
	qu'une reconstruction générale).
3. Laisser les mises à jour secondaires SMW se terminer, puis vérifier
	que `_ASK` et les liens de dépendance sont réellement présents,
	et que la dernière révision de la page n'a pas changé.
4. Vérifier `guard_safe_to_read=true`, le comportement du cache, puis
	les relations parentales après une modification sur la copie.

Si les profils restent absents, **ne pas activer le suivi sur la
production** et examiner les erreurs SMW. La réussite de la première
lecture HTML n'est pas un critère suffisant. Ne pas lancer
`rebuildData.php`, `refreshLinks` ou une purge en masse sur le serveur
public sans plan de migration et sauvegarde distincts.

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

## 2 bis. Limiter les requêtes sur les pages Débat et diagnostiquer HTTP 429

La sonde accepte `--surfaces=vector,minerva` pour ne demander que les
deux HTML complets : les fragments `wikidebatesreading` sont conçus pour
les pages Argument, avec des bornes `Summary` et `Parent_debates`, et
peuvent donc être vides sur les pages Débat. Les surfaces disponibles sont
`vector`, `minerva`, `fragment_vector`, `fragment_minerva`.

`--delay-ms=1000` ajoute une seconde entre deux requêtes successives
(intervalle configurable de 0 à 5000 ms). Pour un contrôle strictement
en lecture seule, une seule itération suffit à vérifier l'accessibilité :

```sh
node maintenance/probeSemanticHttp.cjs \
	--base=https://fr.wikidebates.org \
	--title="Dieu existe-t-il ?" \
	--surfaces=vector,minerva \
	--iterations=1 \
	--delay-ms=1000 \
	--out=/tmp/smw-fr-debat-readonly.json
```

Si une requête échoue, notamment par HTTP 429, le rapport JSON conserve
les mesures déjà effectuées et ajoute le statut et, lorsqu'ils sont
présents, les en-têtes `Retry-After`, `CF-Ray` et `CF-Cache-Status`.
La sonde s'arrête immédiatement et ne contourne pas la limitation par
des reprises automatiques. Elle sort avec un code d'échec.

Un contrôle sur une page Débat en production ne valide **pas** la
fraîcheur après modification d'une relation parentale. Les en-têtes
`Cache-Control: private` des URL `index.php?useskin=...` ne prouvent
pas le comportement des URL publiques canoniques.

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
