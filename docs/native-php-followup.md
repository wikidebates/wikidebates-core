# Suite de la migration Lua vers PHP — PR #3

Cette phase conserve tous les commits précédents et compare les changements
au commit `377c55ac212e45066340ae22e79d944f4c0ebd0d`, déjà publié sur la
branche `codex/native-php-optimizations`. Les chiffres de la première phase
restent dans [son rapport](native-php-optimizations.md) ; ils ne sont pas
attribués une seconde fois aux changements décrits ici.

## Les six points

| Point | Modification | Règles conservées |
| --- | --- | --- |
| 1. Parcours sans copie | Les 89 parcours `Lua::ipairs()` des deux moteurs utilisent `Table::sequence()`. Le générateur parcourt directement un instantané du tableau PHP, conservé par copie sur écriture. | Indices commençant à 1, arrêt au premier trou ou `null`, `false` conservé, instantané pris dès l'appel, objets imbriqués partagés. `Lua::ipairs()` reste disponible pour la compatibilité. |
| 2. FIFO natif | `FifoCache` remplace les tables imbriquées et la réindexation complète à chaque éviction par un tableau PHP et `SplQueue`. | Identité partagée, limites et valeurs par défaut, mise à jour sans rajeunir l'entrée, valeurs `false`/vides, suppression par `null` et comportement des entrées dupliquées. Le cache d'URL conserve la durée de vie du moteur ; les autres sont remis à zéro au rendu. |
| 3. Tableaux PHP | Les consommateurs de références lisent les champs normalisés dans le tableau PHP du record. Les listes SEO, mots-clés JSON-LD, listes about/mentions, auteurs et dictionnaires de bandeaux utilisent des accumulateurs PHP. | Les objets `Table` restent aux frontières des adaptateurs, pour les structures partagées et pour l'encodage JSON. Les indices, l'ordre, les objets vides et l'identité des objets imbriqués restent contrôlés. |
| 4. Conversions | Suppression des conversions chaîne/vérité/trim répétées dans les consommateurs HTML et JSON-LD des records déjà normalisés. | Les conversions des données non normalisées restent en place. Aucune assimilation générale de la vérité Lua à celle de PHP. Le trim ASCII conserve ses différences avec le trim PHP par défaut, notamment pour NUL et les espaces insécables. |
| 5. Préparation commune | `RenderData` mutualise le découpage CSV entre HTML, SEO, données sémantiques et JSON-LD, dans une FIFO de 256 entrées par rendu. | Retour d'un nouvel objet enveloppe à chaque lecture pour éviter les mutations du cache, clé incluant le séparateur sans ambiguïté, nouvelle instance à chaque rendu. Aucun cache supplémentaire des messages, expansions, variables ou écritures SMW. |
| 6. Closures | Le générateur calcule les variables libres lexicales et réduit les captures. Les closures de construction JSON-LD deviennent autonomes lorsque leurs données sont locales. | Références conservées pour les variables libres réellement partagées, récursion, paramètres, déclarations locales, masquage des noms et ordre des effets. Les deux moteurs se régénèrent à l'identique. |

La carte des alias traduits des avertissements de résumé est aussi réinitialisée
à chaque rendu. Cela évite de conserver des alias d'une autre langue quand un
même objet moteur est réutilisé. Les appels de traduction du mode `reference`
restent effectués ; `optimized` conserve sa mémorisation des messages existante.
Une mémorisation supplémentaire des libellés de bandeaux n'a pas été ajoutée,
pour préserver la visibilité des surcharges de messages.

Le nombre d'occurrences statiques dans les deux moteurs évolue ainsi :

| Indicateur | Avant | Après |
| --- | ---: | ---: |
| `new Table` | 377 | 370 |
| `Lua::` | 1 918 | 1 592 |
| `Lua::ipairs` | 89 | 0 |
| Variables capturées par référence | 179 | 19 |

Ces nombres décrivent le code, pas le nombre d'allocations ou d'appels au
cours d'un rendu. La conversion des accumulateurs maintient parfois une
allocation finale `Table` pour respecter le contrat de l'adaptateur.

## Validation et mesures

Le laboratoire utilise MediaWiki **1.43.11**, PHP **8.3.35**, de véritables
SMW **6.0.1**, SemanticScribunto **2.3.3**, VariablesLua **1.7.0**,
Variables **2.6.0-beta** et Scribunto REL1_43. Les conteneurs n'ont pas
de réseau ; MariaDB est accessible uniquement par le socket du laboratoire.
Chaque exécution crée puis supprime sa propre base.

| Exécution | Cas | Lua/PHP | Natif avant/après | Variantes enregistrées | Échantillons de parsing par scénario/mode |
| --- | ---: | ---: | ---: | ---: | ---: |
| SQLite 3.46.1, série 1 | 140 | 280 | 280 | 50 | 15 |
| SQLite 3.46.1, série 2 | 140 | 280 | 280 | 50 | 15 |
| MariaDB 10.11.19 | 140 | 280 | 280 | 50 | 5 |

Les comparaisons natives sont exactes pour le HTML, les données JSON-LD,
propriétés de page, annotations sémantiques typées, variables, catégories,
liens, modules et paramètres/dépendances de cache. Les 116 différences
historiques d'encodage JSON par rapport à Lua restent explicitement
normalisées par le comparateur indépendant ; aucune différence nouvelle
n'a été acceptée. Le banc vérifie les créations, modifications et suppressions,
les écritures différées, les jobs SMW, le partage de variables Lua/PHP,
l'édition d'un modèle dépendant et les hooks de parenté existants. Les files
sont vides à la fin des trois exécutions.

Le banc vérifie désormais aussi **16 comparaisons de profils d'effets** : mêmes
appels et échecs de requêtes/écritures, succès des caches, appels de messages
et prétraitements, après retrait des durées. Ces assertions sont intégrées à
la série SQLite 2 et à MariaDB ; les profils de la série 1 ont été comparés
séparément avec le même résultat. Le profilage reste optionnel et ne change
pas les résultats.

Les tests ajoutés couvrent **36 438 assertions** de collections, **32 assertions**
de réutilisation du moteur entre langues et données différentes, **10 cas**
lexicaux du générateur, et une régénération exacte des deux moteurs depuis les
snapshots Lua inchangés. Les vérifications existantes sont conservées et relancées.

Les suites PHP autonomes passent sous **8.3 et 8.2** : 64 vérifications des
frontières SQL sous 8.3, 56 sous 8.2 sans mysqli, 1 652 équivalences de records,
33 assertions d'adaptateur, FIFO, i18n et journalisation. Les oracles Lua 5.1
passent : **3 888** conditions, **3 301** opérations de texte/découpage,
**174** rendus Argument avec effets et **2 510** traductions. Les 13 fixtures
MediaWiki dans les deux modes et les 14 opérations natives passent avec et
sans les extensions optionnelles. Les tests d'internationalisation vérifient
les surcharges MediaWiki et six langues ResourceLoader pour chacun des contenus
EN/FR ; les 390 messages documentés et 12 combinaisons JavaScript sont validés.
Le banc complet de cette phase a été exécuté sous PHP 8.3 ; PHP 8.2 n'a reçu
que la suite autonome, sans revendiquer une nouvelle validation SMW complète.

Les résultats structurés, échantillons bruts et profils sont disponibles dans
[native-php-followup.json](../tests/results/native-php-followup.json).

### Opérations ciblées

Benchmark PHP 8.3 exécuté séparément : 21 échantillons en ordre mélangé,
3 itérations par échantillon, avec égalité des résultats avant toute mesure.
La référence charge réellement les classes du commit `377c55a`.

| Sonde | Avant, ms | Après, ms | Variation | Pic supplémentaire avant → après, Kio |
| --- | ---: | ---: | ---: | ---: |
| Parcours de 2 000 éléments | 0,2132 | 0,0848 | −60,23 % | 36,05 → 0,59 |
| FIFO 600 entrées, 1 000 évictions | 9,4178 | 0,3965 | −95,79 % | 144,30 → 92,33 |
| FIFO 2 000 entrées, 1 000 évictions | 26,4487 | 0,6772 | −97,44 % | 382,99 → 373,52 |
| 40 textes CSV de 20 éléments, lus 4 fois | 0,3405 | 0,1331 | −60,91 % | 218,27 → 73,05 |
| Mêmes 40 textes, lus une seule fois | 0,0810 | 0,1009 | **+24,50 %** | 54,95 → 67,46 |
| Déduplication/jointure SEO, 2 000 éléments | 0,5025 | 0,2842 | −43,44 % | 36,74 → 1,17 |

Les valeurs sont des médianes de temps écoulé. Les temps CPU bruts sont aussi
conservés dans le JSON. Les pics mesurent les allocations supplémentaires de
la sonde, après allocation des listes d'entrée et échauffement, pas la mémoire
totale d'une page. Les FIFO sont volontairement saturées : sur une page qui
n'atteint pas leur limite, ces évictions n'existent pas. Les textes CSV uniques
coûtent environ **0,020 ms** et **12,5 Kio** supplémentaires dans cette sonde ;
les gains de mutualisation ne doivent donc pas être généralisés aux lectures uniques.

### Parsing complet avec SMW

Deux séries successives de 15 échantillons, agrégées en 30 valeurs par
scénario/mode. Les quatre variantes avant/après sont échauffées puis mélangées
à chaque répétition. Le profilage est désactivé pour ces valeurs ; il dispose
de son propre bloc de mesures. Valeurs médianes en millisecondes :

| Scénario | Reference avant → après | Variation | Optimized avant → après | Variation |
| --- | ---: | ---: | ---: | ---: |
| Argument riche EN | 29,258 → 29,238 | −0,07 % | 29,860 → 29,321 | −1,80 % |
| Débat riche EN | 51,823 → 52,542 | +1,39 % | 54,527 → 50,838 | −6,76 % |
| Argument riche FR | 28,874 → 29,394 | +1,80 % | 28,918 → 28,366 | −1,91 % |
| Débat riche FR | 57,734 → 55,988 | −3,02 % | 57,622 → 55,090 | −4,39 % |
| Parents EN | 21,298 → 21,662 | +1,71 % | 22,216 → 21,298 | −4,13 % |
| Parents FR | 21,268 → 20,842 | −2,00 % | 21,183 → 20,909 | −1,29 % |
| Initialisation | 20,659 → 21,269 | +2,95 % | 23,147 → 21,767 | −5,96 % |
| Cartes | 23,555 → 25,655 | **+8,92 %** | 24,754 → 24,037 | −2,90 % |

**Aucun gain global uniforme n'est établi.** Le scénario cartes/reference est
moins favorable dans l'agrégat. Sa différence médiane appariée est toutefois
de +3,087 ms dans la première série et −0,298 ms dans la seconde : la
variation n'est pas stable d'une série à l'autre. Des durées élevées sont
présentes dans les deux versions ; certains P95 augmentent fortement, par
exemple initialisation/reference de 23,409 à 173,917 ms et cartes/optimized
de 34,848 à 164,009 ms. Les P95 de toutes les variantes sont conservés.

Ce banc long conserve les artefacts de plusieurs moteurs et partage les
ressources de l'environnement. Les séries ne permettent pas d'attribuer
causalement ces pointes au code applicatif, au magasin SMW ou au ramasse-miettes.
Les profils montrent les mêmes nombres de requêtes et d'effets ; cette phase
n'élimine aucune requête SMW. Les cinq échantillons MariaDB servent surtout
à la validation fonctionnelle ; leurs durées brutes restent exploratoires.
Un cycle HTTP et une charge concurrente représentative restent à mesurer.

## Reproduction

Dans un environnement de test isolé avec MediaWiki 1.43, PHP 8.3 et les
dépendances du [laboratoire complet](debatephp-fullstack-validation.md) :

```sh
git worktree add --detach ../wikidebates-before-six 377c55ac212e45066340ae22e79d944f4c0ebd0d
php tests/run.php
python3 debatephp/tests/conditions_differential.py php
python3 debatephp/tests/serialized_differential.py php
python3 debatephp/tests/argument_differential.py php
python3 debatephp/tests/differential_i18n.py php
python3 debatephp/tests/closure_captures.py
python3 debatephp/tests/regenerate.py
php tests/benchmarkNativeCollections.php --baseline=/absolute/path/to/wikidebates-before-six --output=/tmp/wikidebates-collections.json
WD_OPTIMIZATION_BASELINE=/absolute/path/to/wikidebates-before-six MW_INSTALL_PATH=/path/to/mediawiki-1.43 WD_FULLSTACK_AUTOLOAD=/path/to/dependencies/vendor/autoload.php python3 tests/fullstack.py --php php --output /tmp/wikidebates-six.json
```

Les scripts Python de génération nécessitent `luaparser` ; les oracles Lua
nécessitent aussi `lupa`. Le banc MariaDB exige une instance jetable sans
réseau et un socket de laboratoire ; sa procédure est détaillée dans le
[rapport précédent](native-php-optimizations.md#reproduction).

Les benchmarks doivent être exécutés successivement, sans autre test coûteux
en parallèle. Le benchmark de collections charge effectivement le FIFO du
commit de référence. L'ancien benchmark de décodage mesure une autre phase
de la migration et ne sert pas à annoncer un gain supplémentaire ici.

## Risques et limites

- Le cache CSV est limité en nombre d'entrées, pas en octets. Les textes
	uniques introduisent un coût de préparation et de mémoire ; la mutualisation
	profite aux textes relus. Il ne persiste jamais d'un rendu à l'autre.
- Le parcours sans copie explicite utilise les règles de copie sur écriture
	de PHP. Une mutation de la liste pendant le parcours peut déclencher une
	copie ; les objets imbriqués gardent volontairement leur identité partagée.
- La normalisation des records est un prérequis des consommateurs internes
	optimisés. Les données passant par des adaptateurs ou les structures
	sémantiques partagées conservent leurs contrôles et leurs `Table`.
- Aucune suppression globale de `Lua::truth()` ni remplacement global de
	`Table` n'est proposé. Les possibilités restantes doivent encore démontrer
	leur équivalence, notamment aux frontières des hooks et de l'encodage JSON.
- Les accès SMW passent toujours par les adaptateurs éprouvés ; aucune
	écriture directe dans le Store ni refonte des requêtes n'est introduite.
	La propagation des erreurs SQL reste testée.
- Les pages et graphes du laboratoire sont synthétiques. Ils ne permettent
	pas de conclure sur le débit HTTP, les accès concurrents, les caches FPM,
	les extensions locales ou les pages les plus volumineuses du wiki réel.
	Une validation représentative demanderait un export XML anonymisé des
	pages et modèles concernés, leurs propriétés sémantiques attendues, et les
	versions/configurations non secrètes des extensions, dans une nouvelle base
	de test. Aucune copie de base de production n'a été utilisée.

Le mode `reference` reste le mode par défaut. Les hooks, paramètres,
traductions et dépendances n'ont pas été retirés. Le chargement de Wikidebates
reste effectué par un seul `wfLoadExtension( 'Wikidebates' )` dans le banc.
`LocalSettings.php` n'est ni lu par ce banc ni modifié par cette phase.
Aucun accès, déploiement ou changement de base de production, et aucune
fusion de PR, ne font partie de ce travail.
