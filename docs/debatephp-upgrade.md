# Bilan d’intégration DebatePHP 0.6 → 0.7 → 0.8

La PR #1 conserve la première intégration Wikidebates et embarque maintenant
les moteurs 0.8.0, leurs optimisations, leurs outils et leurs tests utiles.
Le chargement de Wikidebates seul fonctionne sous MediaWiki 1.43.11, sans
Scribunto ni pages Lua. Aucun changement de production, de `LocalSettings.php`,
déploiement ou fusion dans `main` n’a été effectué.

## Origine et méthode

- Première intégration : `3431a8b`, sur l’import 0.6 `1ee0cfe`.
- Imports 0.7/0.8 : `9303932`, fusionné dans la branche existante de la PR,
  `codex/integrate-debatephp-060`, sans conflit ni perte du travail antérieur.
- Les 18 fichiers PHP de `debatephp/src/` sont identiques aux sources 0.8
  récupérées. Les outils/tests ont été adaptés au dépôt et à son indentation.
- Les ressources et traductions 0.6, 0.7 et 0.8 sont identiques octet pour octet.
  Le fichier `qqq.json` ajouté en première intégration est conservé.
- Les empreintes réellement intégrées sont consignées dans
  [UPSTREAM.json](../debatephp/UPSTREAM.json).

Les fichiers `SHA256SUMS.json` fournis ne décrivent pas les archives actuelles :
22 entrées sur 150 diffèrent en 0.7, 24 sur 150 en 0.8, et certains fichiers
nouveaux ne figurent pas dans ces listes. Ils n’ont pas servi à certifier les
sources. Les empreintes recalculées sont liées au commit Git fourni ; cette
constatation concerne la fraîcheur des manifestes, pas une divergence détectée
entre le code intégré et ce commit.

## Différences entre versions

| Sujet | 0.6.0 | 0.7.0 | 0.8.0 |
| --- | --- | --- | --- |
| Sources de référence à l’exécution | Lecture et contrôle des modules Lua | Retirés | Retirés |
| Autorité des traductions | PHP embarqué, contrôlé contre le wiki | PHP installé | PHP installé |
| Dépendances artificielles de cache | Modules Débat/Argument et WD/I18N | Retirées | Retirées |
| JSON-LD | Émission PHP directe ; ancien passage d’espacement des accolades encore présent | Passage d’espacement retiré | Identique à 0.7 |
| Marqueurs et listes | Fonctions partiellement distinctes selon le mode | `SerializedData` partagé | Partage étendu aux références/citations Argument |
| Conditions | Portage Lua avec nombreux temporaires | Réduction partielle | Opérateurs PHP dans les contextes booléens |
| Texte courant | Helpers dispersés | Helpers dispersés | `NativeText` partagé |
| Observabilité | Durées et diagnostics | Compteurs réels après cache, par origine | Identique à 0.7 |
| Caches | Quelques caches hérités devenus inutiles | Caches d’expansion centralisés | Quatre caches Argument inutilisés retirés |
| Modes | `reference` et `optimized` | Conservés | Conservés, `reference` par défaut |

### Apports de 0.7

Les lectures et gardes de `Module:Debate`, `Module:Argument`,
`Module:WD/I18N` et `Module:WD/I18N/Data` disparaissent du rendu PHP.
Les transclusions réellement utilisées conservent leurs dépendances.
Modifier les anciens modules ne change donc plus le rendu PHP ; les traductions
installées doivent être mises à jour comme du code. Ce changement d’autorité
et d’invalidation est intentionnel.

Le profilage distingue maintenant les prétraitements exécutés, leurs succès
de cache, les succès de cache natifs et les champs littéraux. Les tentatives
échouées sont comptées ; les zéros restent visibles. Les raisons comprennent
les champs de Débat, les citations, descriptions, balises et paramètres Page
Forms. Le mécanisme d’observation des balises restaure l’observateur précédent
dans un `finally`, y compris après exception et appel imbriqué.

Les fonctions de découpage natives sont communes aux deux modes. La centralisation
du cache conserve ses règles antérieures : forçage des arguments avant lecture,
durée limitée à l’invocation, ordre FIFO et absence de mémorisation des cadres
volatils. L’espacement des fermetures JSON, devenu inutile depuis l’émission
directe de 0.6, est supprimé ; la validation JSON et la protection de
`</script>` restent présentes.

L’API interne `I18n::bundled()` prend désormais le booléen de mémorisation,
sans textes sources du wiki. Les outils et tests de la première intégration
ont été adaptés. Les paramètres de configuration du wiki ne changent pas.

### Apports de 0.8

Les comparaisons et conditions utilisent `&&`, `||` et `!` lorsque seul
un résultat booléen est attendu. Les expressions qui retournent un opérande
conservent la vérité Lua : chaîne vide, `"0"`, zéro et table vide sont vrais.
L’ordre et le nombre d’effets de bord restent contrôlés.

| Occurrences statiques de `Lua::truth()` | 0.6 | 0.7 | 0.8 |
| --- | ---: | ---: | ---: |
| `ReferenceRenderer.php` | 578 | 534 | 291 |
| `ArgumentReferenceRenderer.php` | 1 014 | 990 | 486 |
| Total | 1 592 | 1 524 | 777 |

Les 747 occurrences retirées depuis 0.7 représentent environ 49 % du code
concerné. Ce comptage ne mesure ni le nombre d’appels sur une page ni son temps.

`NativeText` regroupe ancres, infobulles, attributs, découpage au premier
séparateur et segments de chemin. `SerializedData` traite aussi les références
et citations d’Argument. Les différences historiques d’acceptation des marqueurs
entre Débat et Argument sont préservées, ainsi que les champs manquants,
supplémentaires, séparateurs répétés et espaces ASCII.

Les caches Argument `EXPAND_CACHE`, `TITLE_CACHE`, `I18N_DOMAIN` et
`I18N_MSG`, devenus inutilisés, sont retirés. Les caches encore utilisés
conservent leurs limites et leur ordre FIFO, y compris les valeurs fausses
ou vides. La fermeture de normalisation capture seulement la table de paramètres.
Les générateurs réappliquent ces transformations ; les deux moteurs ont été
reconstruits dans un dossier temporaire avec un résultat identique octet pour octet.

## Intégration, compatibilité et dépendances

Le manifeste Wikidebates conserve l’autoload du namespace historique DebatePHP,
ses mots magiques, messages FR/EN et configuration. Le hook existant
`ParserFirstCallInit` continue à enregistrer les contributions récentes puis
les deux moteurs ; les autres hooks Wikidebates sont conservés. Les modules
ResourceLoader et le choix Vector/Minerva n’ont pas changé dans cette mise à jour.

Un seul `wfLoadExtension( 'Wikidebates' )` suffit au rendu natif. La dépendance
obligatoire Scribunto ajoutée lors de l’intégration 0.6 est retirée. Le manifeste
reprend la cible amont `>= 1.43.0 < 1.44.0`, avec PHP >= 8.1.

Semantic MediaWiki reste nécessaire aux fonctions sémantiques. Le chemin
`SMW\\Scribunto\\LibraryFactory` de SemanticScribunto, pour les écritures et
requêtes, est inchangé entre 0.6 et 0.8. Les sources documentent SMW 6.0.1 et
SemanticScribunto 2.3.3 comme environnement amont ; ces versions ne sont pas
nouvellement imposées par le manifeste. SemanticScribunto peut lui-même requérir
Scribunto. SMW seul n’active pas ce chemin sans son adaptateur.

Page Forms, ShortDescription, WikiSEO et Variables/VariablesLua conservent leur
rôle. Variables seul ne remplace pas VariablesLua pour les accumulateurs du
moteur. Le hook `htmltag` intégré conserve une implémentation tierce existante.
Les chemins de repli pour le contenu wiki dynamique et les fonctions tierces
enregistrées restent actifs.

Le compilateur atomique, les générateurs, la documentation, les fixtures,
l’oracle Lua et le manifeste des révisions historiques sont conservés sous
`debatephp/`. Les archives provisoires `imports/debatephp-0.7/` et
`imports/debatephp-0.8/` sont retirées de l’arbre final. Les anciens résultats
bruts volumineux ne sont pas copiés ; les nouveaux résultats reproductibles sont
dans [tests/results/debatephp-upgrade.json](../tests/results/debatephp-upgrade.json).

## Tests effectués

Environnement : PHP 8.2.32, MediaWiki 1.43.11 (`c1b5ca3`), SQLite temporaire,
sans OPcache CLI. Extensions optionnelles testées : Scribunto REL1_43
(`b7b180c`), Page Forms 5.8.1 (`619e233`), ShortDescription 4.0.2
(`8d348a8`), WikiSEO 2.7.0 (`3b13541`) et Variables 2.6.0-beta (`12ad832`).
Python : `lupa` 2.8 avec Lua 5.1 et `luaparser` 4.2.0.

| Contrôle relancé pour cette intégration | Résultat |
| --- | --- |
| Comparaison des trois versions, deux modes, vrai parseur | 121 cas, 726 comparaisons, aucune différence inattendue |
| Corpus Argument face à Lua, frontière simulée | 87 cas, 174 comparaisons réussies |
| Conditions, valeurs retournées et effets de bord | 3 888 comparaisons Lua/PHP réussies |
| Textes, marqueurs et citations | 3 138 comparaisons Lua/PHP réussies |
| Traductions face à Lua | 1 255 requêtes dans chacun des deux modes, soit 2 510 comparaisons |
| Tests autonomes de première intégration | 2 492 comparaisons de traduction et contrôles d’échappement/télémétrie |
| Adaptateur MediaWiki/SMW simulé | 24 assertions réussies |
| FIFO, limites, mises à jour, valeurs vides/fausses, isolation | 12 assertions réussies |
| Tests de la table native et des compteurs | Réussis, dont tentatives échouées et panne du journal |
| Banc Wikidebates réel | 13 cas × 2 modes, observations activées/désactivées, dans trois configurations |
| Opérations natives sous MediaWiki | 14 cas par configuration : compteurs, volatilité, observation imbriquée, JSON-LD, dates, citations |
| Génération des deux moteurs | Reproduction identique octet pour octet |
| Compilation des traductions | Aller-retour identique au tableau importé |

Les trois configurations du banc Wikidebates sont : extension seule ; quatre
extensions optionnelles sans Scribunto ; mêmes extensions avec Scribunto.
Le manifeste est validé contre le schéma 1.43. Le banc vérifie aussi les hooks
Vector/Minerva, modules ResourceLoader, politiques et coexistence HTML Tags,
boutons Page Forms sans création de page, métadonnées ShortDescription/WikiSEO,
mode invalide refusé, absence de lecture/dépendance Lua et chargement unique.

Les 121 cas historiques valides comprennent 86 cas Argument et 35 cas Débat.
Un titre contenant du wikitexte est invalide pour MediaWiki et est explicitement
exclu du banc réel ; il reste couvert dans les 87 cas de la frontière simulée.
Les comparaisons conservent HTML, catégories, propriétés, modèles dépendants,
liens, modules, drapeaux et expiration du cache. Seuls l’ordre/les espaces des
objets JSON et les quatre dépendances Lua volontairement retirées sont normalisés.

## Mesures locales de performance

Chaque couple version/mode a 847 mesures : 121 pages × 7 répétitions.
L’ordre des couples est mélangé de manière reproductible à chaque répétition,
après les passes de validation qui chauffent les services. Le chronométrage
entoure le parsing réel ; les copies temporaires des classes et la normalisation
des résultats sont hors mesure.

| Version | Mode | Médiane (ms) | P95 (ms) |
| --- | --- | ---: | ---: |
| 0.6 | reference | 8,300 | 19,150 |
| 0.6 | optimized | 8,067 | 19,970 |
| 0.7 | reference | 7,595 | 20,242 |
| 0.7 | optimized | 7,601 | 19,070 |
| 0.8 | reference | 7,427 | 19,478 |
| 0.8 | optimized | 7,552 | 18,932 |

Sur ce corpus local, la médiane 0.8 baisse de **10,52 % en reference** et de
**6,38 % en optimized** par rapport à 0.6. Par rapport à 0.7, les baisses sont
respectivement 2,21 % et 0,65 %. Le P95 de reference n’améliore pas celui de 0.6.
Le mode optimized n’est pas systématiquement le plus rapide ; reference reste
donc le défaut demandé.

Ces valeurs ne prédisent pas les performances du site : les modèles proviennent
de callbacks en mémoire, la base est temporaire, le cache de page est désactivé,
les services sont chauds et le magasin SMW réel n’est pas présent. Les temps
réseau, SQL du site, écritures différées et cache HTTP ne sont pas mesurés.
Aucun pourcentage d’accélération globale en production n’en est déduit.

## Limitations et régressions possibles

Aucune nouvelle régression de rendu ou de métadonnées n’est détectée dans les
corpus exécutés. Cela ne prouve pas l’équivalence sur toutes les pages du site.

Lors de ce premier bilan, les appels sémantiques étaient vérifiés par des doubles
et par la conservation de leur chemin d’adaptation. La validation complémentaire
avec SMW 6.0.1, SemanticScribunto 2.3.3 et VariablesLua 1.7.0 réels vérifie maintenant
les écritures différées, lectures, variables et rendus sur un SQLStore SQLite neuf.
Voir le [rapport complémentaire](debatephp-fullstack-validation.md) pour ses
résultats et les contrôles restant à réaliser avec des données représentatives.
Le banc historique utilisait des doubles VariablesLua ; le banc complémentaire
charge maintenant cette extension et vérifie les échanges effectifs avec PHP.

Le retrait des gardes Lua est volontaire et ne doit pas masquer une modification
future des anciennes pages : celles-ci ne sont plus l’autorité du rendu PHP.
Les anciens modèles qui appellent encore `#invoke` ne deviennent pas natifs
automatiquement. Les descriptions et fonctions de formulaire tierces conservent
leurs mécanismes de repli, mais leur absence ne fournit pas leurs fonctionnalités.

Les dépendances externes du site, MediaWiki 1.44+, les écritures de production
et les performances HTTP complètes n’ont pas été testées. Aucune action sur un
serveur de production n’a été nécessaire.

## Reproduction

Les commandes courantes sont dans [README.md](../README.md). Pour retrouver
les sources historiques sans réintroduire les imports dans l’extension active,
utiliser un dossier temporaire et l’historique Git complet de la PR :

```sh
mkdir -p /tmp/wikidebates-versions/060 /tmp/wikidebates-versions/070
git archive 1ee0cfe debatephp | tar -x -C /tmp/wikidebates-versions/060
git archive 9303932 imports/debatephp-0.7/DebatePHP | tar -x -C /tmp/wikidebates-versions/070
WD_TEST_EXTENSIONS=Scribunto,PageForms,ShortDescription,WikiSEO,Variables MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/compareVersions.php --v060=/tmp/wikidebates-versions/060/debatephp --v070=/tmp/wikidebates-versions/070/imports/debatephp-0.7/DebatePHP --repeats=7 --output=/tmp/debatephp-upgrade.json
```

La comparaison utilise des copies temporaires de classes sous des namespaces
distincts et des callbacks de modèles en mémoire. Elle n’enregistre aucune page.
Scribunto n’y est chargé que pour reproduire l’identité historique des modules
0.6 ; il n’est pas nécessaire au banc natif 0.8 ni au rendu Wikidebates seul.
