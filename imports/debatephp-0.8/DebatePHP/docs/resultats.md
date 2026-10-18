# Résultats du portage DebatePHP

État au 18 octobre 2026. L’utilisateur indique avoir testé l’extension sur son site, où elle semble bien fonctionner. Les tests locaux détaillés ci-dessous confirment le rendu et les effets observables sur le corpus fourni. Le stockage SMW et le temps total d’enregistrement n’ont pas été audités dans cet environnement isolé.

## Maintenance et observations — version 0.3.0

Le site utilise un seul appel à Débat par page. Cette version ajoute des mesures par étape et des diagnostics facultatifs, désactivés par défaut, ainsi qu’une commande de vérification et de régénération des traductions utilisant le Scribunto installé. Aucun cache supplémentaire pour des appels multiples n’a été ajouté.

Les observations ponctuelles sur les cinq pages réelles placent le développement des arguments en tête des coûts internes du rendu. Toutes les expansions sont maintenues. Les lectures de traductions représentent une faible part de ces observations ; cette version ne revendique pas un nouveau gain de vitesse lié à leur réécriture. Les étapes et leurs durées figurent dans `tests/results/v030-profile-stages.json`. Ces mesures internes couvrent l’appel PHP à Débat et comportent le coût de leur instrumentation ; elles ne représentent pas le temps complet de parsing. SMW reste simulé dans ce montage.

La vérification comprend :

- 248 comparaisons sur 31 cas, deux variantes PHP et deux moteurs Lua, avec puis sans traces d’expansion ;
- 124 comparaisons supplémentaires avec mesures et diagnostics activés sur les deux moteurs, soit **372 comparaisons réussies** au total ;
- 2 510 comparaisons indépendantes de traductions et le contrôle du chargement natif ;
- la commande de maintenance exécutée avec LuaSandbox et LuaStandalone (2 510 traductions et 14 sélections de langue par exécution) ;
- la validation d’une traduction modifiée, le rejet d’un format incompatible, la conservation du fichier précédent, de ses permissions et de son propriétaire/groupe ;
- quatre scénarios avec observations activées/désactivées : succès, exception SMW, erreur retournée par SMW et exception SEO, sans différence de rendu, de catégories, de propriétés, de liens ni de dépendances ;
- le contrôle d’un journal défaillant et de l’omission des valeurs dans les événements ;
- la syntaxe des 27 fichiers PHP et la génération reproductible de la table de traductions.

### Mesures de parsing avec observations désactivées — 0.3.0

Nouvelle série de 25 répétitions par page et variante, sur le même montage isolé. Chaque moteur est mesuré séparément, avec un nouveau parseur par répétition, une chauffe préalable et un ordre des variantes mélangé. Les chiffres restent des durées de `Parser::parse()` avec un double SMW ; ils ne prédisent pas le temps d’enregistrement du site.

#### LuaSandbox

| Page | Lua | PHP référence | PHP optimisé | Réduction Lua vers PHP optimisé |
| --- | ---: | ---: | ---: | ---: |
| Test vide | 31.0 ms | 19.6 ms | 19.9 ms | 36.0 % |
| Dieu existe-t-il ? | 97.4 ms | 84.4 ms | 83.5 ms | 14.3 % |
| Faut-il accepter l'immigration ? | 68.8 ms | 56.7 ms | 53.0 ms | 23.0 % |
| Un revenu de base doit-il être instauré ? | 82.6 ms | 66.2 ms | 67.7 ms | 18.1 % |
| Pour ou contre l'IA générative ? | 38.6 ms | 27.4 ms | 26.5 ms | 31.2 % |
| Ecosia est-il un moteur de recherche écologique ? | 48.1 ms | 35.4 ms | 35.4 ms | 26.3 % |

#### LuaStandalone

| Page | Lua | PHP référence | PHP optimisé | Réduction Lua vers PHP optimisé |
| --- | ---: | ---: | ---: | ---: |
| Test vide | 70.9 ms | 21.5 ms | 22.6 ms | 68.2 % |
| Dieu existe-t-il ? | 402.2 ms | 84.4 ms | 89.5 ms | 77.7 % |
| Faut-il accepter l'immigration ? | 217.9 ms | 55.4 ms | 57.1 ms | 73.8 % |
| Un revenu de base doit-il être instauré ? | 229.5 ms | 78.2 ms | 74.6 ms | 67.5 % |
| Pour ou contre l'IA générative ? | 80.1 ms | 26.9 ms | 26.8 ms | 66.6 % |
| Ecosia est-il un moteur de recherche écologique ? | 104.6 ms | 37.2 ms | 36.9 ms | 64.7 % |

Ces résultats confirment que le gain de conversion par rapport à Lua subsiste dans cette série. Les variations entre versions mesurées séparément ne permettent pas d’attribuer un gain ou une régression précis aux nouveaux points d’observation. Les fichiers de cette version portent le préfixe `v030-` dans `tests/results/`.

## Intégration des traductions PHP — version 0.2.0

Les deux modules transmis sont identiques aux références incluses, à leur saut de ligne final près. Le moteur et la table complète de traductions sont maintenant chargés en PHP dans les deux modes. Le lecteur de tables Lua intervient uniquement dans l’outil de conversion hors ligne. Les gardes vérifient toujours les sources wiki et conservent leurs dépendances de cache.

Les 31 cas passent avec deux variantes PHP, deux moteurs Lua et deux parcours (avec puis sans traces), soit 248 comparaisons. Les 2 510 comparaisons de traductions passent en utilisant la table PHP intégrée. Le test `i18n_bundle.php` confirme aussi les gardes de synchronisation et l’absence de chargement du lecteur de tables Lua.

Voici une nouvelle série de 25 répétitions par page et variante, réalisée séparément pour chaque moteur, dans le même environnement isolé décrit plus bas. SMW reste remplacé par l’enregistreur de paramètres.

### LuaSandbox — 0.2.0

| Page | Lua | PHP référence | PHP optimisé | Réduction Lua vers PHP optimisé |
| --- | ---: | ---: | ---: | ---: |
| Test vide | 30.2 ms | 19.8 ms | 19.8 ms | 34.4 % |
| Dieu existe-t-il ? | 100.5 ms | 82.5 ms | 82.1 ms | 18.3 % |
| Faut-il accepter l'immigration ? | 66.6 ms | 65.6 ms | 51.6 ms | 22.6 % |
| Un revenu de base doit-il être instauré ? | 90.3 ms | 72.5 ms | 77.1 ms | 14.6 % |
| Pour ou contre l'IA générative ? | 37.5 ms | 26.2 ms | 26.9 ms | 28.2 % |
| Ecosia est-il un moteur de recherche écologique ? | 46.3 ms | 34.5 ms | 34.3 ms | 25.8 % |

### LuaStandalone — 0.2.0

| Page | Lua | PHP référence | PHP optimisé | Réduction Lua vers PHP optimisé |
| --- | ---: | ---: | ---: | ---: |
| Test vide | 66.6 ms | 20.2 ms | 20.9 ms | 68.7 % |
| Dieu existe-t-il ? | 380.4 ms | 83.7 ms | 84.2 ms | 77.9 % |
| Faut-il accepter l'immigration ? | 228.1 ms | 60.1 ms | 59.6 ms | 73.8 % |
| Un revenu de base doit-il être instauré ? | 211.8 ms | 83.1 ms | 64.9 ms | 69.4 % |
| Pour ou contre l'IA générative ? | 79.7 ms | 25.1 ms | 26.1 ms | 67.3 % |
| Ecosia est-il un moteur de recherche écologique ? | 98.7 ms | 34.5 ms | 34.5 ms | 65.0 % |

Le mode optimisé n’est pas systématiquement le plus rapide sur chaque page dans cette série. L’objectif confirmé ici est l’utilisation native des traductions avec des sorties identiques à Lua ; les différences entre séries ne permettent pas d’attribuer un gain précis à cette seule modification. Les résultats bruts de cette version portent le préfixe `native-i18n-` dans `tests/results/`.

## Mesures historiques avec LuaSandbox

Les mesures de performance ci-dessous ont été réalisées avec la version 0.1.0, avant la correction de compatibilité de la version 0.1.1. Elles n’ont pas été remesurées lors de cette vérification complémentaire.

Médianes de 25 répétitions par page et par variante. Une réduction positive signifie un parsing plus rapide dans ce montage local.

| Page | Lua | PHP référence | PHP optimisé | Réduction Lua vers PHP optimisé |
| --- | ---: | ---: | ---: | ---: |
| Test vide | 32.3 ms | 27.8 ms | 22.1 ms | 31.7 % |
| Dieu existe-t-il ? | 109.1 ms | 102.1 ms | 95.0 ms | 12.9 % |
| Faut-il accepter l'immigration ? | 73.2 ms | 63.5 ms | 55.8 ms | 23.7 % |
| Un revenu de base doit-il être instauré ? | 89.4 ms | 100.8 ms | 74.1 ms | 17.1 % |
| Pour ou contre l'IA générative ? | 41.7 ms | 35.0 ms | 31.6 ms | 24.2 % |
| Ecosia est-il un moteur de recherche écologique ? | 58.2 ms | 46.0 ms | 38.2 ms | 34.4 % |

La conversion seule ne garantit pas un gain : sur la page du revenu de base, la version PHP de référence est plus lente que LuaSandbox dans cette série. La version optimisée améliore ce résultat. Les optimisations retenues portent sur le découpage natif des champs, la construction des enregistrements, la mémorisation des messages non formatés et les traductions PHP précompilées vérifiées par SHA-256.

## Mesures historiques avec LuaStandalone

Le second moteur effectue davantage d’allers-retours avec son processus Lua ; les gains observés y sont plus élevés.

| Page | Lua | PHP référence | PHP optimisé | Réduction Lua vers PHP optimisé |
| --- | ---: | ---: | ---: | ---: |
| Test vide | 65.8 ms | 26.2 ms | 19.6 ms | 70.1 % |
| Dieu existe-t-il ? | 399.5 ms | 92.2 ms | 87.0 ms | 78.2 % |
| Faut-il accepter l'immigration ? | 203.6 ms | 72.2 ms | 52.5 ms | 74.2 % |
| Un revenu de base doit-il être instauré ? | 213.8 ms | 71.2 ms | 68.9 ms | 67.8 % |
| Pour ou contre l'IA générative ? | 80.7 ms | 32.1 ms | 26.0 ms | 67.8 % |
| Ecosia est-il un moteur de recherche écologique ? | 99.8 ms | 40.4 ms | 34.5 ms | 65.4 % |

## Conditions des mesures

Le montage emploie MediaWiki 1.43.9, PHP CLI 8.3.6, SQLite, Scribunto REL1_43, LuaSandbox 4.1.1 et LuaStandalone 5.1.5. ParserFunctions et Cite sont chargés. ShortDescription est en version 4.0.1 et HTML Tags annonce la version 0.3. Le code WikiSEO provient du tag `v2.7.0`, dont le manifeste annonce encore 2.6.6 ; le serveur public annonce WikiSEO 2.7.0.

Chaque mesure utilise un nouveau parseur, avec une chauffe préalable, puis un ordre des variantes mélangé à chaque répétition. Le chronométrage couvre `Parser::parse()`. Les sources de modèles et services du processus peuvent être chauds ; le cache de page n’est pas utilisé. OPcache n’est pas activé dans ce PHP CLI. Les contrôles instrumentés sont distincts des répétitions chronométrées.

SMW est remplacé dans ce montage par un enregistreur des paramètres : la comparaison vérifie les valeurs envoyées, pas leur validation typée par le vrai magasin ni les écritures SQL. Les autres personnalisations du site, Page Forms Wikidebates, Interlanguage Central, le stockage SMW, le réseau HTTP et le traitement des tâches différées ne sont pas reproduits intégralement. Ces chiffres ne constituent donc pas une mesure du temps total d’enregistrement ou du chargement d’une page servie depuis son cache.

Les distributions, médianes, p95, minima et maxima se trouvent dans les fichiers JSON de résultats. Les deux séries ont été exécutées séparément. Leurs différences absolues ne doivent pas être interprétées comme une mesure comparative isolée des deux moteurs Lua.

## Équivalence vérifiée

Le corpus comprend cinq pages publiques récupérées sur Wikidébats, un cas vide et 24 cas synthétiques. Les références de révision et les sources sont incluses dans `tests/fixtures`. Avec deux variantes PHP et deux moteurs Lua, cela représente 120 comparaisons différentielles de cas.

Les contrôles portent sur :

- le DOM, le texte affiché et les graphes JSON-LD ;
- les catégories, les propriétés SEO et les propriétés ShortDescription ;
- les liens locaux et externes, les liens de langue et les dépendances de modèles avec leurs identifiants de révision ;
- les modules de ressources, les drapeaux de sortie et l’expiration du cache ;
- les valeurs transmises à SMW, y compris les doublons ;
- le nombre, l’ordre, les entrées et les sorties des expansions explicites, ainsi que leur volatilité et leur durée de vie.

Les seuls ajustements de comparaison sont l’ordre des attributs HTML, l’ordre des clés d’objets JSON et l’ordre de clés distinctes dans les requêtes URL. Les contenus de listes, les valeurs, le texte et les catégories ne sont pas neutralisés.

Les cas synthétiques couvrent notamment les deux langues, l’héritage et la priorité des paramètres, les valeurs vides et `0`, les doubles expansions, les notes et marqueurs `nowiki`, tous les bandeaux, les références des trois types et des trois côtés, les champs mal formés, les arguments inutilisés avec effets et les appels multiples sur une même page.

Un test indépendant compare 1 255 requêtes de traduction avec le Lua 5.1 de référence, puis avec chacune des deux stratégies PHP : 2 510 comparaisons réussies.

## Validation restante sur le site

### Vérification complémentaire de la version 0.1.1

La revue a trouvé une erreur dans l’auxiliaire de typage : `is_callable()` reconnaissait des chaînes telles que `date` et `strlen` comme des fonctions PHP, alors que Lua les traite comme des chaînes. Un cas reproduisant une différence de JSON-LD et de valeurs transmises à SMW échouait avec les deux variantes PHP. La correction donne la priorité au type chaîne et le cas est conservé dans le corpus.

Le test d’horodatage avait aussi un défaut de reproductibilité : une seconde pouvait s’écouler entre deux variantes. Les options du parseur utilisent maintenant le même instant dans les trois variantes, en conservant les expansions et leurs effets sur le cache.

Les 31 cas ont été rejoués avec les deux variantes PHP et les deux moteurs Lua, avec puis sans instrumentation des expansions : **248 comparaisons réussies**. Les 2 510 comparaisons de traductions passent également. Les résultats de cette revue sont dans les fichiers `tests/results/audit-*.json` ; les anciens résultats restent conservés comme historique. La syntaxe PHP et l’intégrité du paquet ont été revérifiées.

### Contrôles encore nécessaires

Le mode `--full-stack` fourni utilise les vraies extensions et compare les données sémantiques typées du `ParserOutput`. Il doit être exécuté sur une copie complète de Wikidébats ; ce mode n’a pas été validé ici avec un magasin SMW réel.

Les écritures SMW après enregistrement, les tâches différées, l’invalidation effective du cache sur le serveur, les personnalisations locales et les cas proches des limites de ressources demandent encore cette validation. La procédure se trouve dans [validation.md](validation.md). Le site public n’a pas été modifié.

## Fichiers des résultats

- [LuaSandbox et pages réelles](../tests/results/benchmark-sandbox.json).
- [LuaStandalone et pages réelles](../tests/results/benchmark-standalone.json).
- [Cas synthétiques avec LuaSandbox](../tests/results/synthetic-sandbox.json).
- [Cas synthétiques avec LuaStandalone](../tests/results/synthetic-standalone.json).
- [Analyse des expansions et des cadres](equivalence.md).
