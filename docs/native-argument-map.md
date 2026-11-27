# Migration native d'ArgumentMap

Les audits FR et EN du 27 novembre 2026 ont retrouvé exactement le même
Module:ArgumentMap (révisions FR 195841 et EN 10184, SHA-256
d355b6a200310a77e3595cd7758fbe3944f2457ff8488898bd8b17e4084ade6b).
La copie binaire gzip dans tests/fixtures est uniquement un oracle de test.
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
et envoie les deux titres encodés explicitement en URI avec encoding=uri.
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

## Transport typé et cache navigateur (étape expérimentale)

Le point d'entrée en lecture seule `action=wikidebatesargumentmap` accepte
page, source, mode (full/debate/argument) et l'option lang historique.
Il garde le parseur pour les liens et les libellés stockés en wikicode,
sans passer par le module générique ApiParse. La configuration
`$wgWikidebatesArgumentMapApi = true;` active le transport GET.
Par défaut il reste désactivé : parité du HTML et performance doivent
être vérifiées avant activation en production. Un ancien backend qui
ne connaît pas l'action conserve le transport précédent ; une erreur
métier ne provoque pas de deuxième requête.

Les cartes utilisent une seule entrée LRU partagée par requête et langue,
avec une durée de vie de 60 secondes (pas de prolongation à la lecture).
Un HIT s'affiche avant le délai de 140 ms. Un seul rendu ArgumentMap
peut occuper la file commune à la fois, et le préchauffage des noms
des modèles Wikipedia n'est plus exécuté pour une carte. Aucun changement
de stockage SMW, de langue ou d'accessibilité dans ce lot.
