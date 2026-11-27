# Migration native d'ArgumentMap

Les audits FR et EN du 27 novembre 2026 ont retrouvé exactement le même
Module:ArgumentMap (révisions FR 195841 et EN 10184, SHA-256
d355b6a200310a77e3595cd7758fbe3944f2457ff8488898bd8b17e4084ade6b).
Les sorties de référence sont figées dans le contrat natif ; les sources
historiques ont été retirées après validation et restent dans l'historique Git.
La production n'a plus besoin d'exécuter le module pour les nouveaux survols.

La fonction de parseur #argumentmap:render conserve les trois branches :
carte stockée quand la source est absente ; arguments pour/contre si le
troisième paramètre est non vide ; justifications/objections sinon.
Les propriétés et traductions existantes, séparateurs, ordre, libellés,
liens, cartes vides et classes CSS restent les mêmes. La recherche SMW est
limitée à un sujet local exact, via le formateur natif déjà utilisé.
L'identité canonique du titre et une seule vérification de l'existence de
la source suffisent au surlignage ; aucun chargement de module Lua ni
recherche de l'identifiant de chaque carte n'est nécessaire.

Les attributs data-argument et data-debate des renderers restent inchangés
(y compris le HTML déjà en cache). Le JS décode leur ancien encodage QUERY
et transmet les deux titres littéraux à l'API dédiée. Celle-ci encode les
titres en URI avant l'appel interne de la fonction de parseur.
Cela corrige le test historique de '%' qui cherchait en réalité deux
signes '%' consécutifs, évite de confondre un plus littéral avec une espace
et protège les limites des paramètres du wikicode. Les appels manuels sans
encoding=uri utilisent des titres en clair. Un titre invalide ou une
expression qui n'est pas analysée par SMW comme le sujet exact ne donne
pas accès à une requête arbitraire ; les entrées invalides de liste sont
ignorées. Les liens vers fichiers/catégories sont des liens, pas des inclusions.
Les libellés restent du wikicode traité par le parseur normal.

La file de requêtes, les caches d'infobulles, les autres fonctionnalités
de survol, les politiques de cache serveur et les écritures SMW ne changent
pas. Le test réel MediaWiki/SMW sans extensions Lua et la comparaison avec
le module original sur une base jetable couvrent les deux langues.
Les cas de caractères URL corrigés font l'objet de tests natifs séparés
car le défaut historique n'est pas reproduit volontairement.

Déployer d'abord la version PHP/JS en gardant Scribunto, SemanticScribunto
et les anciens modules : les anciennes pages HTML/versions ResourceLoader
et onglets déjà ouverts peuvent encore appeler #invoke:ArgumentMap.
Vérifier les trois types de survol sur FR/EN puis traiter cette compatibilité
et l'accès aux révisions Scribunto avant toute désactivation. Aucun effacement
de l'historique, purge générale ou changement de configuration n'est inclus.

## Optimisations ciblées du survol

La requête sémantique reste une recherche exacte d'un seul sujet, via le
moteur SMW normal : ne pas remplacer cette recherche par du SQL direct ou
une lecture brute des propriétés. Cela pourrait modifier les redirections,
les filtres, les formatages et les hooks de SMW. Aucun cache serveur ni
préchargement spéculatif supplémentaire.

Le titre source n'est testé pour son existence que si une carte lui
correspond. Une seule réponse (y compris false) est partagée entre les
deux colonnes. Les listes vides ne sont pas découpées ; les traductions
utilisées une seule fois ne sont plus mémorisées par instance.
Le formateur natif résout les libellés booléens à la première valeur
booléenne seulement et réutilise le Linker pendant la lecture.

Le client conserve le même délai de survol, la même file, la même
clé de cache et les mêmes contrôles contre les réponses périmées.
Uniquement pour #argumentmap:render dans la file hover, il demande
prop=text et disablelimitreport=1 : les métadonnées JSON des liens,
sections, catégories et modèles, ainsi que le rapport de limites qui
était supprimé par le JavaScript, ne sont pas nécessaires. Le wikicode
est toujours analysé et assaini par MediaWiki.

Les tests d'intégration vérifient les compteurs de lectures SMW et
d'existence du titre (0 sans correspondance, 1 pour plusieurs cartes
correspondantes), les liens rouges, les trois modes FR/EN et l'équivalence
de l'HTML du véritable action=parse avec ou sans les paramètres compacts.
Les lignes ARGUMENTMAP_API_BYTES mesurent la taille JSON non compressée
des réponses du laboratoire ; elles ne mesurent pas le trafic gzip,
les temps réseau ni les gains PHP-FPM réels de la production.

## Transport typé et cache navigateur

Le point d'entrée en lecture seule `action=wikidebatesargumentmap` accepte
page, source, mode (full/debate/argument) et l'option lang historique.
Il garde le parseur pour les liens et les libellés stockés en wikicode,
sans passer par le module générique ApiParse. Le survol structuré utilise
ce transport GET dans toutes les langues, sans réglage de configuration.
Une erreur du point d'entrée dédié ne provoque pas de seconde requête
vers action=parse et ne désactive pas l'API pour les cartes suivantes.
Les appels explicites de wikicode et le banc comparatif restent possibles ;
ils ne constituent pas un repli automatique du survol.

Les cartes utilisent une seule entrée LRU partagée par requête et langue,
avec une durée de vie de 60 secondes (pas de prolongation à la lecture).
Un HIT s'affiche avant le délai de 140 ms. Un seul rendu ArgumentMap
peut occuper la file commune à la fois, et le préchauffage des noms
des modèles Wikipedia n'est plus exécuté pour une carte. Aucun changement
de stockage SMW, de langue ou d'accessibilité dans ce lot.

## Frontière des données et étude de lecture directe

Les trois moteurs partagent désormais la sérialisation des couples
page/libellé et le conteneur des cartes. ArgumentMap décode les données
en couples structurés, sans changer les valeurs persistées, l'ordre,
les séparateurs ou les libellés. Le format des entrées de modèles, qui
peut contenir des avertissements, reste distinct. Pas de migration SQL.

ArgumentMapData sépare la lecture source-indépendante du rendu : un
éventuel cache pourra porter sur ces données, jamais sur le surlignage
ou sur un fragment HTML dépendant du visiteur.

Le laboratoire compare le résultat de la requête singleton et celui
d'une lecture des propriétés Text via Store::getPropertyValues, avec
DataValueFactory et le formatage SMW. Les lignes ARGUMENTMAP_DIRECT_READ
indiquent la parité stricte et des médianes de 12 mesures alternées
par mode. L'état local du Store est vidé avant chaque mesure ; cela
ne garantit pas des caches DB/SMW froids. Les cas incluent les trois
cartes, les valeurs multiples, les absences et une redirection.
La lecture directe ne devient pas le chemin de production sur la
seule foi de ce test exploratoire.

Le cache WAN reste une étape séparée : il faut couvrir les écritures
SMW après commit, les suppressions et déplacements (chemins distincts),
les changements de propriétés, la visibilité et les lectures concurrentes.
Ne pas réactiver les anciens purges sémantiques de pages ni stocker
des réponses dépendantes du visiteur dans un cache partagé.

## Lecture directe : chemin restreint et validation sur la version de production

La lecture directe est sélectionnée automatiquement, sans paramètre de
configuration, lorsque ses conditions d'éligibilité sont réunies. Elle autorise
la lecture directe des cinq propriétés Text historiques sur les sujets
ordinaires de l'espace principal, non redirigés, avec SMW 7.3.1 ou 7.4.1.
Le cas général (titre ambigu, page inexistante, redirection, propriété
non textuelle ou multivaluée, version différente) garde la requête
singleton existante. Les listes, leur ordre, le formatage SMW et le
surlignage restent inchangés. Pas de SQL ni de cache persistant ajouté.

Le workflow native-argumentmap teste MediaWiki 1.43.12 et SMW 7.4.1
sur SQLite et MariaDB, sans Scribunto : lecture automatique et comparaison
avec la requête de référence, égalité du HTML API et des champs, compteurs de requêtes,
cas de repli et édition/suppression de valeurs sémantiques. La mesure
runtime inclut les garde-fous de sélection : elle est distincte du
prototype brut. L'échantillonnage de production (2 000 pages FR/EN) a confirmé zéro
divergence et zéro erreur. Le choix ne dépend plus de la langue du wiki,
mais des propriétés et du sujet réellement lus. Le repli sémantique reste
indispensable pour les cas non certifiés.

## Mesurer les gains de bout en bout et décider du cache partagé

Les lignes ARGUMENTMAP_API_TIMING comparent le temps PHP du transport
compact et de l'API dédiée, sérialisation JSON comprise, en alternant
12 mesures par chemin et en vérifiant le HTML visible. Ce n'est pas
un benchmark réseau ni PHP-FPM.

Après installation du code, `maintenance/BenchmarkArgumentMap.php`
permet un audit borné, sans modification des pages ni du schéma.
Il compare les champs effectivement enregistrés sur les pages réelles,
compte les cas éligibles et les divergences, et mesure les lectures
non vides. Par exemple (adapter l'identifiant du wiki à la ferme) :

```bash
php maintenance/run.php Wikidebates:BenchmarkArgumentMap --wiki=fr --limit=200 --cache-probe
```

Utiliser aussi le wiki EN et plusieurs plages d'identifiants. Une
divergence interdit l'activation sans analyse. L'option cache-probe
évalue 100 lectures WAN d'une valeur synthétique (clé aléatoire, TTL
30 s, suppression en fin de mesure) avec une clé de validation dédiée.
Elle ne met jamais les cartes de production en cache. Les résultats
CLI ne représentent pas nécessairement le backend ou les coûts FPM.
Ne pas ajouter de cache de production uniquement sur la base d'un
HIT rapide : considérer aussi le taux de HIT réel, les MISS et les
coûts/risques d'invalidation.
