# Cinq optimisations complémentaires — PR #3

Cette phase compare les changements au commit déjà publié et installé
manuellement par l'utilisateur : `49e9e7994789fe63379b340c3d3758a225d91485`.
Les phases précédentes restent documentées séparément dans
[le premier rapport](native-php-optimizations.md) et
[la suite en six points](native-php-followup.md). Leurs gains ne sont pas
réattribués à cette phase.

## Changements

1. **Clés des caches SMW.** `CacheKey::semantic()` remplace deux accumulateurs
	`Table`, leur tri et les conversions de longueur par des tableaux PHP,
	un tri numérique, `strlen()` et `implode()`. Le format reste strictement
	identique : indices numériques triés, clés nommées ignorées, longueur en
	octets et traitement des valeurs `false`, `null`, vides et Unicode.
2. **Fragments HTML.** Les trois types de références et les citations
	assemblent leurs fragments dans des tableaux PHP. Les ajouts de bandeaux
	modifient l'accumulateur du demandeur par référence. L'ancien accumulateur
	`Table` reste accepté et conserve son identité partagée. Les catégories,
	variables et annotations sémantiques gardent leur ordre d'exécution.
3. **JSON-LD.** `TextEscaper::jsonValue()` convertit les valeurs sans créer de
	cellules PHP par référence. Les séquences contiguës commençant à 1 et les
	objets aux clés de même valeur numérique zéro évitent le tri. Ces dernières
	gardent l'ordre d'insertion puisque le tri PHP 8 est stable. Les clés
	clairsemées, mixtes, numériques ou inhabituelles conservent le tri numérique
	et la détection de séquence historiques. La conversion est pure ; les
	`Table` partagées ne sont pas modifiées et l'ordre final reste identique.
4. **Records natifs.** `DecodedReferences::values()` fournit des tableaux PHP
	aux chemins HTML et JSON-LD. Les objets intermédiaires par record ne sont
	plus nécessaires dans ces chemins. `parse()` et les anciens helpers restent
	disponibles : leurs enveloppes `Table` sont construites à la demande et
	gardent les identités de listes et de blocs partagés. Les mutations des
	enveloppes de compatibilité ne contaminent pas les vues natives et
	réciproquement. La différence historique entre la première citation
	d'Argument et la première citation valide de Debate reste conservée.
5. **Cache CSV sélectif et borné.** Les champs ordinaires conservent une
	admission immédiate. Lorsque le cache est plein en entrées ou en budget,
	un texte inconnu de plus de 1 024 octets de clé doit être relu avant de
	remplacer les données déjà conservées. Des indications de réutilisation
	des mots-clés et rubriques permettent une admission immédiate de ces champs.
	Les textes vides et les entrées trop volumineuses ne sont pas conservés.

Le générateur applique ces changements avec `nativeFurtherPass.py`. La
régénération des deux moteurs reste identique octet pour octet. Les snapshots
Lua restent inchangés. Les anciens helpers de décodage restent des accès de
compatibilité ; les chemins natifs du moteur utilisent les nouveaux accès.

## Budget et observations du cache CSV

Chaque rendu crée un nouveau `RenderData`. Ses limites internes sont :

- 256 entrées de résultats ;
- 131 072 octets de budget total de chaînes conservées ;
- 16 384 octets de budget par entrée ;
- 256 empreintes d'admission de 128 bits au maximum.

Le poids d'une entrée est `2 * strlen(clé complète)`. C'est une majoration du
volume cumulé de la clé et des chaînes découpées : le découpage et le trim
n'ajoutent aucun octet. Le budget ne prétend pas mesurer les allocations PHP
exactes et **ne limite pas à 128 Kio la mémoire totale** : les conteneurs PHP,
les enveloppes et les métadonnées ont leur propre coût, borné par les nombres
d'entrées et la taille admise. Les allocations transitoires pour analyser un
texte trop volumineux ne sont pas couvertes par ce budget de rétention.

Les empreintes ne servent qu'à l'admission. Même une collision d'empreinte ne
peut pas retourner le contenu d'une autre entrée : les résultats sont toujours
recherchés avec la clé complète, qui inclut le séparateur sans ambiguïté.

Le profilage existant, toujours optionnel, ajoute un bloc `preparation` avec
les seuls compteurs suivants : `calls`, `hits`, `misses`, `bypassed`,
`admitted`, `evicted`, `failures`, `entries`, `admission_entries`,
`payload_bytes` et `peak_payload_bytes`. Les deux derniers représentent les
poids conservateurs ci-dessus. Aucun texte, clé ou contenu de propriété n'est
journalisé. L'interface optionnelle `PreparationProfilingContext` préserve
les contrats existants `Context` et `ArgumentContext`.

Une politique d'attente de deux lectures pour tous les textes a été écartée
pendant la mise au point : elle pénalisait les réutilisations ordinaires et
pouvait augmenter les copies transitoires. La sélection finale ne demande une
seconde lecture que pour les gros textes inconnus sous pression du cache.

## Validation finale

MediaWiki **1.43.11**, PHP **8.3.35**, véritables SMW **6.0.1**,
SemanticScribunto **2.3.3**, VariablesLua **1.7.0**, Variables **2.6.0-beta**
et Scribunto REL1_43. Les conteneurs sont sans réseau. MariaDB est accessible
uniquement par le socket du laboratoire et chaque exécution supprime sa base.

| Exécution finale | Cas | Lua/PHP | Natif avant/après | Variantes enregistrées | Échantillons de parsing par scénario/mode |
| --- | ---: | ---: | ---: | ---: | ---: |
| PHP 8.3 / SQLite 3.46.1 | 140 | 280 | 280 | 50 | 30 |
| PHP 8.3 / MariaDB 10.11.19 | 140 | 280 | 280 | 50 | 5 |

Les files d'écritures et de jobs sont vides en fin de test. Les 16 comparaisons
de profils d'effets passent pour chaque exécution. Les observations CSV sont
présentes et respectent leurs limites dans les profils du banc. Les tests
MediaWiki des hooks vérifient aussi des succès de réutilisation réels des
mots-clés/rubriques et le contournement des champs vides.

Tests autonomes exécutés avec succès sous PHP **8.3 et 8.2** : **8 047**
assertions ajoutées sur les clés, le JSON, les identités, les bandeaux et les
bornes du cache ; **36 438** assertions de collections existantes ; **1 652**
cas de records comparés en vues natives et compatibles ; **32** assertions de
réutilisation entre langues ; **64 / 56** contrôles SQL (mysqli absent sous 8.2),
adaptateurs, FIFO, diagnostics et traductions. Le banc complet de cette phase
cible PHP 8.3 ; PHP 8.2 ne reçoit ici que les tests autonomes.

Les oracles Lua passent : **3 888** conditions, **3 301** opérations de texte,
**174** rendus Argument avec effets et **2 510** traductions. Les **10** cas
lexicaux et la régénération exacte des moteurs passent. Les **13** fixtures
MediaWiki dans les deux modes et **14** opérations natives passent avec/sans
extensions optionnelles, avec le chargement unique de Wikidebates. Les
surcharges MediaWiki et ResourceLoader sont vérifiées en six langues pour
chacun des contenus EN/FR, ainsi que **390** messages documentés, **623** entrées
historiques et **12** combinaisons JavaScript. Aucun catalogue n'est modifié.

Les comparaisons portent sur le HTML complet, le JSON-LD, les propriétés de
page et annotations sémantiques typées, les variables, catégories, liens,
modules et paramètres/dépendances de cache. Les corrections historiques de
l'encodage Lua restent normalisées par le comparateur indépendant ; aucune
nouvelle différence n'est acceptée. Les créations, modifications, suppressions,
écritures différées, jobs SMW et changements de modèles sont vérifiés.

Les profils avant/après sont comparés après retrait des durées et du nouveau
bloc d'observations CSV, contrôlé séparément. Les compteurs des appels aux
messages, prétraitements et accès sémantiques restent identiques. Les oracles
d'Argument comparent également les traces d'effets.
Cette phase ne réduit pas le nombre de requêtes SMW et ne change pas leurs
adaptateurs ni la préparation des écritures différées.

## Mesures ciblées

Le benchmark charge effectivement les classes du commit `49e9e79`, y compris
ses caches CSV et de records déjà existants. Il ne compare pas le nouveau
cache à un ancien décodeur sans cache. Chaque sonde vérifie l'égalité des
résultats avant les mesures : 31 échantillons mélangés de 5 itérations, en
PHP 8.3 CLI, sans base ni requête réseau.

| Sonde | Avant, ms | Après, ms | Variation | Pic supplémentaire avant → après, Kio |
| --- | ---: | ---: | ---: | ---: |
| 100 clés de requêtes SMW | 0,33726 | 0,09607 | -71,51 % | 12,61 → 12,38 |
| 40 références bibliographiques HTML | 0,12140 | 0,05224 | -56,97 % | 8,50 → 8,48 |
| 40 records, deux consommateurs | 0,05603 | 0,04878 | -12,93 % | 54,70 → 52,62 |
| Graphe JSON-LD de 40 citations | 0,03420 | 0,03297 | -3,58 % | 50,98 → 40,79 |
| JSON aux clés mixtes (compatibilité) | 0,00077 | 0,00086 | 12,02 % | 0,98 → 0,77 |
| CSV : 40 textes uniques de 20 éléments | 0,11237 | 0,12076 | 7,46 % | 67,46 → 66,26 |
| CSV : mêmes textes, quatre lectures | 0,14056 | 0,14805 | 5,33 % | 73,05 → 71,82 |
| CSV : quatre lectures avec indications de réutilisation | 0,14275 | 0,16582 | 16,17 % | 73,05 → 71,82 |
| CSV : 160 lectures du même jeton | 0,03421 | 0,03238 | -5,36 % | 9,12 → 9,11 |
| CSV : 10 gros textes uniques de 200 éléments | 0,23503 | 0,22964 | -2,29 % | 206,16 → 205,90 |
| CSV : mêmes gros textes, quatre lectures | 0,30662 | 0,31944 | 4,18 % | 206,16 → 205,90 |
| CSV : gros textes, quatre lectures avec indications | 0,28726 | 0,34279 | 19,33 % | 206,16 → 207,05 |
| CSV sous pression : 64 gros textes uniques | 1,41757 | 1,44473 | 1,92 % | 1191,19 → 585,97 |

La sonde sous pression conserve 30 entrées et 34 empreintes, avec un poids de
128 120 octets. Son pic supplémentaire diminue de 1 191,19 à 585,97 Kio
(environ 51 %), tandis que sa médiane augmente d’environ 1,92 %. Ce résultat
décrit cette sonde, pas une borne de mémoire totale PHP.

Résultats bruts, profils et empreintes :
[native-php-further-optimizations.json](../tests/results/native-php-further-optimizations.json).

Les pics supplémentaires sont mesurés après allocation des entrées et
échauffement ; ils ne sont pas la mémoire totale d'une page. Les sondes CSV
qui conservent toutes les sorties incluent cette rétention. La sonde de
pression lit successivement 64 textes CSV de 200 éléments en ne conservant
que leur compte ; elle observe donc surtout la rétention du cache.

Le coût du budget et de ses compteurs est visible sur plusieurs sondes CSV.
Les indications de réutilisation de la sonde portent sur davantage de textes
que les deux champs suivis par un rendu réel. Les petits écarts, notamment
sur les opérations submilliseconde, ne constituent pas une garantie de gain.
Le chemin JSON de compatibilité coûte légèrement plus de temps dans la
sonde mixte, malgré un pic mémoire inférieur. Ces compromis restent explicites.

## Parsing complet et limites

Une série finale de 30 échantillons par scénario et par variante sous SQLite,
avec échauffement puis ordre mélangé. Le profilage est désactivé pour les
mesures principales et dispose d’un bloc séparé. Médianes en millisecondes :

| Scénario | Reference avant → après | Variation | Optimized avant → après | Variation |
| --- | ---: | ---: | ---: | ---: |
| rich native argument en | 29,981 → 29,263 | -2,39 % | 28,790 → 29,035 | 0,85 % |
| rich native debate en | 58,741 → 59,010 | 0,46 % | 57,725 → 60,136 | 4,18 % |
| rich native argument fr | 28,840 → 27,763 | -3,73 % | 28,862 → 28,022 | -2,91 % |
| rich native debate fr | 54,016 → 57,596 | 6,63 % | 55,340 → 56,012 | 1,21 % |
| parents en | 22,837 → 22,157 | -2,98 % | 22,797 → 22,040 | -3,32 % |
| parents fr | 22,523 → 22,086 | -1,94 % | 22,552 → 21,686 | -3,84 % |
| initialization | 21,012 → 21,198 | 0,89 % | 21,058 → 19,861 | -5,69 % |
| cards | 23,459 → 24,943 | 6,32 % | 23,060 → 22,492 | -2,46 % |

**Aucun gain global uniforme n’est établi.** Les médianes varient de −5,69 %
à +6,63 %. Le débat riche FR/reference et les cartes/reference sont moins
favorables dans cette série. Certains P95 augmentent fortement, par exemple
Argument EN/optimized de 36,250 à 97,770 ms et Argument FR/optimized de
31,842 à 166,269 ms. Tous les P95 et échantillons sont conservés dans le JSON.
Les cinq échantillons MariaDB servent surtout à la validation fonctionnelle ;
leurs durées restent exploratoires. Les grands gains de certaines sondes
ne constituent donc pas une promesse de baisse des temps de page complète.


Le banc conserve les artefacts de plusieurs moteurs et partage les ressources
de l'environnement. Il ne représente ni une requête HTTP complète, ni la
concurrence, ni OPcache/FPM en production. Les pointes de durée ne sont pas
attribuées causalement au code, au magasin SMW ou au ramasse-miettes.

Les fixtures sont synthétiques. Les limites et l'admission sous pression du
cache CSV sont couvertes par les tests dédiés ; les pages du banc complet
ne constituent pas un échantillon représentatif de tous les grands champs
CSV du wiki réel. Une recette représentative demanderait des exports
anonymisés des pages et modèles, les propriétés sémantiques attendues et les
versions/configurations non secrètes des extensions dans une nouvelle base
de test. Aucune base de production n'a été utilisée.

Le chemin de compatibilité du décodeur peut conserver à la fois les records
natifs et leurs enveloppes lorsqu'un consommateur externe demande les anciens
objets. Les gains des chemins natifs ne sont pas annoncés pour ce cas.
Les structures partagées et les règles de vérité Lua ne peuvent pas être
supprimées globalement. Aucun remplacement général du Store SMW n'est introduit.

## Reproduction

Utiliser uniquement le laboratoire isolé MediaWiki 1.43 avec PHP 8.3 et les
dépendances du [banc complet](debatephp-fullstack-validation.md).

```sh
git worktree add --detach ../wikidebates-before-five 49e9e7994789fe63379b340c3d3758a225d91485
php tests/run.php
python3 debatephp/tests/closure_captures.py
python3 debatephp/tests/regenerate.py
python3 debatephp/tests/conditions_differential.py php
python3 debatephp/tests/serialized_differential.py php
python3 debatephp/tests/argument_differential.py php
python3 debatephp/tests/differential_i18n.py php
php tests/benchmarkNativePaths.php --baseline=/absolute/path/to/wikidebates-before-five --output=/tmp/wikidebates-paths.json
WD_NATIVE_BENCHMARK_REPEATS=30 WD_OPTIMIZATION_BASELINE=/absolute/path/to/wikidebates-before-five MW_INSTALL_PATH=/path/to/mediawiki-1.43 WD_FULLSTACK_AUTOLOAD=/path/to/dependencies/vendor/autoload.php python3 tests/fullstack.py --php php --output /tmp/wikidebates-further.json
```

Les commandes sont successives, sans benchmark concurrent. Pour MariaDB,
utiliser uniquement une instance jetable sans réseau, un socket de laboratoire
et les variables documentées dans le [rapport initial](native-php-optimizations.md#reproduction).

Le mode `reference` reste le défaut. Les modes, hooks, traductions et paramètres
sont conservés. Le chargement de Wikidebates reste effectué par un seul
`wfLoadExtension( 'Wikidebates' )`. Les nouvelles fonctions utilisent les
capacités de PHP 8.3, sans nouvelle dépendance externe. `LocalSettings.php`
n'est pas modifié, le banc ne le lit pas. Aucun accès ou déploiement en
production et aucune fusion de PR ne sont effectués par cette phase.
