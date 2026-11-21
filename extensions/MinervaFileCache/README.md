# MinervaFileCache

Extension autonome pour **MediaWiki 1.43.x**, **MobileFrontend 2.4.x** et
**MinervaNeue**. Elle conserve le HTML complet des pages mobiles dans un stockage
distinct du cache desktop. Elle ne dépend d'aucune extension propre à un wiki, ne change pas le
contenu à lire et ne demande aucune modification du code de MediaWiki ou de
MobileFrontend.

Dès que l'extension est chargée et que son répertoire de cache est utilisable,
**toutes les pages mobiles éligibles sont couvertes**. Il n'y a ni liste de
pages, ni paramètre de test à ajouter aux URL. Le cache est activé par défaut ;
un répertoire absent ou inutilisable laisse MediaWiki afficher les pages normalement.

## Installation et activation

1. Copier **ce dossier complet**, avec son `extension.json`, dans
   `extensions/MinervaFileCache` de chaque installation MediaWiki. Ne copier
   que cette extension : aucune autre extension spécifique à un site n'est
   nécessaire.

2. Créer un répertoire **hors de la racine web**, accessible en écriture par le
   processus PHP. Adapter `www-data` à l'utilisateur réel de PHP-FPM :

   ```sh
   sudo install -d -m 0700 -o www-data -g www-data /var/cache/mediawiki/minerva
   ```

3. Après le chargement existant de MinervaNeue et MobileFrontend, ajouter dans
   `LocalSettings.php` :

   ```php
   wfLoadExtension( 'MinervaFileCache' );

   // Conserver le répertoire et les réglages du cache desktop déjà en place.
   $wgUseFileCache = true;

   $wgMinervaFileCacheDirectory = '/var/cache/mediawiki/minerva';
   ```

   `$wgUseFileCache` doit être actif : MediaWiki appelle le point d'intégration
   des vues à cette condition. Ne pas déplacer le cache desktop vers le nouveau
   répertoire. Aucun `update.php`, changement de base ou préchauffage n'est requis.
   Les fichiers mobiles sont créés à la première visite éligible. Il n'est pas
   nécessaire de définir `$wgMinervaFileCacheEnabled` : sa valeur par défaut
   est `true`. Sans répertoire valide, la classe d'affichage habituelle de
   MediaWiki est conservée. Le chemin doit aussi être autorisé par `open_basedir`
   si PHP-FPM applique cette restriction.

Les wikis français et anglais peuvent utiliser le même répertoire si le même
processus PHP y accède : le stockage est séparé par identifiant de wiki. Sur
plusieurs serveurs PHP, utiliser un système de fichiers partagé qui prend en
charge `flock` et le renommage atomique ; des disques locaux indépendants ne
reçoivent pas tous les invalidations.

## Vérifier le fonctionnement

Faire deux GET identiques sur une page publique, avec un User-Agent mobile, sans
connexion et sans cookies. Ne pas utiliser `curl -I`, qui envoie une requête HEAD.

```sh
curl -sS --compressed -D - -o /dev/null \
  -A 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36' \
  'https://wiki.example.org/wiki/Titre_de_la_page'
```

Répéter exactement la commande. L'en-tête `X-Minerva-File-Cache` doit indiquer
`MISS`, puis `HIT`. `X-Minerva-File-Cache-Age` donne l'âge d'un hit en secondes.
Une visite à l'ordinateur continue d'utiliser le cache desktop habituel.
La requête doit atteindre MediaWiki : une page de challenge Cloudflare ou une
réponse déjà conservée par un intermédiaire ne prouve rien sur ce cache.

`BYPASS; reason=…` signifie que le rendu habituel a été conservé :

| Motif | Signification |
| --- | --- |
| `cookie` / `session` | Cookies présents, visiteur connecté ou session persistante |
| `query` / `request-headers` | Variante de lecture, requête AJAX, plage ou demande sans cache |
| `variant` / `page` | Langue/skin différent, redirection ou notification personnelle |
| `extension-veto` | Refus d'une extension, ou intégration MobileFrontend non reconnue |
| `nonce` / `csp-nonce` | Réponse avec nonce ; elle ne sera pas partagée entre visiteurs |
| `parser-expiry` | Sortie d'analyse sans expiration cache valide (par exemple avec `__NOCACHE__`) |
| `not-public` / `redirect` / `http-status` / `headers-sent` | Réponse non publique, redirection, statut différent de 200 ou en-têtes déjà envoyés |
| `cache-control` / `response` | Réponse non conservable, personnalisée ou autre qu'un succès HTML |
| `header-…` / `vary` | En-tête ou source de variation non pris en charge |
| `storage-error` | Répertoire ou stockage indisponible ; détails dans le canal de log `MinervaFileCache` |
| `busy` / `not-stored` | Remplissage concurrent, invalidation pendant le rendu ou réponse trop volumineuse |

Sans en-tête, vérifier le chargement dans `Special:Version`, la configuration
d'un répertoire de cache privé accessible, `$wgUseFileCache`, le véritable
affichage mobile et les éventuelles extensions qui fournissent leur propre
classe `Article`. Si le stockage est indisponible dès la création de l'article,
l'extension ne remplace pas sa classe et n'ajoute aucun en-tête de diagnostic. Désactiver les diagnostics en
mettant `$wgMinervaFileCacheDebugHeaders = false` si souhaité.

## Périmètre et conservation

- GET de la vue courante en Minerva, visiteur anonyme sans cookies, langue du
  contenu et permissions de lecture vérifiées **avant** de servir le fichier.
- Toutes les pages normales, y compris discussions, catégories et descriptions
  de fichiers. Les classes natives `CategoryPage` et `ImagePage` sont conservées.
- Sessions connectées, cookies, notifications personnelles, redirections,
  anciennes révisions, formulaires, actions API et pages spéciales exclus.
- Un fichier distinct par URL, User-Agent complet, langue acceptée et variante
  de sous-domaine. Cela conserve les différences entre navigateurs ; un nouveau
  navigateur ou une nouvelle version commence avec un `MISS`.
- Le HTML et les en-têtes de sécurité compatibles sont restitués ensemble.
  Aucune politique CSP n'est affaiblie : les réponses à nonce sont exclues.
  Les réponses qui posent un cookie ou déclarent un `Vary` inconnu sont exclues.
- Les réponses conservées portent `Cache-Control: private, no-store, max-age=0`.
  Le cache se trouve sur le serveur PHP ; aucun cache partagé en amont n'est
  nécessaire. Cloudflare et le serveur web doivent respecter ces en-têtes.
- Les fichiers sont compressés sur disque. La compression HTTP reste gérée par
  MediaWiki, PHP ou le serveur web, avec les réglages existants.

La durée maximale est de **2 592 000 secondes (30 jours)** par défaut. Les modifications de page,
purges et invalidations de dépendances retirent les variantes mobiles via le
pipeline HTML de MediaWiki. Les jobs de mise à jour des dépendances doivent
fonctionner, comme pour les autres caches MediaWiki.

Les paramètres facultatifs sont :

```php
$wgMinervaFileCacheTTL = 2592000; // Maximum 30 jours, limité aussi par le ParserOutput.
$wgMinervaFileCacheMaxBytes = 4194304; // Taille maximale du HTML par fichier.
$wgMinervaFileCacheDebugHeaders = true;
$wgMinervaFileCacheEpoch = '1';
```

Changer `MinervaFileCacheEpoch` à chaque déploiement qui modifie le HTML PHP,
l'habillage ou des réglages de rendu. Cela invalide les anciennes entrées
immédiatement. Une purge de la page ou une édition ordinaire ne nécessite pas
de changer ce réglage.

## Préchauffage des pages

La commande de maintenance `prewarm` visite les pages avec de **vraies requêtes
HTTP GET mobiles anonymes**, sans cookies. Elle ne remplit pas directement
les fichiers depuis le parseur : elle respecte donc les règles d'éligibilité,
de sécurité et de variation de MinervaFileCache.

Depuis la racine MediaWiki :

```sh
# Premier test limité à 20 pages, avec point de reprise.
php maintenance/run.php MinervaFileCache:prewarm --limit=20 --resume

# Continuer ensuite sur toutes les pages restantes.
php maintenance/run.php MinervaFileCache:prewarm --resume
```

Dans une ferme de wikis, ajouter `--wiki=IDENTIFIANT` à chaque commande,
puis recommencer pour chacun des wikis concernés.

Par défaut, toutes les pages non redirigées, de **tous les espaces de noms**
enregistrées dans la table `page` sont parcourues par lots de 100.
Certaines réponses restent exclues par la politique du cache (`BYPASS`).
Les pages protégées en lecture ne deviennent jamais publiques.

### Préchauffer une catégorie

La commande peut également traiter **uniquement les pages membres d'une
catégorie** MediaWiki. Le nom est accepté avec ou sans préfixe `Category:`
(ou son équivalent localisé) :

```sh
# Pages directement membres de la catégorie (simulation).
php maintenance/run.php MinervaFileCache:prewarm --category="Articles" --dry-run --limit=20

# Membres de la catégorie et de toutes ses sous-catégories.
php maintenance/run.php MinervaFileCache:prewarm \
	--category="Articles" --recursive --limit=20 --resume

# Reprendre ce même préchauffage après le premier lot.
php maintenance/run.php MinervaFileCache:prewarm \
	--category="Articles" --recursive --resume
```

Sur une ferme, ajouter `--wiki=IDENTIFIANT`. L'option `--recursive`
est interdite sans `--category`. Elle descend dans l'arborescence à
tous les niveaux, sans boucle ni double visite lorsqu'une page appartient
à plusieurs catégories. `--namespace` peut limiter les membres aux pages
d'un espace de noms. La sélection comprend les pages de sous-catégories
elles-mêmes si elles sont des membres éligibles et que leur espace de noms
n'est pas exclu.

Les catégories peuvent exister sans page `Category:` : les liens
`categorylinks` suffisent à déterminer leurs membres. Une catégorie sans
membre produit simplement zéro page. Le point de reprise est distinct pour
chaque catégorie et pour les modes direct/récursif. Si les appartenances
changent pendant un parcours interrompu, utiliser `--reset --resume` pour
reprendre depuis le début et inclure les nouvelles pages anciennes.

Le délai par défaut de 6 secondes évite qu'un préchauffage continu non exempté
dépasse, à lui seul, la limite individuelle courante de CrawlerBurstProtection
(80 consultations anonymes par 300 secondes). D'autres requêtes partageant
la même adresse IP peuvent néanmoins déclencher une limitation. Un HTTP 429
interrompt le parcours ; si le serveur fournit un délai `Retry-After` en
secondes, celui-ci figure dans le message d'arrêt. Attendre la fin du
blocage avant de reprendre avec `--after-id` ou `--resume`.

Le préchauffage ne remplace pas les invalidations ni les contrôles habituels
de MinervaFileCache. Les très grandes arborescences peuvent occasionner des
requêtes de base de données coûteuses : commencer par `--limit=20`.

Options principales :

- `--limit=N` : s'arrêter après N pages ; 0 = aucune limite (défaut).
- `--delay-ms=N` : délai entre deux requêtes (6000 ms par défaut, minimum 250).
- `--timeout=N` : délai maximal HTTP en secondes (30 par défaut).
- `--retries=N` : jusqu'à N tentatives supplémentaires (0 à 3, **0 par défaut**) uniquement pour les erreurs transitoires `HTTP_429`, `HTTP_500`, `HTTP_502`, `HTTP_503`, `HTTP_504` et les erreurs réseau. Respecte `Retry-After` (secondes ou date HTTP) jusqu'à 120 secondes ; au-delà, s'arrête pour permettre une reprise manuelle sans insister prématurément.
- `--device=android|iphone` : User-Agent à préchauffer (Android par défaut).
- `--namespace=N` : ne parcourir qu'un espace de noms numérique.
- `--category="Nom"` : ne parcourir que les membres directs de cette catégorie.
- `--recursive` : inclure tous les niveaux de sous-catégories (nécessite `--category`).
- `--dry-run` : afficher les URL sans envoyer de requêtes.
- `--resume` : sauvegarder un point de reprise privé après chaque page dans
  le répertoire de cache, et continuer automatiquement au prochain lancement.
  Le point de reprise disparaît lorsque tout le wiki a été parcouru.
- `--reset --resume` : repartir de la première page ; chaque combinaison
  wiki/domaine/langue/appareil/namespace possède son propre point de reprise.
- `--after-id=N` : reprendre manuellement après l'identifiant SQL `page_id`
  N, sans `--resume`.

La commande nécessite `$wgUseFileCache = true`, un stockage accessible et
`$wgMinervaFileCacheDebugHeaders = true` pour confirmer `MISS` et `HIT`.
Elle utilise le domaine de `$wgServer` et ne suit pas les redirections.
Elle signale le statut HTTP réel (par exemple `HTTP_403`, `HTTP_429` ou
`HTTP_503`) et distingue `TRANSPORT_ERROR` des réponses HTTP. Les réponses
`HTTP_404` et `HTTP_410` n'interrompent pas le parcours.
Elle s'arrête sur les autres erreurs HTTP, les erreurs réseau, les erreurs de
stockage ou plusieurs réponses successives sans en-tête de diagnostic pour
éviter de surcharger PHP-FPM et Cloudflare. En cas d'interruption, relancer
la commande avec `--resume` ou reprendre après le dernier `page_id` affiché.

Pour un préchauffage soumis ponctuellement aux limites HTTP, préférer un
nombre réduit de nouvelles tentatives (par exemple
`php maintenance/run.php MinervaFileCache:prewarm --wiki=fr --resume --retries=1`).
Les refus permanents (notamment 403) ne sont jamais réessayés automatiquement.
Si l'en-tête `Retry-After` impose plus de deux minutes d'attente, le préchauffage
s'arrête et conserve son point de reprise.


**Attention :** le cache est indexé par User-Agent complet, URL et langue
acceptée. Un préchauffage Android ne remplit pas toutes les variantes Safari
iPhone ou toutes les versions de Chrome. Il est donc surtout utile pour les
variantes réellement consultées et peut produire beaucoup de fichiers.

## Pages froides et répertoires vides

Sur une page jamais stockée, MinervaFileCache vérifie désormais l'absence de
génération **sans créer de répertoire ni de verrou**. Si le HTML final est
non cacheable, aucun fichier n'est créé pour cette simple consultation.
Lorsqu'il est cacheable, la première publication crée la génération sous
verrou exclusif ; une purge intervenue pendant le rendu empêche cette
publication, de la même manière que pour un cache déjà initialisé.

Cette optimisation ne supprime pas les répertoires historiques. Les purges
et invalidations créent encore un marqueur de génération pour empêcher la
publication d'un rendu commencé avant leur exécution. Ne pas supprimer
automatiquement les répertoires contenant des fichiers `page.lock` ou
`fill.lock` pendant que PHP-FPM ou des jobs utilisent le stockage :
supprimer un verrou ouvert introduirait une course d'invalidation.

Le nombre de répertoires sans fichiers `.cache` ne suffit donc pas à
déterminer la source des créations (anciennes lectures, purges ou fichiers
expirés). Le meilleur indicateur d'efficacité reste l'observation des
`HIT`, `MISS` et `BYPASS` sur de vrais accès, complétée par les
statistiques et audits ci-dessous.

## Diagnostic du stockage

Avant d'envisager un regroupement des navigateurs et langues, mesurer la
fragmentation réelle du stockage par wiki :

```sh
php maintenance/run.php MinervaFileCache:stats --wiki=fr
php maintenance/run.php MinervaFileCache:stats --wiki=en
```

La commande renvoie le nombre de répertoires de pages (`pages`), le nombre
de fichiers de contenu (`files`), leur taille compressée totale en octets
(`bytes`) et le maximum de variantes dans un répertoire de page
(`maxVariantsPerPage`), les pages ayant plusieurs variantes
(`pagesWithMultipleVariants`) et leur répartition (`variantDistribution`).
Elle ne décompresse pas le contenu et ne modifie aucune entrée. Elle parcourt toutefois les fichiers : à exécuter en maintenance,
pas à chaque requête web. Ces chiffres ne sont pas des taux de HIT/MISS.

Pour mesurer si certaines variantes déjà stockées ont produit le même HTML et
les mêmes en-têtes, lancer un audit *en lecture seule*, limité en volume :

```sh
php maintenance/run.php MinervaFileCache:variants --wiki=fr --limit-pages=100 --max-variants=20
php maintenance/run.php MinervaFileCache:variants --wiki=en --limit-pages=100 --max-variants=20
```

Pour un wiki ayant environ 28 000 répertoires et jusqu'à 206 variantes
par page, les bornes explicites peuvent être augmentées en maintenance
(jusqu'à 50 000 répertoires et 250 variantes par page) :

```sh
php maintenance/run.php MinervaFileCache:variants --wiki=fr --limit-pages=30000 --max-variants=250
```

Cela parcourt davantage de répertoires et décompresse davantage de réponses :
choisir un créneau de faible charge si le serveur est sollicité.

`scannedPages` indique le nombre de répertoires visités,
`pagesWithMultipleFiles` ceux contenant plusieurs fichiers,
`validEntries` les entrées non expirées, décodées et contrôlées,
`identicalExtraEntries` le nombre de fichiers supplémentaires strictement
identiques (même identité, mêmes en-têtes et mêmes octets HTML) et
`divergentPages` les pages ayant au moins deux empreintes complètes différentes.

Pour interpréter ces différences, l'audit sépare maintenant leurs causes :

- `comparablePages` : pages possédant au moins deux entrées avec **la même
  identité** (révision, date de modification, époques de cache et format).
- `pagesWithIdentityDifferences` : pages avec plusieurs identités d'entrées ;
  ces identités ne sont jamais comparées entre elles pour conclure sur le HTML.
- `pagesWithHtmlDifferences` et `pagesWithHeaderDifferences` : pages où
  respectivement les octets HTML ou les en-têtes diffèrent **pour une même
  identité**. Une page peut apparaître dans les deux compteurs.
- `sameIdentityHtmlDuplicates` et `sameIdentityHeaderDuplicates` :
  fichiers supplémentaires avec respectivement un HTML ou des en-têtes
  identiques au sein de chaque groupe de même identité. Ces totaux ne
  prouvent pas à eux seuls que les réponses complètes sont interchangeables.

Les compteurs supplémentaires `volatileMetadataEntries`,
`volatileComparablePages`, `pagesWithOnlyVolatileHtmlDifferences`,
`pagesWithResidualHtmlDifferences` et
`sameIdentityHtmlDuplicatesIgnoringVolatile` servent à vérifier l'hypothèse
selon laquelle deux champs JavaScript propres à la requête, `wgRequestId`
et `wgBackendResponseTime`, expliquent les différences HTML. L'audit
remplace **uniquement en mémoire, pour ses empreintes**, ces deux valeurs
dans les blocs `<script>` où chacune apparaît exactement une fois. Les
groupes comparés doivent avoir la même identité de cache et tous leurs
fichiers doivent contenir ces deux champs exactement une fois.

`pagesWithOnlyVolatileHtmlDifferences` compte les pages dont au moins un
groupe d'identité diffère en HTML brut mais devient identique après cette
neutralisation, sans différence résiduelle dans les groupes comparables ;
`pagesWithResidualHtmlDifferences` compte les pages dont au moins un
groupe présente encore des différences après neutralisation.
`sameIdentityHtmlDuplicatesIgnoringVolatile` compte les fichiers HTML
supplémentaires identiques à l'intérieur de ces groupes après
neutralisation. Les pages avec champs absents, multiples ou non reconnus
restent hors de cette comparaison : aucun résultat n'est extrapolé.

Pour déterminer si les différences restantes sont limitées aux scripts,
l'audit compare également, **en mémoire seulement**, une empreinte du HTML
neutralisé dont tous les éléments `<script>` ont été retirés. Il ajoute :

- `pagesWithResidualScriptOnlyDifferences` : pages ayant des différences
  après neutralisation des deux champs, mais aucun écart en dehors des scripts
  dans les groupes comparables ;
- `pagesWithResidualOutsideScripts` : pages présentant au moins un écart
  situé en dehors des scripts dans ces groupes ;
- `sameIdentityHtmlDuplicatesIgnoringScripts` : entrées HTML supplémentaires
  identiques lorsque les scripts entiers sont exclus de l'empreinte.

Retirer les scripts ici est **uniquement une méthode de localisation des écarts** :
les scripts peuvent contenir des données importantes pour le navigateur ou
pour l'utilisateur. Ces indicateurs ne sont pas une preuve que les variantes
sont interchangeables. Les pages dont certains fichiers n'ont pas les deux
champs de configuration attendus sont exclues de cette comparaison.

Un niveau de diagnostic supplémentaire distingue les zones où se situent
les différences **en dehors des scripts**. Pour chaque groupe partageant la
même identité de cache, si tous les fichiers contiennent exactement une zone
`<head>` et une zone `<body>` correctement ordonnées, l'audit ajoute :

- `regionEntries` et `regionComparablePages` : nombres d'entrées et de
  pages éligibles à cette comparaison par zones ;
- `pagesWithResidualHeadDifferences` : différences dans `<head>`, hors scripts ;
- `pagesWithResidualBodyDifferences` : différences dans `<body>`, hors scripts ;
- `pagesWithResidualShellDifferences` : différences autour de ces deux zones
  (par exemple attributs de `<html>`) ;
- `pagesWithResidualBodyTextDifferences` : différences dans le texte extrait
  du `<body>` après suppression des balises et des blocs `<style>` ;
- `pagesWithResidualBodyMarkupOnlyDifferences` : différences du `<body>`
  dont le texte extrait est stable dans tous les groupes comparables de
  la page, ce qui suggère une variation d'attributs ou de balisage.

Les compteurs par zone peuvent se recouper : une page peut présenter des
écarts dans `<head>` et dans `<body>`. Le « texte extrait » est une
approximation fondée sur `strip_tags`, **pas** une comparaison du texte
effectivement affiché ou du résultat du parseur MediaWiki. Aucun contenu,
nom de page, User-Agent ou valeur individuelle de la requête n'est publié.
Un document incomplet ou non reconnu est laissé hors de cette classification.

**Aucun HTML n'est modifié sur disque ou lors de l'affichage**, et ces
statistiques ne justifient pas de regrouper automatiquement les navigateurs.
Une même empreinte après neutralisation ne signifie pas que le
`wgRequestId` enregistré dans le cache est celui de la requête courante.

Les compteurs `truncatedPages`, `ignoredEntries` et `racedPages` signalent
les limites de l'échantillon, les fichiers non valides et les invalidations
pendant l'audit. Une entrée dont l'identité interne est cohérente n'est pas
nécessairement la dernière révision du wiki : seul MediaWiki connaît l'identité
courante de chaque page lors de sa consultation.

Cette mesure décompresse volontairement un échantillon de fichiers : l'utiliser
en maintenance, et éviter les très grands seuils sur un serveur occupé. Elle ne
produit ni URL, ni User-Agent individuel, ni contenu de page ; elle ne modifie
pas les fichiers. Les répertoires sont parcourus dans l'ordre du système de
fichiers, sans tirage aléatoire : les résultats ne sont **pas** nécessairement
représentatifs du trafic.

Une identité HTML observée entre variantes n'autorise **pas** à fusionner
automatiquement les User-Agents ou les langues : les futures réponses pourraient
différer. Les regroupements éventuels nécessitent encore des comparaisons sur
les rendus complets représentatifs et des vérifications de cache en intégration.

La clé stricte par URL, User-Agent et préférences linguistiques est conservée
pour éviter les collisions de variantes non validées.

## Nettoyage et désactivation

Planifier une exécution régulière du nettoyage, par exemple toutes les heures,
depuis la racine MediaWiki et avec l'utilisateur PHP :

```sh
php maintenance/run.php MinervaFileCache:prune
```

Le nettoyage retire les fichiers expirés ou endommagés. Il parcourt
les répertoires et les variantes progressivement, sans constituer en mémoire
la liste de tous les fichiers du wiki : son empreinte mémoire reste ainsi
limitée même si le cache contient de nombreuses pages. Les nouvelles
entrées enregistrent leur échéance dans l'horodatage du fichier (mtime) :
le nettoyage horaire peut ainsi ignorer rapidement les fichiers encore valides
sans les décompresser. Les anciens fichiers restent compatibles : ils sont
vérifiés puis leur horodatage est migré lors du prochain nettoyage. Cette
optimisation ne remplace jamais la validation de l'échéance et des permissions
lors du service d'une page. Un fichier devenu endommagé tout en conservant un
horodatage futur peut ne pas être effacé immédiatement, mais ne peut pas être
servi comme entrée valide. Les petits fichiers de
verrouillage et de génération sont conservés pour éviter une course avec les
requêtes actives. Surveiller l'espace disque et les inodes, notamment parce que
les User-Agents ont des variantes distinctes.

Le cache est actif par défaut lorsque l'extension est chargée et son stockage
est disponible. Pour revenir immédiatement au rendu mobile habituel :

```php
$wgMinervaFileCacheEnabled = false;
```

Les fichiers existants ne seront plus servis. Il est possible de laisser
l'extension chargée : les invalidations continueront. Pour une suppression
complète du stockage, arrêter d'abord les requêtes PHP qui l'utilisent avant
de retirer son répertoire.

## Compatibilité et tests

Cette version vise **MediaWiki 1.43 uniquement**. Pour préserver les vetos des
autres extensions tout en contournant le seul blocage mobile de MobileFrontend,
l'adaptateur utilise l'accesseur de callbacks de hooks encore présent en 1.43,
mais déprécié depuis 1.41. Il ne modifie aucun hook global. Seul le callback exact
`MobileFrontendHooks::onHTMLFileCache__useFileCache` est ignoré ; tout autre veto
reste actif. Une version de MobileFrontend non reconnue conserve le rendu normal.

L'extension est autonome ; seul le banc d'intégration dépend d'un laboratoire
MediaWiki. Les tests unitaires se lancent avec :

```sh
php extensions/MinervaFileCache/tests/unit.php
```

Le [mode d'emploi des tests d'intégration](tests/README.md) précise les versions,
les commandes et les limites des contrôles. La mesure de 79 % de l'étude initiale
porte sur le prototype et une page synthétique, pas sur le trafic de production
ni sur une garantie de gain de cette extension. Les appels `action=parse` du
mode lecture continuent de suivre leur chemin actuel et leur parser cache.


### Durée des sources du lecteur

La durée par défaut est maintenant de 30 jours maximum. Une écriture conserve
également l’expiration restante du ParserOutput ; les contenus dynamiques gardent
leurs durées réduites. Pour les pages stables, vérifier aussi
`$wgParserCacheExpireTime = 2592000` : conserver son plafond plus court empêcherait
d’atteindre cette durée. Les anciennes entrées ne voient pas leur échéance prolongée.
Quand Wikidebates est chargé, le lecteur peut réutiliser ces fichiers après le
cache natif Vector ; l’extension reste installable et utilisable seule.
