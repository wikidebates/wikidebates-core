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

La durée maximale est de **300 secondes** par défaut. Les modifications de page,
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

Le préchauffage ne remplace pas les invalidations ni les contrôles habituels
de MinervaFileCache. Les très grandes arborescences peuvent occasionner des
requêtes de base de données coûteuses : commencer par `--limit=20`.

Options principales :

- `--limit=N` : s'arrêter après N pages ; 0 = aucune limite (défaut).
- `--delay-ms=N` : délai entre deux requêtes (1000 ms par défaut, minimum 250).
- `--timeout=N` : délai maximal HTTP en secondes (30 par défaut).
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
Elle s'arrête sur les erreurs HTTP (403, 429, 5xx, etc.), les erreurs de
stockage ou plusieurs réponses successives sans en-tête de diagnostic pour
éviter de surcharger PHP-FPM et Cloudflare. En cas d'interruption, relancer
la commande avec `--resume` ou reprendre après le dernier `page_id` affiché.

**Attention :** le cache est indexé par User-Agent complet, URL et langue
acceptée. Un préchauffage Android ne remplit pas toutes les variantes Safari
iPhone ou toutes les versions de Chrome. Il est donc surtout utile pour les
variantes réellement consultées et peut produire beaucoup de fichiers.

## Nettoyage et désactivation

Planifier une exécution régulière du nettoyage, par exemple toutes les heures,
depuis la racine MediaWiki et avec l'utilisateur PHP :

```sh
php maintenance/run.php MinervaFileCache:prune
```

Le nettoyage retire les fichiers expirés ou endommagés. Les petits fichiers de
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
