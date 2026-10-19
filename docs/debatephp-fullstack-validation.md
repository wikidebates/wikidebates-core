# Validation isolée de DebatePHP 0.8.0 avant fusion

Les tests avec les véritables extensions Semantic MediaWiki, SemanticScribunto
et VariablesLua réussissent sous MediaWiki 1.43.11. Ce résultat valide le banc
décrit ici ; il ne certifie pas le fonctionnement de toutes les pages ni la
capacité du serveur du site. La PR #1 reste ouverte, en brouillon, sans fusion
ni déploiement. Le mode `reference` reste le défaut.

Les différences 0.6/0.7/0.8 et les mesures historiques restent documentées dans
[le bilan de migration](debatephp-upgrade.md). Le présent rapport complète ce
bilan avec un véritable magasin sémantique et une exécution LuaStandalone.
Les résultats structurés sont dans
[tests/results/debatephp-fullstack.json](../tests/results/debatephp-fullstack.json).
La validation a ensuite été répétée après l’[harmonisation i18n](i18n-harmonization.md),
avec les nouveaux résultats séparés dans
[debatephp-i18n-fullstack.json](../tests/results/debatephp-i18n-fullstack.json).

## Environnement réellement exécuté

Chaque invocation crée une base SQLite et un répertoire de cache temporaires,
initialise les tables MediaWiki et SMW, puis supprime ces ressources à la fin.
`MW_CONFIG_CALLBACK` remplace le chargement de configuration du site : aucun
`LocalSettings.php` n'est lu ou modifié. La base est explicitement forcée vers
SQLite dans ce répertoire neuf ; aucun accès à une base existante n'est utilisé.
Le serveur logique est `https://example.test`.

Les bibliothèques sont celles des extensions installées, vérifiées par réflexion,
sans `SemanticDouble` ni bibliothèque de capture. Les sauvegardes passent par
`WikiPage` et `PageUpdater`, les valeurs sont relues depuis le SQLStore réel,
et les mises à jour utilisent les mécanismes MediaWiki et SMW réels. Le petit
module Lua et la balise de test injectent seulement des données synthétiques
pour exercer ces API ; ils ne remplacent aucun service tiers.

| Composant | Version ou branche | Commit utilisé |
| --- | --- | --- |
| MediaWiki | 1.43.11, REL1_43 | `c1b5ca37ce4f2b93703d77c6d398dc94104e8957` |
| mediawiki-vendor | REL1_43 | `af938940dec08d256da80447a41005a6ffa007f2` |
| Semantic MediaWiki | 6.0.1 | `448a112ac897ff6a4b615cf0703cf60ed946907f` |
| SemanticScribunto | 2.3.3 | `af2e61df59f4dca2384636345d182e2897067189` |
| VariablesLua, dépôt Liquipedia | 1.7.0 | `a56a46b0f1c655bc0739aa198f900c26e97be631` |
| Variables | 2.6.0-beta | `12ad832a68e1a002c821f5d1c127b04ac047181e` |
| Scribunto | REL1_43, LuaStandalone 5.1.5 | `b7b180cb53c4d729170c10abef93ca6266a8f638` |
| ParserFunctions | REL1_43, 1.6.1 | `a062e47a9d6e57fa3f12121b900e28bb5aee617c` |
| Cite | REL1_43 | `09a828e14d4d1bf4ee43359a8f62aa55d639b667` |
| Page Forms | 5.8.1 | `619e233c9ec66a69dfc2c8d50d8d1ce4c6e84484` |
| ShortDescription | 4.0.2 | `8d348a84a2e337b777525daeea2ff01707785171` |
| WikiSEO | 2.7.0 | `3b135415f9fe0eff4d23659b4143bd50009238ab` |

PHP 8.2.32 et SQLite 3.46.1 sont utilisés. Le fichier Composer de test et son
verrouillage conservent les 22 bibliothèques supplémentaires de SMW. Ils sont
séparés du `vendor/` MediaWiki ; aucun code tiers n'a été corrigé pour faire
passer le banc. La version Variables testée porte explicitement un suffixe
`beta` : il faut conserver cette information lors du choix des versions du site.

Ces extensions satisfont l'enregistrement des dépendances sous MediaWiki 1.43.
La fonction sémantique native utilise toujours SMW **et** la `LibraryFactory` de
SemanticScribunto. VariablesLua et SemanticScribunto requièrent Scribunto ; cela
ne réintroduit pas Scribunto comme dépendance du moteur PHP seul. Les versions
exactes du site restent à confronter à cet ensemble testé.

Exécution consignée : `2026-10-19T06:37:54+00:00`.

## Tests réussis

| Contrôle automatisé | Résultat |
| --- | --- |
| Persistance différée de base avec `#set` | Ancienne valeur avant exécution des mises à jour, nouvelle valeur après |
| `mw.smw.set` face à `MediaWikiContext::smwSet` | 3 états × 3 moteurs : création, remplacement et retrait des propriétés ; données identiques |
| Types et délimiteurs | Textes multiples, `0`, `A\|B=C & é`, nombre 42 puis zéro, date et page avec espace de noms conservés |
| `mw.smw.ask` face à `MediaWikiContext::smwAsk` | Résultats identiques, types conservés ; interrogation de données réellement persistées |
| Job asynchrone SMW | Un véritable `smw.update` forcé est mis en file puis exécuté ; données typées conservées |
| VariablesLua vers PHP | Valeurs `Lua → PHP` et chaîne `0` lues correctement |
| PHP vers VariablesLua | Modification `PHP → Lua` visible dans le même parseur |
| Isolation des variables | Un nouveau parseur ne récupère pas les valeurs de la page précédente |
| Rendu Lua / PHP `reference` / PHP `optimized` | 134 cas, 268 comparaisons ; aucune divergence hors exceptions documentées ci-dessous |
| Données sémantiques des rendus | Propriétés métier typées et sous-objets comparés à ceux de Lua |
| Citations | Accumulateurs `WD_QUOTES_*` identiques, y compris plusieurs rendus sur une page |
| Débats et arguments sauvegardés | 18 variantes complètes ; SQLStore identique aux propriétés du rendu après traitement différé |
| Dépendances de modèles | Identifiants de pages **et de révisions** comparés ; modification de modèle visible dans les trois moteurs |
| Contributions récentes | Relations parentales et fils d'Ariane lus depuis les tables réelles de SMW 6 |
| Hook de lien parental | Création d'un enfant, modification du débat parent et propriété `Pro argument` réellement persistée |
| Fin des traitements | Mises à jour différées et toutes les files de jobs vides ; échec du test si elles ne convergent pas |
| Chargement unique | `tests/mediawiki.php` réussit avec Wikidebates seul, sans Scribunto ni modules Lua |
| Régressions existantes | Tests autonomes et banc MediaWiki conservés réussis |

Le corpus comprend 35 cas Débat existants, 12 cas Wikidebates, 84 cas Argument
réutilisés et 3 cas supplémentaires de graphes parentaux FR/EN et citations
répétées. Les modèles conservés dans les exports XML du dépôt sont réellement
créés dans la base de test. Les quatre modules Lua conservés servent d'oracle.
Les relations, types de propriétés, utilisateurs et identifiants de pages du
banc sont synthétiques. Les textes publics archivés dans le dépôt ne constituent
pas une copie du magasin sémantique actuel du site.

Trois cas Argument sont explicitement exclus de ce banc : un titre refusé par
MediaWiki, et deux scénarios demandant l'absence d'extensions que ce banc doit
charger réellement. Ils restent couverts par les tests de frontière ou de repli
existants. Aucun résultat de ces cas exclus n'est présenté comme un succès réel.

### Ce qui est comparé et normalisé

Le comparateur vérifie le DOM visible, chaque graphe JSON-LD, les catégories,
propriétés de page, liens locaux/externes/interlangues, modules ResourceLoader,
drapeaux, durée de cache et dépendances de transclusion. L'ordre des attributs
HTML, des clés JSON et des clés distinctes des requêtes URL est normalisé.
Les doublons de paramètres URL conservent leur ordre.

Deux différences antérieures à cette validation restent intentionnelles :

- Le PHP n'enregistre plus de dépendances vers les quatre sources Lua retirées
  du chemin d'exécution. Toutes les autres dépendances sont comparées, avec
  leurs révisions.
- L'ancien passage du JSON Lua par HTML Tags altérait certaines chaînes avec
  des échappements wiki/HTML. Le comparateur ne tolère que la transformation
  historique précise de ces chaînes ; les valeurs PHP restent du JSON direct,
  protégé contre la fermeture du script. Toute autre différence échoue. Sur ce corpus, 116 comparaisons présentent
  exactement cette correction historique de chaînes JSON-LD.

Les propriétés internes SMW commençant par `_` sont exclues de la comparaison
sémantique : dates techniques, identifiants de révision et métadonnées de requête
varient lors des sauvegardes. Les propriétés métier conservent leur identifiant
de type et leur sérialisation exacte. Le test ne déduit pas d'égalité sur les
métadonnées internes exclues. Les échecs Lua, valeurs absentes, jobs en échec et
différences de rendu font échouer la commande globale.

## Correction apportée

`AutoParentLink` appelait encore `ContentHandler::getContentText()`, déprécié
depuis MediaWiki 1.37 et déprécié dur dans 1.43. Les deux lectures du contenu de
l'enfant et du parent utilisent maintenant `TextContent::getText()`, en conservant
le contrôle du type et le repli pour un contenu non textuel. Le test de sauvegarde
parentale exerce les deux lectures puis vérifie le résultat sémantique en base.

Les moteurs 0.8, traductions, paramètres et défaut `reference` n'ont pas été
modifiés. Aucun changement de dépendance d'exécution n'est ajouté à Wikidebates.

## Performances mesurées

Le résultat JSON contient 9 mesures par moteur et par scénario, après une passe
de chauffe, avec ordre des trois moteurs mélangé à chaque répétition. Le premier
scénario est le débat archivé « Dieu existe-t-il ? » ; le second est un argument
avec citation et accès au graphe sémantique synthétique. Le chronométrage couvre
le parsing, avec les vraies extensions et leurs accès SQLite ; il exclut la
création du parseur, les sauvegardes et le traitement différé. Les chiffres
ci-dessous sont les médianes de cette exécution, en millisecondes.

| Scénario | LuaStandalone | PHP reference | PHP optimized |
| --- | ---: | ---: | ---: |
| Débat | 302,118 | 93,909 | 95,125 |
| Argument avec citation | 79,792 | 24,393 | 25,718 |

Ces mesures concernent le LuaStandalone livré avec Scribunto, avec services
chauds, cache de page désactivé et PHP CLI sans OPcache. Elles incluent le coût
d'exécution LuaStandalone lorsqu'un rendu l'utilise. Le nombre d'échantillons est
faible ; les minimums, maximums et valeurs brutes sont conservés dans le JSON.
Le mode `optimized` n'est pas systématiquement plus rapide que `reference`.
Il n'y a pas de mesure de débit, de concurrence, de consommation mémoire maximale,
de coût SQL sur MariaDB ni de latence complète d'édition. Aucun gain global du
site n'est annoncé. Les mesures historiques 0.6/0.7/0.8 du bilan précédent
utilisaient un autre banc ; leurs valeurs absolues ne sont pas comparables.

## Limites et risques restant à valider

- **Données actuelles du site : non testées.** Les exports publics archivés et
  graphes synthétiques ne couvrent pas toutes les combinaisons de modèles,
  types de propriétés, cartes JSON, fils d'Ariane et relations existantes.
- **MariaDB/MySQL, réplication et concurrence : non testés.** SQLite ne reproduit
  pas les plans SQL, verrous, délais de réplication et éditions simultanées.
- **Cache partagé et invalidation sémantique : partiellement testés.** Les
  dépendances, durées et modifications de modèle sont vérifiées. La propagation
  vers les caches de page, Redis/Memcached, cache HTTP/CDN et les autres pages
  affectées par une modification sémantique reste non vérifiée ; ces caches sont
  désactivés dans le banc. Un job SMW exécuté ne constitue pas un test complet
  d'invalidation distribué.
- **Volumétrie : non testée à l'échelle du site.** Les requêtes de parents
  héritées ont des limites, notamment `limit=200`, et des caches bornés. Le
  comportement sur des graphes très denses et la durée des files sous charge
  nécessitent un corpus représentatif ; aucune absence de troncature n'est
  déduite des petits graphes du banc.
- **Erreurs tierces : le repli reste silencieux côté rendu.** L'adaptateur peut
  absorber une exception SMW et n'émettre un diagnostic que si l'option est
  active. Les assertions lisant le magasin rendent les pertes de données
  visibles dans ce banc ; elles ne couvrent pas une panne de base sous charge.
- **Interface et matrice de versions : non exhaustives.** Pas de navigation
  automatisée Vector/Minerva, d'envoi de formulaire Page Forms, de LuaSandbox,
  de PHP 8.1/8.3 ni d'autre couple SMW/SemanticScribunto testé ici. Les hooks et
  boutons HTML restent couverts par le banc MediaWiki existant. MediaWiki 1.44+
  n'est pas annoncé compatible.

Aucune nouvelle régression fonctionnelle n'a été détectée dans les cas exécutés.
La validation d'intégration isolée est réussie ; la validation opérationnelle
avec la configuration et la volumétrie du site reste à faire avant mise en
production. Ce rapport ne déclenche aucune fusion ni action sur le serveur.

### Données précises nécessaires pour aller plus loin

Une connexion au serveur de production ou sa base SQL n'est pas nécessaire pour
étendre ces tests. Il faudrait fournir un export **hors production et anonymisé** :

1. Révisions courantes d'un échantillon de débats et de tous leurs arguments
   reliés, avec les ancêtres jusqu'aux racines, cas multi-parents, cycles connus,
   longues chaînes et pages proches ou au-delà de 200 relations. Inclure les
   pages vides, anciennes et mal formées représentatives.
2. Tous les modèles/modules, pages de propriétés avec types, formulaires et
   messages personnalisés dont ces pages dépendent. Un export XML cohérent suffit
   pour les contenus ; conserver une correspondance stable des titres anonymisés.
3. Valeurs sémantiques métier attendues pour cet échantillon, exportées par
   sujet/propriété/type/valeur, y compris valeurs multiples et sous-objets. Cela
   permet de détecter une différence préexistante entre le texte et le magasin,
   sans déclarer arbitrairement le nouveau rendu comme référence.
4. Liste exacte des versions/extensions/PHP/SGBD et extrait de configuration
   **sans secrets** : langues/espaces de noms, réglages SMW, Variables, cache,
   jobs, URLs et politiques HTML pertinentes. Ne pas fournir un
   `LocalSettings.php` contenant des identifiants ou clés.
5. Pour un essai de charge : nombre de pages et relations, distribution des
   tailles et du nombre de parents, fréquence d'édition et paramètres des workers.
   Un jeu synthétique de même forme peut suffire ; les effets du matériel et du
   SGBD se mesurent ensuite sur une installation de recette distincte.

Les titres, délimiteurs, longueurs, cardinalités, types et relations doivent
rester cohérents lors de l'anonymisation. Sans ces éléments, les tests signalés
comme non effectués ci-dessus restent tels quels.

## Reproduction sans configuration de site

Le banc nécessite Python 3, Git, Composer et un PHP CLI MediaWiki 1.43 avec
notamment PDO SQLite, sqlite3, mbstring, intl, DOM/XML/SimpleXML, ctype, fileinfo,
curl, Phar et tokenizer. Le binaire LuaStandalone Scribunto doit pouvoir s'exécuter.
Il n'y a pas de dépendance Python supplémentaire pour ce banc.

Créer un laboratoire neuf avec `mediawiki/` et `dependencies/` côte à côte.
Cloner le cœur, son `vendor/` et les extensions du tableau aux commits indiqués.
Les dépôts sont `wikimedia/mediawiki`, `wikimedia/mediawiki-vendor`,
`wikimedia/mediawiki-extensions-NOM` pour les extensions Wikimedia,
`SemanticMediaWiki/SemanticMediaWiki`, `SemanticMediaWiki/SemanticScribunto`,
`Liquipedia/VariablesLua` et `StarCitizenTools/mediawiki-extensions-ShortDescription`.
Les extensions doivent se trouver dans `mediawiki/extensions/NOM`.

Depuis le dépôt Wikidebates, pour un laboratoire situé à `/path/to/lab` :

```sh
mkdir -p /path/to/lab/dependencies
cp tests/fullstack-dependencies/composer.json /path/to/lab/dependencies/composer.json
cp tests/fullstack-dependencies/composer.lock /path/to/lab/dependencies/composer.lock
composer --working-dir=/path/to/lab/dependencies install --no-dev --no-interaction --no-plugins --no-scripts
MW_INSTALL_PATH=/path/to/lab/mediawiki WD_FULLSTACK_AUTOLOAD=/path/to/lab/dependencies/vendor/autoload.php python3 tests/fullstack.py --php php --output /path/to/lab/results.json
php tests/run.php
MW_INSTALL_PATH=/path/to/lab/mediawiki php tests/mediawiki.php
```

Le fichier Composer de test contient l'autoload SMW relatif à ce laboratoire et
les dépendances du paquet 6.0.1, verrouillées dans le fichier associé. Il ne
remplace pas le `composer.json` d'un wiki. Une installation SMW effectuée par son
procédé Composer habituel peut utiliser son propre autoload ; la variable
`WD_FULLSTACK_AUTOLOAD` est alors optionnelle si le `vendor/` MediaWiki le fournit.

`tests/fullstack.py` exécute le PHP puis compare les sorties fraîchement créées
avec l'oracle Lua. Son code de retour est non nul dès qu'une assertion, un job ou
une comparaison échoue. Le rapport n'est écrit qu'après réussite de l'ensemble.
Les artefacts intermédiaires et la base sont supprimés à la fin. Le manifeste
Wikidebates ne charge aucun de ces fichiers de test.
