# Extraits préparés pour le mode lecture

L’API `action=wikidebatesreading` reste le point d’accès du lecteur, de ses
préchargements et de ses aperçus. Pour une demande anonyme éligible, elle cherche,
dans cet ordre : l’extrait valide, le fichier natif Vector valide, le fichier
MinervaFileCache valide, puis le rendu MediaWiki par ApiParse et son parser cache.
Elle conserve l’extrait obtenu. Un fichier absent ne déclenche jamais une requête
HTTP destinée à construire un autre fichier complet.

## Installation et configuration

Mettre à jour Wikidebates ; aucun `update.php` n’est nécessaire. Le cache d’extraits
est actif par défaut et ne nécessite pas MinervaFileCache. Pour bénéficier de la
seconde source, installer aussi [MinervaFileCache](../extensions/MinervaFileCache/README.md).
Activer le cache natif et choisir Vector comme habillage par défaut :

```php
$wgUseFileCache = true;
$wgDefaultSkin = 'vector'; // ou vector-2022
$wgFileCacheDirectory = '/var/cache/mediawiki/vector';
$wgWikidebatesReadingCacheDirectory = '/var/cache/mediawiki/reading';
$wgWikidebatesReadingCacheTTL = 2592000;
$wgMinervaFileCacheTTL = 2592000; // si l’extension autonome est chargée
$wgParserCacheExpireTime = 2592000;
```

Créer les répertoires sous l’utilisateur PHP. Le répertoire d’extraits doit être
privé, hors accès HTTP, accessible en écriture à PHP et à la CLI. À défaut de
chemin explicite, un répertoire privé temporaire est créé automatiquement.
Sur plusieurs hôtes, partager le stockage et les verrous `flock` avec renommages
atomiques. Exemple : `install -d -m 0700 -o www-data -g www-data /var/cache/mediawiki/reading`.
Ne pas exposer les fichiers directement : l’API vérifie toujours les droits.

| Paramètre Wikidebates | Défaut | Rôle |
| --- | --- | --- |
| `WikidebatesReadingFragments` | `true` | Active les extraits partagés ; `false` conserve l’API avec rendu MediaWiki |
| `WikidebatesReadingCacheDirectory` | `false` | Répertoire privé temporaire automatique ou chemin existant |
| `WikidebatesReadingCacheTTL` | `2592000` | Conservation maximale en secondes, plafonnée à 30 jours |
| `WikidebatesReadingCacheEpoch` | `'2'` | Version du format d’extrait |
| `WikidebatesReadingPrewarm` | `true` | Autorise la commande de préparation et les jobs explicites |
| `WikidebatesReadingArgumentCategory` | `'Arguments'` | Catégorie parcourue par la commande |

Les expirations ne sont **jamais prolongées** au-delà de celles des sources.
Le fichier natif n’a pas d’expiration pour le contenu dynamique : son utilisation
exige donc aussi un enregistrement valide du parser cache anonyme. L’âge du
fichier et la durée du ParserOutput limitent la conservation ; MinervaFileCache
conserve maintenant aussi l’échéance du ParserOutput lors de l’écriture.
Une source ancienne conservée cinq minutes reste limitée à cinq minutes.
La configuration du parser cache ci-dessus supprime son plafond général plus
court, mais les réductions nécessaires aux contenus dynamiques, les sorties non
conservables et les nonce restent respectés. Trente jours est un maximum, jamais
une garantie de durée ni une instruction de conserver tous les contenus aussi longtemps.

## Contenu commun et sécurité

Les clés d’extraits distinguent la langue, l’origine, le protocole et le wiki,
mais plus l’habillage ni le mode mobile. Un extrait Vector sert donc aussi sur
téléphone, et un extrait Minerva sur ordinateur. La source native n’est utilisée
que pour l’origine canonique, la langue du contenu et un habillage par défaut
Vector. Les entrées Minerva doivent posséder une provenance compatible et passer
les contrôles de génération, identité et expiration de leur propre stockage.
Les anciennes entrées Minerva sans provenance restent utilisables par l’extension
autonome ; le lecteur attend leur remplacement pour les utiliser comme source.

L’adaptateur conserve la plage entre `Summary` et `Parent_debates`. Il retire les
enveloppes `mf-section-*`, leurs commandes de repli et les contrôles d’édition
d’habillage ; il conserve résumés, citations, références Cite, justifications,
objections, liens et attributs des interactions du lecteur. Les contenus ne
dépendent plus d’un masquage CSS des blocs repliables Minerva. Une transformation
mobile différée non réversible est refusée comme source, avec repli sur ApiParse.
Les transformations de section définies par le contenu lui-même ne sont pas supprimées.

Les utilisateurs connectés gardent le rendu de leur contexte, sans fichiers
partagés. Cookies, session persistante, authentification HTTP, messages personnels,
paramètres de rendu inconnus, requêtes Range/AJAX et demandes sans cache empêchent
également la réutilisation. Les droits et les révisions supprimées sont contrôlés
avant lecture, y compris à chaque étape d’une redirection. Les veto de cache des
extensions restent vérifiés pour les sources ; seul le veto MobileFrontend connu
contre le cache natif mobile est ignoré dans cet adaptateur MediaWiki 1.43.

## Invalidation et préparation quotidienne

Éditions, purges, dépendances, `page_touched`, révisions et `CacheEpoch` invalident
les extraits. Une modification du rendu commun doit avancer `CacheEpoch` pour
invalider aussi les fichiers complets, et l’époque Minerva si son rendu change.
L’époque des extraits seule invalide leurs fichiers, sans purger leurs sources.
Les verrous et générations empêchent une publication commencée avant invalidation.

Aucune édition ne programme désormais un rendu d’extrait. Les demandes réelles
remplissent les absences ; une page complète déjà rendue peut préparer l’extrait
dans le même passage, après les contrôles de réponse publique, sans second rendu.
Les anciens jobs encore présents revérifient la validité avant tout travail et
ne préparent plus deux variantes d’habillage.

Planifier quotidiennement, sous le même utilisateur que PHP :

```cron
15 3 * * * www-data php /srv/mediawiki/maintenance/run.php Wikidebates:prepareReadingCache --batch-size=100 --sleep-ms=100
```

Adapter chemins et utilisateur ; omettre l’utilisateur dans une crontab personnelle.
La commande parcourt la catégorie des arguments, sans récursion, par identifiants
croissants. Elle ignore les redirections et les extraits valides. Un seul processus
par wiki travaille séquentiellement ; les préparations web utilisent aussi le verrou
non bloquant de page. Options : `--limit=500`, `--after=123`, `--batch-size=100`
(1–1000), `--sleep-ms=100`. Le curseur affiché permet une reprise manuelle. Sans
`--after`, chaque passage quotidien recontrôle toute la catégorie, y compris les
pages anciennes expirées. Les variantes linguistiques supplémentaires se préparent
à la demande. `WikidebatesReadingPrewarm=false` désactive cette préparation explicite.

Planifier aussi le nettoyage :

```sh
php maintenance/run.php Wikidebates:pruneReadingCache
```

Les fichiers de verrouillage et de génération restent présents. Une indisponibilité
du stockage laisse le repli MediaWiki disponible ; journal : `WikidebatesReading`.

## Validation et mesures

`source` vaut `fragment-cache`, `vector-file`, `minerva-file` ou `parser` dans
la réponse API. Aucun diagnostic technique n’est ajouté à l’interface du lecteur.
Les [tests](../tests/readingFragments/README.md) forcent les deux sources réelles,
comparent leur contenu et contrôlent droits, sessions, invalidations et navigation.
Voir la [mesure locale comparative](reading-file-source-benchmark.md) pour les
latences, CPU, mémoire et limites. Le gain de 79 % de l’ancienne étude Minerva
ne s’applique pas à cette stratégie.
