# Mesure locale de la réutilisation des fichiers

Mesure du 24 octobre 2026, sur un wiki SQLite jetable MediaWiki 1.43.11,
PHP 8.3.30, Vector, MinervaNeue, MobileFrontend et Cite, sans accès production.
Le témoin utilise exactement le code de `main` au commit `038129f`.
Chaque série comporte 15 lectures HTTP en loopback après une lecture de chauffe.
L’argument synthétique contient environ 200 paragraphes, une référence Cite et
une dépendance de modèle. Les sources complètes et le parser cache sont chauds.

| Chemin | Médiane HTTP (ms) | Médiane CPU PHP (ms) | Pic mémoire PHP allouée | Passages du pipeline par demande |
| --- | ---: | ---: | ---: | ---: |
| Témoin : construction ApiParse après absence d’extrait | 34,07 | 33,30 | 4 Mio | 1 |
| Nouveau : fichier Vector après absence d’extrait | 17,48 | 16,67 | 4 Mio | 0 |
| Nouveau : fichier Minerva après absence d’extrait | 18,31 | 17,59 | 4 Mio | 0 |
| Nouveau : repli ApiParse, sans fichier réutilisable | 40,07 | 39,23 | 4 Mio | 1 |
| Témoin : extrait déjà préparé | 7,37 | 6,95 | 4 Mio | 0 |
| Nouveau : extrait déjà préparé | 7,90 | 7,41 | 4 Mio | 0 |

Sur ce cas, utiliser Vector réduit d’environ **49 %** le temps de construction
HTTP médian et de **50 %** le CPU PHP par rapport au témoin ApiParse. Le repli
sans fichier coûte davantage : contrôles des sources et adaptation du HTML.
Le pic mémoire allouée mesuré ne diminue pas. Les extraits déjà préparés gardent
un coût comparable, avec une petite différence entre ces séries successives.

Ce résultat ne mesure ni un gain global du site, ni un gain en production. Il
n’inclut pas SMW, les modèles réels, les grands fichiers, des disques froids,
la contention entre serveurs, le réseau utilisateur ou le CPU du navigateur.
Les chiffres ne doivent pas être extrapolés à tous les arguments. Les 79 % de
l’ancienne étude Minerva concernent une autre implémentation et ne sont pas repris.

## Reproduction et ressources

Préparer le [laboratoire](../tests/readingFragments/README.md). Lancer
`tests/readingFragments/benchmark.php` avec `WD_READING_BENCHMARK=1` transmis au
conteneur. Le script contrôle les sources observées et la référence avant toute
mesure. Il retire seulement l’extrait entre deux demandes de construction ; il
retire ensuite Vector pour forcer Minerva, puis désactive Minerva pour le repli.
Le hit final garde l’extrait préparé.

Le bootstrap mesure le CPU utilisateur et système **dans chaque requête**, entre
son démarrage et son arrêt, par `getrusage()`. Il journalise séparément le pic de
mémoire allouée et les passages `ParserOutputPostCacheTransform`. Les opérations
CLI qui invalident les extraits entre mesures ne sont pas comptées dans le coût
de la lecture. Les séries sont séquentielles et les données restent des mesures
locales exploratoires, sans intervalle de confiance.

Pour le témoin, extraire `git archive 038129f` dans un répertoire séparé et y
copier uniquement les quatre fichiers du banc `bootstrap.php`, `http.php`,
`manage.php`, `benchmark.php`. Monter ce témoin comme `/src` et initialiser une
base jetable. Refaire la même procédure avec le code modifié. Ne pas monter une
installation existante et ne publier aucun port du conteneur.

Données brutes : [témoin](../tests/readingFragments/baseline-benchmark.json) et
[nouvelle stratégie](../tests/readingFragments/file-source-benchmark.json).
