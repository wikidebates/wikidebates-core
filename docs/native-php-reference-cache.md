# Retrait du plafond du cache des références — PR #3

Comparaison avec `05d5483f92edbff868a075939f823eb4a106148e`, qui contenait le plafond expérimental.

## Décision et comportement

Le plafond est retiré. Le cache partagé par rendu, présent dans `aca0e6d`, est rétabli **octet pour octet** dans `DecodedReferences.php`. Les références et citations décodées une première fois restent disponibles pour leurs autres consommateurs, notamment le HTML et le JSON-LD, sans nouveau découpage de la liste. Il est réinitialisé à chaque nouveau rendu ; il ne devient pas un cache partagé entre visiteurs.

Les trois autres optimisations de `05d5483` restent en place : parcours des arguments fusionnés, accumulateurs HTML locaux et normalisation PHP des titres. Les deux moteurs générés sont inchangés octet pour octet. Les plafonds déjà existants du CSV et des titres ne sont pas modifiés.

Les modes `reference` et `optimized`, le défaut `reference`, les hooks, les traductions, les paramètres et la compatibilité sont conservés. Aucun élément d’une référence n’est retiré et aucune dépendance n’est ajoutée.

## Mesures ciblées après retrait

| Sonde de décodage | Avant, plafonné, ms | Après, cache existant, ms | Variation |
| --- | ---: | ---: | ---: |
| references-normal | 0,05820 | 0,05109 | -12,21 % |
| references-pressure | 5,43926 | 5,79212 | 6,49 % |
| references-reused-list-40 | 0,03805 | 0,03433 | -9,77 % |
| references-reused-list-4000 | 7,23083 | 3,82118 | -47,15 % |

Mesures PHP **8.3.35**, 31 échantillons mélangés, dix opérations par échantillon, sources exactes de la référence chargées sous un autre namespace. Les sondes vérifient l’égalité des valeurs décodées avant de chronométrer. Les temps mesurent le décodage et son cache, pas le chargement d’une page ni une requête HTTP.

Le compromis inverse reste visible : sous pression de 4 000 petites listes distinctes, le pic supplémentaire passe de **465464 à 4736360 octets**. Le cache rétabli peut donc retenir davantage de mémoire pendant un rendu. Le retrait privilégie la réutilisation et évite le redécodage des grandes listes ; il ne constitue pas une amélioration uniforme de temps et de mémoire dans tous les scénarios.

Les mesures historiques [de la première sélection](native-php-measured-optimizations.md) et leur fichier JSON sont conservés comme résultats de `05d5483`. Elles ne sont pas remplacées par cette nouvelle série, et les gains de mémoire de l’ancien plafond ne décrivent plus le code courant.

## Vérifications effectuées

- PHP **8.3 et 8.2** : suites autonomes complètes réussies, avec **7 043 assertions** dans `measured_candidates.php`, ainsi que les suites de collections, lectures, références, réutilisation, i18n et erreurs SQL.
- Tests de réutilisation après 1 100 listes distinctes, de références volumineuses et de listes de 3 000 ou 4 000 éléments : la seconde lecture ne provoque aucun décodage supplémentaire. Les mutations d’un consommateur ne corrompent pas les données natives et les objets historiques gardent leurs identités partagées.
- Régénération exacte des deux moteurs ; lint PHP 8.3 des fichiers modifiés et tabulations vérifiés.
- Véritables **MediaWiki 1.43.11**, **SMW 6.0.1**, **SemanticScribunto 2.3.3**, **VariablesLua 1.7.0**, Variables **2.6.0-beta** et Scribunto REL1_43 : campagnes isolées SQLite **3.46.1** et MariaDB **10.11.19**.

| Base | Cas | Lua/PHP | Natif avant/après | Variantes persistées | Profils comparés |
| --- | ---: | ---: | ---: | ---: | ---: |
| SQLite | 144 | 288 | 288 | 70 | 24 |
| MariaDB | 144 | 288 | 288 | 70 | 24 |

Les comparaisons natives avec `05d5483` exigent le même HTML, JSON-LD, propriétés sémantiques typées, variables, catégories, liens, modules et dépendances de cache. Les comparaisons Lua gardent leurs seules normalisations historiques. Les écritures différées, suppressions/mises à jour, jobs, hooks de parenté, variables Lua/PHP et contrôles SQL passent. Les files sont vides et les bases jetables sont supprimées.

Les cinq échantillons par variante de ces campagnes servent à la non-régression ; ils ne démontrent pas un gain de performance des pages complètes. Aucun nouveau test HTTP/FPM, OPcache en service web, concurrence ou page de production n’est revendiqué. Le diagnostic GC antérieur n’est pas relancé : aucun changement du ramasse-miettes n’est introduit.

## Reproduction

Dans le laboratoire MediaWiki 1.43/PHP 8.3 décrit dans le rapport de validation, créer une copie détachée de `05d5483`, puis lancer :

```sh
php tests/run.php
php tests/benchmarkMeasuredCandidates.php --baseline=/chemin/laboratoire/avant --output=/chemin/laboratoire/cache.json
python3 debatephp/tests/regenerate.py
```

Pour la matrice réelle, définir `MW_INSTALL_PATH`, l’autoload des dépendances du laboratoire si nécessaire, `WD_OPTIMIZATION_BASELINE` vers cette copie et `WD_NATIVE_BENCHMARK_REPEATS=5`, puis lancer `tests/fullstack.py`. L’initialisation utilise une base synthétique jetable et `MW_CONFIG_CALLBACK`, sans charger les réglages d’un wiki existant. MariaDB exige l’opt-in et le socket de laboratoire documentés dans le rapport de validation.

Aucun accès à la production, aucune modification de `LocalSettings.php`, aucun déploiement ni fusion de la PR.

[Résultats bruts et empreintes](../tests/results/native-php-reference-cache.json).
