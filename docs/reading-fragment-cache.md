# Extraits préparés pour le mode lecture

Cette fonction fait partie de **Wikidebates**, sans nouvelle extension à charger.
Elle conserve directement le HTML compris entre les titres `Summary` et
`Parent_debates`, puis le renvoie au mode lecture, aux préchargements et aux
aperçus mobiles. Citations, références, justifications et objections restent
dans l'extrait. Le cache du navigateur et ses limites ne sont pas modifiés.

## Fonctionnement

Le client utilise `action=wikidebatesreading` pour les lectures ordinaires :

```text
Vérification du titre, des redirections, des droits et de la révision
  → extrait fichier valide : réponse immédiate avec le HTML préparé
  → sinon : rendu par ApiParse et son parser cache, extraction puis conservation
```

Un hit ne relance ni l'extraction, ni le pipeline de rendu. Il effectue encore
les contrôles d'accès et de fraîcheur, la lecture du fichier et le transfert.
Le navigateur doit toujours insérer le HTML et initialiser ses interactions.

La construction réutilise le **vrai pipeline `ApiParse` de MediaWiki 1.43**,
avec `usearticle=1`, `redirects=1`, l'habillage demandé et la langue du visiteur.
Elle ne rend pas une section de wikitexte isolée et ne contourne pas le parser
cache. L'extracteur PHP conserve les mêmes octets que l'extracteur JavaScript,
sans réécriture ou sérialisation du DOM.

Les fichiers HTML complets de Vector et de Minerva ne sont pas lus comme des
sources interchangeables : ils incluent un habillage et des transformations
qui ne garantissent pas le même extrait. Le cache d'extraits est indépendant de
`MinervaFileCache` et fonctionne également quand cette extension est absente.
C'est l'extrait déjà prêt qui constitue ici le fichier réutilisé.

## Activation et stockage

La fonction est **active par défaut après mise à jour de Wikidebates**. Aucun
`update.php` n'est nécessaire. Le code JavaScript fonctionne aussi avec une page
HTML mise en cache avant cette mise à jour ; il ne nécessite pas une purge
globale du parser cache pour découvrir la nouvelle fonction. En cas de serveur
API encore ancien pendant un déploiement progressif, il revient à `action=parse`.

Par défaut, le stockage est un répertoire privé, créé automatiquement sous
`sys_get_temp_dir()`, séparé par installation et wiki. Pour un emplacement
persistant choisi explicitement :

```sh
sudo install -d -m 0700 -o www-data -g www-data /var/cache/mediawiki/wikidebates-reading
```

Adapter l'utilisateur PHP puis ajouter à `LocalSettings.php` :

```php
$wgWikidebatesReadingCacheDirectory = '/var/cache/mediawiki/wikidebates-reading';
```

Le répertoire configuré doit déjà exister et être accessible en écriture par
PHP. Sur plusieurs hôtes PHP, préférer un stockage partagé compatible avec les
verrous `flock` et le renommage atomique pour que toutes les invalidations et
préparations utilisent le même ensemble de fichiers.

Un emplacement dans `w/cache/` est possible pour **ce cache d'extraits** si le
serveur web en interdit l'accès HTTP. Les fichiers internes doivent rester servis
par l'API, qui vérifie les droits. Exemple Nginx, à adapter au chemin public réel :

```nginx
location ^~ /w/cache/wikidebates-reading/ {
    deny all;
}
```

Avec Apache, utiliser une règle de répertoire `Require all denied`. La présence
d'un fichier `.htaccess` ne suffit que si sa prise en compte est effectivement
autorisée. Ces règles ne sont pas écrites automatiquement par l'extension.
La restriction de répertoire de l'extension autonome `MinervaFileCache` reste
inchangée ; ses fichiers et ceux du mode lecture sont distincts.

## Préparation asynchrone et invalidation

Après mise à jour des liens d'une page de la catégorie `Arguments`, un job
`wikidebatesWarmReading` est ajouté à la file. Les pages déjà connues du cache
sont aussi préparées à nouveau après une invalidation de dépendance. Les jobs
identiques sont dédoublonnés et lisent la dernière révision disponible.

Le job utilise un utilisateur anonyme, la langue du contenu et les habillages
installés Vector et Minerva. Il ne doit pas fabriquer un cache partagé dans une
requête web personnalisée : une exécution sous session connectée ou avec cookies
ne prépare pas de fichier. Un exécuteur de jobs CLI est recommandé, par exemple
avec la tâche habituelle de l'installation, sous le même utilisateur que PHP :

```sh
sudo -u www-data php maintenance/run.php runJobs --maxjobs=100
```

Adapter `www-data` à l'installation. Choisir un répertoire persistant explicite
si PHP-FPM et la CLI n'ont pas le même répertoire temporaire ; les jobs et les
requêtes web doivent lire et écrire les mêmes fichiers.

Le contexte MobileFrontend est une variante distincte de l'habillage : le
résultat peut différer selon que le formatage mobile est effectivement actif.
Un job CLI prépare les variantes correspondant à son contexte. Les variantes
de formatage mobile ou de langue qui ne sont pas encore présentes sont créées
à leur première demande réelle, en réutilisant le parser cache. Une variante
préparée en contexte desktop ne peut donc pas remplacer silencieusement la
réponse attendue en contexte mobile.

Une édition, une purge ou une invalidation HTML de dépendance retire les
variantes de la page via le pipeline de MediaWiki. Le contrôle porte aussi sur
la révision, `page_touched`, les époques et l'expiration. La révision seule serait
insuffisante après une modification de modèle.

Un verrou de publication et un numéro de génération empêchent un rendu commencé
avant une invalidation de publier ensuite sa copie périmée. Les fichiers sont
écrits dans un fichier temporaire privé, puis remplacés par renommage atomique.
La durée de conservation est plafonnée par l'expiration restante du
`ParserOutput` source. Une sortie non conservable, une réponse à nonce ou un
refus de cache du `OutputPage` ne produit pas de fichier réutilisable.

## Paramètres et retour au fonctionnement précédent

| Paramètre | Défaut | Usage |
| --- | --- | --- |
| `$wgWikidebatesReadingFragments` | `true` | Point d'accès d'extraits et cache serveur |
| `$wgWikidebatesReadingCacheDirectory` | `false` | Répertoire privé temporaire automatique, ou chemin explicite |
| `$wgWikidebatesReadingCacheTTL` | `300` | Plafond de conservation, en secondes |
| `$wgWikidebatesReadingCacheEpoch` | `'1'` | À changer lors d'une modification du rendu ou de l'extracteur |
| `$wgWikidebatesReadingPrewarm` | `true` | Préparation asynchrone par les jobs |
| `$wgWikidebatesReadingArgumentCategory` | `'Arguments'` | Catégorie des arguments à préparer après mise à jour des liens |

Pour désactiver :

```php
$wgWikidebatesReadingFragments = false;
```

Le serveur cesse immédiatement de servir les fichiers préparés et les jobs
cessent de les construire. Les nouvelles pages HTML indiquent au JavaScript de
reprendre les requêtes `action=parse`. Les clients déjà chargés peuvent continuer
à appeler le nouveau point d'accès : celui-ci construit alors l'extrait à partir
du parser cache sans utiliser de fichier préparé.

Les visiteurs connectés, les sessions persistantes et les requêtes avec cookies
ou paramètres de rendu non reconnus ne réutilisent pas les fichiers partagés.
Ils gardent le rendu de leur propre contexte. Une panne du stockage laisse le
repli sur le parser cache disponible ; le canal de log est `WikidebatesReading`.

Planifier le retrait des fichiers expirés, par exemple une fois par heure :

```sh
sudo -u www-data php maintenance/run.php Wikidebates:pruneReadingCache
```

Les fichiers de verrouillage et marqueurs de génération restent présents afin
de ne pas créer de course avec des requêtes actives.

## Vérification

```text
/w/api.php?action=wikidebatesreading&page=Nom_de_l_argument&useskin=minerva&uselang=fr&format=json&formatversion=2
```

La réponse contient `wikidebatesreading.html`, le titre résolu, l'identifiant de
page, la révision et `source`. `source=fragment-cache` confirme la restitution
d'un fichier ; `source=parser` désigne le chemin de construction, qui peut lui-même
utiliser le parser cache. Aucun diagnostic technique n'est affiché dans la lecture.

Les [tests d'intégration](../tests/readingFragments/README.md) comparent les octets
de l'extrait avec l'API existante sur Vector et Minerva, avec de vraies références
Cite. Ils vérifient aussi les contrôles d'accès, les réponses personnalisées,
l'expiration, les invalidations et les jobs. Un test Chromium vérifie le titre,
le contenu et le défilement avec les nouvelles réponses compactes.

Ces contrôles établissent le fonctionnement et l'absence de pipeline sur un hit.
Ils ne constituent pas une mesure du gain de latence ou de ressources sur le
trafic de production. Le gain de 79 % de l'étude Minerva initiale concernait un
autre prototype et ne doit pas être attribué à ce cache d'extraits.
