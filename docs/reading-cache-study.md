# Clés des caches et étude des relectures

Les extraits de lecture utilisent maintenant exactement la même clé que les
réponses API : la liste triée de **tous** les paramètres de la requête, notamment
la page, la langue et l'habillage. La clé est capturée avant le chargement et
conservée jusqu'au rangement de l'extrait. Un changement de réglage pendant une
requête ne peut donc pas ranger une réponse française sous une clé anglaise.

Le cache des extraits reste **sans limite et sans éviction** pour la durée de la
page. Un retour vers un argument déjà ouvert, avec les mêmes paramètres, ne
nécessite aucune nouvelle requête, même après son éviction du cache des réponses
complètes. Ce dernier garde sa limite existante de 50 entrées. Ni délai
d'expiration ni requête de validation supplémentaire ne sont ajoutés. Les
paramètres API, les bornes d'extraction et les préchargements sont conservés.

Changer de langue ou de paramètres peut maintenant demander une autre réponse,
comme le faisait déjà le cache API. Réafficher un ancien extrait dans un autre
contexte aurait été incorrect. Revenir aux paramètres précédents réutilise
l'extrait correspondant, toujours conservé.

## Mesures locales facultatives

Après ouverture du mode lecture, les commandes suivantes sont disponibles dans
la console du navigateur :

```js
Wikidebates.wkReadingCacheDiagnostics.start();
// Lire et rouvrir des arguments normalement, puis consulter les compteurs :
Wikidebates.wkReadingCacheDiagnostics.snapshot();
// Terminer la mesure et récupérer le bilan :
const bilan = Wikidebates.wkReadingCacheDiagnostics.stop();
```

La mesure est désactivée par défaut. `start()` démarre une nouvelle période de
mesure sans vider les caches. `snapshot()` ne modifie ni leur contenu ni leur
ordre d'éviction ; `stop()` arrête les mesures suivantes sans toucher aux caches.
Aucun envoi réseau, stockage persistant ou historique de navigation n'est ajouté.
Le bilan ne contient aucun titre ni HTML. Les clés sont gardées en mémoire
uniquement pendant la mesure pour compter les répétitions.

Le bilan fournit :

- `fragments` et `responses` : nombre d'entrées et longueur cumulée du HTML en
  unités UTF-16 (`htmlCodeUnits`), calculés seulement à la demande du bilan ;
- `opens`, `reopens`, `distinctKeys` : tentatives d'ouverture, répétitions de la
  même clé et clés distinctes **pendant la période de mesure** ; cela ne constitue
  pas un historique de toutes les ouvertures depuis le chargement de la page ;
- `sources.fragment`, `response`, `pending`, `network` : chargements terminés
  respectivement depuis un extrait, une réponse complète déjà en cache, une
  requête déjà en cours ou une nouvelle requête ;
- pour chaque source, nombre de chargements, délai moyen et maximal jusqu'à la
  disponibilité de l'extrait, en millisecondes ; ce délai inclut l'extraction mais
  **pas** l'insertion dans le DOM ni l'affichage à l'écran ;
- `failures` et `empty` : erreurs et réponses sans extrait. Une ouverture devenue
  obsolète peut terminer son chargement et être comptée sans être affichée.

Les temps incluent le léger coût des sondes activées. Aucun échantillon temporel
individuel n'est retenu. Un chargement encore en cours lors de `stop()` n'est pas
inclus comme terminé dans le bilan retourné.

Les longueurs HTML ne mesurent pas la mémoire réelle : `htmlCodeUnits * 2`
donne seulement la taille théorique du texte en UTF-16, sans les objets,
les clés, le DOM ou les structures internes. Le moteur JavaScript peut compresser
ou partager les chaînes, et un extrait peut conserver le stockage de la chaîne
complète dont il provient. Additionner ces tailles ne donne donc pas le tas du
navigateur. Une mesure mémoire complète demande les outils du navigateur.

## Étude reproductible dans Chromium

```sh
npm ci --prefix tests --ignore-scripts
npm run study:reading-cache --prefix tests -- \
  --baseline 2a9a526dcaae2acce58e7a75bc70d8ca3a2d590f \
  --output /tmp/reading-cache-study.json
```

`CHROMIUM_PATH` permet de sélectionner un Chromium déjà installé. La référence
Git doit être disponible localement. Aucun téléchargement de navigateur n'est
effectué. Les tests bloquent toutes les requêtes réseau du navigateur et
remplacent l'API par des réponses synthétiques, avec un délai fixé à 20 ms.
Les deux habillages utilisent leur véritable interface de lecture ; les données
ne comprennent pas de voisins à précharger. Le partage avec le préchargement
mobile est couvert séparément par les tests JavaScript existants.

L'étude relève le temps entre l'ouverture et l'insertion du contenu, puis la
prochaine frame. Elle mesure aussi le tas JavaScript global après une collecte
explicite **hors des fenêtres chronométrées**. Elle ne change pas les réglages du
collecteur en production. Les sondes facultatives de temps restent désactivées
pendant cette comparaison. L'ordre avant/après est alterné selon l'habillage.

Le replay séparé simule des plafonds LRU de 25, 50, 100 et 200 extraits, tout en
conservant un cache de 50 réponses. Ce replay ne plafonne jamais le vrai cache
et ne mesure pas la latence d'un cache plafonné. Il compte les requêtes qui
seraient nécessaires pour ces parcours précis.

## Résultats du 22 octobre 2026

Référence : `2a9a526dcaae2acce58e7a75bc70d8ca3a2d590f`. Chromium 154.0.8037.57.
Les [résultats bruts](../tests/results/reading-cache-study.json) conservent les
mesures avant/après et la méthodologie. Une seule série contrôlée est réalisée ;
ses différences de quelques dixièmes de milliseconde ne démontrent pas un gain
ou une régression en production.

| Parcours synthétique | Ouvertures | Relectures | Requêtes API avant / après, sur chaque habillage | Requêtes supplémentaires avec un plafond simulé de 50 |
| --- | ---: | ---: | ---: | ---: |
| 12 arguments, puis relecture des 12 | 24 | 12 | 12 / 12 | 0 |
| 80 arguments, puis deux passages sur les 20 derniers | 120 | 40 | 80 / 80 | 0 |
| 80 arguments, puis deux passages sur les 10 premiers | 100 | 20 | 80 / 80 | 10 |

Les fréquences de relecture (50 %, 33,3 % et 20 %) sont imposées par les parcours,
**pas observées chez les visiteurs**. Les relectures actuelles n'émettent aucune
requête supplémentaire. Dans le dernier scénario, un plafond de 50 ferait
passer le total de 80 à 90 requêtes : les premiers arguments ont aussi quitté
le cache des réponses complètes. Un plafond de 100 ou 200 ne provoque pas cette
situation dans un corpus de 80 arguments ; cela ne prouve pas son innocuité sur
des sessions plus longues.

Avec le correctif, la médiane de disponibilité du contenu relu se situe entre
1,2 et 1,7 ms selon le scénario et l'habillage ; la médiane jusqu'à la prochaine
frame entre 13,3 et 15,7 ms. Avant correction, ces plages étaient respectivement
de 1,2 à 1,7 ms et de 14,9 à 15,8 ms. Ces mesures ne comprennent aucune latence
HTTP réelle et ne remplacent pas un essai sur téléphone physique.

Après les parcours longs, les caches contiennent 80 extraits et 50 réponses,
soit 294 390 et 7 737 250 unités UTF-16 de HTML. La croissance du tas global du
navigateur après collecte se situe entre 10,52 et 12,05 Mio avec le correctif,
contre 10,52 à 12,04 Mio avant correction, selon le scénario et l'habillage.
Ces valeurs incluent l'interface, le DOM et les autres objets de la page ; elles
ne représentent ni le coût propre du cache d'extraits ni une mesure du site réel.

**Décision : conserver les extraits sans plafond.** Avant toute limitation,
recueillir sur des sessions représentatives la fréquence des relectures, la
mémoire réelle et les délais de réouverture. Le diagnostic local permet de
préparer ces observations après installation du correctif, sans collecte
automatique de données des visiteurs.

## Validation fonctionnelle

Les nouveaux tests vérifient la distinction des langues, habillages et autres
paramètres, le rangement d'une réponse après changement de langue pendant son
chargement, la relecture sans réseau au-delà des 50 réponses, les lectures depuis
une réponse préchargée ou en cours, et l'absence d'effet du diagnostic sur l'ordre
LRU. La suite Chromium existante vérifie séparément les gestes et la mise en page.
