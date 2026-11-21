# Purges semantiques : optimisation et reprise controlee

## Perimetre

MediaWiki 1.43.11, SMW 7.3.1 et PHP 8.4. Branche experimentale
`audit/smw-semantic-cache-invalidation-2026-11`, PR #11. Ce document ne vaut
ni autorisation de fusion dans `main`, ni activation, ni deploiement en production.
Les indications de configuration et les 144 jobs anglais proviennent du diagnostic
fourni par l'administrateur ; leur etat actuel n'a pas ete releve sur le serveur.

Le correctif optimise la purge facultative. Il ne remplace pas les controles de
fraicheur lors des lectures : suivi transactionnel `wd_smw_entity_revisions`,
empreintes des dependances, refus des fragments et ParserOutput incertains,
protection des fichiers HTML des pages semantiques. Aucun retrait de `__NOCACHE__`
ni modification des modeles ou configurations de production n'est effectue ici.

## Diagnostic et choix

Les proprietes largement partagees conduisent plusieurs jobs a rencontrer les
memes requetes. Le code precedent limitait chaque execution a 150 sous-objets de
requete et recreait une continuation meme lorsque le dernier lot etait exactement
plein. Une taille de file presque stable ne signifie donc pas necessairement une
boucle : un job consomme peut etre remplace par sa continuation.

Les identifiants `_ASK*` sans liens ne suffisent pas a expliquer des milliers de
continuations. Aucun identifiant de production ni nom de propriete n'est exclu
arbitrairement. Le diagnostic fournit des indices de recouvrement, pas une mesure
exacte de toutes les purges effectivement redondantes.

La solution conserve le curseur `s_id > cursor`, au lieu de changer l'unite des
144 anciens curseurs. Elle traite 1000 identifiants distincts par defaut, lit une
ligne supplementaire pour savoir s'il reste du travail, et resout les pages
proprietaires dans une seule jointure sur la base primaire. Les resultats sont
regroupes par `page_id` avant invalidation. La continuation n'est publiee qu'apres
le traitement reussi du lot ; un echec garde le curseur initial pour la reprise.
Sur un graphe fini et stabilise, chaque continuation avance strictement vers la
fin. Une croissance permanente du graphe n'a pas de borne de terminaison promise.

Une table facultative `wd_smw_purge_state` memorise les purges de pages terminees,
non des reservations de travail. Son empreinte comprend la revision et l'identite
de la page, ainsi que tous les triplets requete / dependance / revision du graphe
`_ASK` enregistre. La generation locale du stockage des fragments doit aussi
correspondre. Les requetes attendues mais absentes et les profils sans liens
interdisent la reutilisation. L'etat est compare avant et apres invalidation ; une
modification detectee pendant celle-ci interdit d'enregistrer un nouveau recu.
Une perte ou un remplacement concurrent de recu peut provoquer plus de travail,
mais ne constitue pas une reservation pouvant faire disparaitre une purge.

Les nouveaux jobs recoivent aussi une signature des revisions des dependances,
capturee apres leur incrementation et avant le callback differe. Seuls les jobs
exactement equivalents en dependances, revisions et curseur peuvent partager
leur identite entre pages sources. Une nouvelle revision, meme dans la meme
seconde, donne une autre identite. Les anciens jobs restent des anciens jobs :
ni ajout obligatoire de parametre, ni remise a zero de leur curseur, ni suppression.

**Limite volontaire :** les ensembles seulement partiellement communs continuent
a parcourir leurs liens SQL. Les recus evitent des invalidations repetees, pas
tous les scans. Il n'y a pas de coordinateur global ni de verrou durable sur une
propriete. Des travailleurs simultanes peuvent encore faire la meme purge. Les
lectures supplementaires des recus peuvent couter davantage lorsque le
recouvrement est faible ; mesurer les vrais lots avant de les agrandir.

## SQL et caches

Les lectures de progression, de resolution des pages, d'empreintes et de recus
utilisent `newPrimarySelectQueryBuilder()`. L'ecriture des recus utilise
`newReplaceQueryBuilder()` : la facade SMW 7.3.1 n'expose plus `replace()`.
La migration ajoute uniquement une table propre a l'extension, avec cle primaire
`page_id`, via `LoadExtensionSchemaUpdates`, pour MariaDB/MySQL et SQLite.
Sans cette table, les jobs restent executables mais ne mutualisent pas les purges
de pages entre lots. La table des revisions semantiques reste indispensable.

Le schema SMW 7.3.1 declare deja les index `s_id`, `o_id` et `(s_id,o_id)` sur
`smw_query_links`. Aucun index SMW supplementaire n'est impose. La presence reelle
de ces index et les plans `EXPLAIN` doivent etre controles sur la base concernee
avant d'envisager, notamment, un index inverse `(o_id,s_id)`. Il n'y a pas eu de
mesure des plans ni des latences SQL en production.

La purge invalide strictement le stockage des fragments, le cache du parseur et
la page, puis utilise `HTMLCacheUpdater::purgeTitleUrls(..., PURGE_NAIVE)` pour
emettre immediatement les purges HTML/CDN et les hooks associes, dont
`TitleSquidURLs`. Elle n'appelle pas `WikiPage::doPurge()` / `ArticlePurge` et ne
doit pas marquer SMW pour une reecriture semantique. Les compteurs
`invalidations` concernent les pages traitees, pas le nombre d'operations internes
ni les paquets reseau CDN. Un retour du moteur de purge n'est pas un accuse de
reception du fournisseur CDN. Les protections de lecture restent essentielles.

## Reglages et diagnostic

Valeurs par defaut, deja declarees dans `extension.json` :

```php
$wgWikidebatesSemanticCachePurgeBatchSize = 1000;
$wgWikidebatesSemanticCachePurgeLog = false;
```

La taille est bornee entre 1 et 5000 requetes, non pages. Il est possible de la
reduire sur une installation contrainte. Activer temporairement le second
reglage produit un evenement `Semantic purge batch` sur le canal
`WikidebatesSemanticCache`, avec un champ JSON `metrics`. Router ce canal vers un
journal prive existant et inscriptible selon la configuration MediaWiki du site.
Les erreurs sont journalisees meme si les compteurs detailles sont desactives.

Champs : `dependencies`, `cursor`, `batch_size`, `queries_examined` (incluant la
ligne d'anticipation), `queries_processed`, `pages`, `invalidations`,
`coalesced_pages`, `continuations`, `coalescing_available`, `status`, `duration_ms`.
Le suivi complementaire ajoute `next_cursor` (uniquement apres acceptation de la
continuation), `generation_reads` (appels de lecture explicites du job, pas toutes
les E/S internes) et `logging_failures` (visible via `getMetrics()` meme si le
journal est indisponible). Un journaliseur defaillant ne change plus le resultat
du job et ne masque pas la classe de l'exception originale.
`getMetrics()` expose les memes compteurs aux tests. Aucun titre ni contenu de
page n'est ajoute a cet evenement. Ces reglages n'activent ni Tracking ni
EagerPurge et ne changent pas le dispositif manuel de production.

Le job ne relit plus la generation d'un fragment avant invalidation lorsque son
recu est absent ou son empreinte deja differente. Une reutilisation candidate
verifie toujours la generation locale et le graphe complet. Sans table de recus,
aucune lecture de generation n'est necessaire au job. Chaque objet Title est
construit une fois par page distincte, sans perdre les identifiants des autres
requetes de la page. Aucun schema supplementaire n'est ajoute par ce suivi.

## Tests et portee des mesures

Depuis une copie du depot, hors production :

```sh
php tests/run.php
php tests/semanticPurgeFollowup.php
```

Le second script execute d'abord `semanticPurge.php`, puis les regressions
supplementaires. Le workflow `.github/workflows/semantic-purge.yml` execute aussi,
sous PHP 8.4, `fullstackSemanticCacheCli.php` (qui inclut les tests de purge et de
journalisation) et `fullstackSemanticHttp.php` sur de vrais MediaWiki/SMW jetables,
avec SQLite et MariaDB. La version initiale `ec4c1cbdad9c` a passe les deux jobs :
https://github.com/wikidebates/wikidebates-core/actions/runs/44896149599
Pour une revision ulterieure, consulter sa propre execution CI dans la PR.

Sont testes le recouvrement, les changements successifs de revision, une mutation
intercalee dans la purge, les anciens curseurs, les continuations reconstruites,
plusieurs requetes par page, les dependances sans resultat, les exceptions SQL
et d'invalidation, ainsi que l'absence de boucle ArticlePurge. Le laboratoire
controle de vrais fichiers de fragments, leurs generations, l'eviction effective
du ParserCache et les hooks HTML. Les tests HTTP existants couvrent aussi Minerva
et des lectures dans des processus distincts. Les doubles seuls ne sont pas
presentes comme une preuve de compatibilite SQL.

Les regressions supplementaires verifient les lectures evitees, le maintien du
controle de generation des recus reutilises, un profil non initial manquant,
l'echec d'envoi d'une continuation et sa reprise au meme curseur. Les tests reels
injectent aussi des pannes du fournisseur et de la destination des journaux.
Le test CLI execute `maintenance/run.php Wikidebates:CheckSemanticCache`, controle
ses codes de sortie et rejoue l'extrait `eval` de CE document dans un vrai processus.

Le scenario reproductible autonome contient 1200 requetes sur 40 pages et 12
vagues de jobs qui se recouvrent, sans nouvelle modification semantique :

| Compteur | Ancien algorithme modele | Nouveau code dans les doubles |
| --- | ---: | ---: |
| Jobs, continuations comprises | 108 | 24 |
| Continuations | 96 | 12 |
| Invalidations de pages | 480 | 40 |

Le test observe aussi 100 appels de lecture explicites dans son faux adaptateur,
contre une estimation de 204 avec resolution SQL des proprietaires a chaque lot
ancien non vide. Ce n'est PAS une mesure SQL exhaustive ni un minimum universel :
les caches d'identifiants SMW, `tableExists`, les ecritures et les requetes internes
MediaWiki ne sont pas tous representes. Les pourcentages de ce scenario ne
prejugent ni du temps HTTP ni du gain reel sur les 144 jobs anglais. La validation
sur une copie de production et les cas de panne du CDN ou de retard de replique
restent distincts de ces tests.

Dans le cas supplementaire de deux pages sans recu reutilisable, le job effectue
2 lectures explicites de generation au lieu de 4 ; sans table de recus, 0 au lieu
de 2. Ce sont des comptes d'appels, pas un gain de latence globale mesure.

## Deploiement propose, uniquement apres validation et accord

Tester d'abord sur une copie isolee. Sauvegarder les bases, conserver le commit
actuel pour un retour arriere et ne pas lancer de worker de purge pendant la mise
a jour. Les commandes ci-dessous sont des instructions, pas des operations deja
executees. Elles supposent que `origin` designe `wikidebates/wikidebates-core`.

```sh
cd /home/users/webmaster/www/farm.wikidebates.org/w/extensions/Wikidebates
git status --short
git remote -v
git rev-parse HEAD
```

S'arreter si le depot contient des modifications locales, si le depot distant
est different ou si un traitement utilise encore ce code. Ne pas faire de
`reset --hard`, de nettoyage automatique ni de reinitialisation des jobs.
Puis, sur un depot propre :

```sh
git fetch origin &&
git switch audit/smw-semantic-cache-invalidation-2026-11 &&
git merge --ff-only origin/audit/smw-semantic-cache-invalidation-2026-11 &&
git log -1 --oneline
```

Verifier que le commit obtenu est celui dont les tests ont ete valides. Si les
tables n'ont pas encore ete installees, appliquer la migration standard sur
chacun des wikis utilisant ce code, en conservant la configuration EagerPurge.
`update` peut appliquer d'autres migrations en attente : sauvegarde et fenetre de
maintenance restent necessaires. Si les deux tables sont deja presentes, ce
suivi n'exige aucune nouvelle migration.

```sh
cd /home/users/webmaster/www/farm.wikidebates.org/w &&
env -u WD_SEMANTIC_PURGE_MANUAL php maintenance/run.php update --wiki=en &&
env -u WD_SEMANTIC_PURGE_MANUAL php maintenance/run.php update --wiki=fr
```

Ne pas activer EagerPurge globalement. Garder les exclusions du worker anglais
pour `smw.update` et `wikidebatesSemanticCachePurge`. Ne pas changer le francais.

### Lire les continuations sans reconstruire les jobs

Si le `showJobs --list` standard échoue par `Invalid job command`,
ne pas supprimer les jobs et ne pas assimiler cette erreur à une corruption.
Ce chemin de MediaWiki instancie chaque job avec son `JobFactory`. Pour
vérifier leur progression même lorsque le type n'est pas reconnu par ce
processus, utiliser l'inspection SQL en lecture seule :

```sh
php maintenance/run.php Wikidebates:InspectSemanticPurgeQueue \
	--wiki=en \
	--limit=200 \
	--match=Basic_income
```

Le paramètre `--match` est une sous-chaîne littérale du titre stocké.
Sans `--match`, jusqu'à 200 lignes sont affichées ; `--after-id` permet
de poursuivre la lecture par `job_id`. La commande ne reconstruit
aucun job, n'appelle pas JobFactory et n'effectue ni pop, ni acquittement,
ni suppression. Elle affiche le curseur sauvegardé, le nombre de dépendances,
le statut de réclamation et le nombre de tentatives. Elle ne fonctionne
que pour la file SQL MediaWiki située dans la table `job` du wiki.

Le diagnostic `CheckSemanticCache` vérifie aussi désormais que le
type est présent dans la configuration effective `JobClasses` et que
le `JobFactory` sait le construire : une configuration qui semble
saine mais ne peut pas exécuter les jobs ne doit plus donner un feu vert.

### Controle sans consommer de job

```sh
WD_SEMANTIC_PURGE_MANUAL=1 php maintenance/run.php Wikidebates:CheckSemanticCache \
	--wiki=en \
	--require-purge-ready
```

La commande lit les reglages et l'existence des tables sur la base primaire.
Elle ne parse aucune page, ne purge aucun cache et ne touche pas a la file.
`--json` fournit un rapport machine. Sans `--require-purge-ready`, on peut aussi
inspecter un wiki dont EagerPurge est volontairement desactive.

Attendre Tracking=true, EagerPurge=true, `purge_preconditions_met = true` et les
deux tables de l'extension OK. Le controle accepte le fonctionnement sans la
table facultative `wd_smw_purge_state`, mais affiche alors un avertissement :
installer cette table pour beneficier de la mutualisation. Il refuse les tables
obligatoires manquantes et considere correctement `smwgQueryProfiler=0` comme
actif (seul le booleen false desactive ce profilage).

Ce controle ne certifie pas la fraicheur de chaque page, les permissions du
stockage des fragments, les workers, les profils `_ASK` complets ni le CDN.
Il ne modifie pas la configuration. En execution ordinaire, EagerPurge doit
rester false. Le comportement historique d'un job avec EagerPurge=false retourne
true sans purge : les exclusions du worker general restent donc indispensables.

Pour les versions ne contenant pas encore la nouvelle commande, utiliser ce
repli. **MediaWiki 1.43 eval evalue chaque ligne separement** : conserver une
instruction complete par ligne, meme dans un here-document.

```sh
WD_SEMANTIC_PURGE_MANUAL=1 php maintenance/run.php eval --wiki=en <<'PHP'
$services = \MediaWiki\MediaWikiServices::getInstance();
$config = $services->getMainConfig();
echo 'Tracking = ' . json_encode($config->get('WikidebatesSemanticCacheTracking')) . "\n";
echo 'EagerPurge = ' . json_encode($config->get('WikidebatesSemanticCacheEagerPurge')) . "\n";
echo 'BatchSize = ' . json_encode($config->get('WikidebatesSemanticCachePurgeBatchSize')) . "\n";
$db = \SMW\Services\ServicesFactory::getInstance()->getStore()->getConnection('mw.db');
echo 'wd_smw_entity_revisions = ' . ($db->tableExists('wd_smw_entity_revisions', __METHOD__) ? 'OK' : 'ABSENTE') . "\n";
echo 'wd_smw_purge_state = ' . ($db->tableExists('wd_smw_purge_state', __METHOD__) ? 'OK' : 'ABSENTE') . "\n";
PHP
```

## Reprise des jobs anglais

Commencer par quelques jobs, puis comparer progression, durees, erreurs et
fraicheur des pages representatives. Le nombre 144 est historique ; consulter
la file reelle, y compris les jobs retardes, acquis ou abandonnes.

```sh
php maintenance/run.php showJobs --wiki=en --type=wikidebatesSemanticCachePurge --group
WD_SEMANTIC_PURGE_MANUAL=1 php maintenance/run.php Wikidebates:CheckSemanticCache \
	--wiki=en \
	--require-purge-ready &&
WD_SEMANTIC_PURGE_MANUAL=1 php maintenance/run.php runJobs \
	--wiki=en \
	--type=wikidebatesSemanticCachePurge \
	--maxjobs=5 \
	--maxtime=60
php maintenance/run.php showJobs --wiki=en --type=wikidebatesSemanticCachePurge --group
```

Le `&&` interdit le lancement manuel si le controle echoue. Les deux processus
utilisent le meme prefixe d'environnement ; ne pas modifier la configuration
entre eux. Ce controle n'est pas un verrou et ne remplace pas les exclusions.

Si le pilote est concluant, recommencer par lots bornes, par exemple
`--maxjobs=100 --maxtime=300`, avec le meme prefixe d'environnement. Ne pas
lancer 144 jobs en supposant que cela suffira : les continuations comptent aussi.
`--maxtime` borne le lanceur entre les executions, ce n'est pas un moyen de tuer
sans risque une requete SQL deja bloquee. Un compteur qui baisse peu pendant
qu'une longue chaine avance n'est pas a lui seul un echec. Ne supprimer aucun
job et ne remettre aucun curseur a zero. Ne pas poursuivre une reconstruction
pendant que des purges manuelles sont encore actives.

## Reprise des profils a partir de la page 4773

Apres traitement des purges et verification de fraicheur, utiliser une plage
bornee en laissant EagerPurge=false. Exemple de lot pilote, sans suppression de
la base semantique et sans mise en file de `smw.update` par `--use-job` :

```sh
env -u WD_SEMANTIC_PURGE_MANUAL php extensions/SemanticMediaWiki/maintenance/rebuildData.php \
	--wiki=en \
	-s 4773 \
	-n 50 \
	-d 100 \
	--skip-properties \
	--force-update
```

`-s` est l'identifiant de depart ; `-n` borne la plage d'identifiants.
Reprendre selon la progression annoncee par le script, pas en ajoutant
arbitrairement 50 au dernier ID observe. `--query` attend une vraie requete SMW,
ce n'est pas une option booleenne pour les profils `_ASK`. Ne pas employer `-f`.
Ce recalcul reconstruit les donnees des pages selectionnees, pas exclusivement
les profils. Avec EagerPurge=false, leurs revisions semantiques restent suivies,
mais cette operation ne doit pas etre presentee comme un vidage complet de tous
les caches externes. Controler les profils et la fraicheur apres chaque lot.

## Retour arriere

Suspendre d'abord le traitement manuel et attendre la fin des executions actives.
Revenir au commit de code precedemment note, uniquement sur un arbre propre,
sans toucher a la file ni aux curseurs. Le code de reference `b46b8aef793f24db9483588497239aa35f2a7e64`
comprend les anciens parametres de continuation ; les parametres supplementaires
ne sont pas necessaires a leur execution. Garder Tracking et les gardes de cache
actuels ; ne pas les desactiver simplement pour annuler cette optimisation.
Les deux tables peuvent rester en place. Ne jamais supprimer la table des
revisions ni la vider tant que le suivi est actif.
