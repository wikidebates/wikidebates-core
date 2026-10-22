# Étude des transferts d'arguments et du parser cache

## Décision

**Conserver la requête actuelle `action=parse&page=…&prop=text` et le découpage
dans le navigateur.** Les mesures ne justifient pas de remplacer ce chemin par
un rendu partiel qui perdrait le parser cache. Une API dédiée pourrait conserver
ce cache, mais son intérêt apparaît limité aux économies de transfert mesurées :
environ **1,35 à 1,81 Ko gzip par première lecture** sur l'échantillon principal.

Cette étude ne modifie aucun module de production. Les extraits déjà lus restent
conservés comme auparavant : leurs relectures ne téléchargent rien. Le partage
entre préchargement et lecture n'est pas modifié.

## Deux caches à distinguer

Le **parser cache MediaWiki** conserve le résultat du rendu wiki et peut éviter
de relancer le parser, les modèles et leurs appels aux extensions. Le **cache
HTTP** conserve une réponse à une URL donnée. Un en-tête Cloudflare `DYNAMIC`
ou HTTP `private, max-age=0` ne prouve donc pas l'absence de parser cache.

Les deux sites annoncent MediaWiki **1.43.11**. Le code de cette version a été
vérifié, et pas seulement celui du checkout local, qui annonce 1.43.6 :

- [`ApiParse::getParsedContent`, lignes 832–874](https://github.com/wikimedia/mediawiki/blob/1.43.11/includes/api/ApiParse.php#L832)
  utilise `getPageParserOutput` dans le cas ordinaire d'une page existante ;
- [`getPageParserOutput`, lignes 182–202](https://github.com/wikimedia/mediawiki/blob/1.43.11/includes/api/ApiParse.php#L182)
  appelle `WikiPage::getParserOutput`, le chemin qui peut lire et alimenter le
  parser cache ;
- [`section`, lignes 851–861](https://github.com/wikimedia/mediawiki/blob/1.43.11/includes/api/ApiParse.php#L851)
  extrait le contenu wiki puis appelle `getContentParserOutput`, au lieu de ce
  chemin de cache de page ;
- [le rendu de `text` fourni à l'API](https://github.com/wikimedia/mediawiki/blob/1.43.11/includes/api/ApiParse.php#L442)
  est explicitement traité sans lecture ni écriture de ce cache.

Le chemin actuel est donc **éligible** au parser cache. Cela ne mesure pas son
taux de succès sur les sites : les options de rendu, l'expiration, les extensions
et l'état du backend peuvent influer sur son utilisation effective. Aucun journal
serveur ni compteur interne de cache n'a été consulté.

## Protocole du 22 octobre 2026

Référence de l'extracteur : `6f1877bfd13e168233a567a3649a8558ee27d63a`.

Une première série a couvert trois pages par wiki sélectionnées parmi les
24 derniers rattachements à la catégorie Arguments. Elle contenait surtout de
petites pages ; ses [résultats préliminaires](../tests/results/argument-transfer-study-preliminary.json)
sont conservés. Pour inclure des pages plus riches, la série principale a élargi
la sélection :

- les 500 derniers rattachements à la catégorie Arguments ;
- les arguments présents dans les 30 premières pages principales d'au moins
  10 000 octets retournées par `allpages` ;
- après dédoublonnage, 509 candidats français et 501 anglais ;
- sélection de la page la plus petite, de la médiane et de la plus grande de
  chaque ensemble, selon la longueur du **wiki stocké**, pas celle du HTML rendu.

Cela constitue un échantillon de tailles contrastées, pas une recherche exhaustive
des plus gros arguments du site ni une mesure des habitudes des visiteurs.

Les six pages sélectionnées ont été lues sur Vector et Minerva. Pour chaque
combinaison, une lecture de découverte récupère les sections et la révision,
une requête teste `section=1`, puis trois lectures répétées utilisent les
paramètres de production : `page`, `prop=text`, `formatversion=2`, `usearticle=1`,
`redirects=1`, langue du wiki et habillage. L'ordre des combinaisons est mélangé
avec une graine fixe. Les lectures répétées interviennent après la découverte ;
elles ne sont **pas** présentées comme des lectures à cache froid.

La série principale comporte **36 réponses de lecture chronométrées**, 12 sondes
de section et 18 requêtes de découverte/sélection, soit **66 GET**. La première
série en comportait 64. Il n'y a eu aucune purge, écriture, modification de page,
demande de wikitexte à rendre ou paramètre destiné à contourner un cache. Une
seule requête est envoyée à la fois par wiki, avec une pause de 200 ms.

Le secret configuré est transmis uniquement dans `X-Wikidebia-Access` aux deux
domaines autorisés. Les scripts refusent de suivre les redirections. Les rapports
conservent les titres publics, les tailles, empreintes et durées, sans HTML
téléchargé ni valeur de secret.

## Taille réellement utile après gzip

Les réponses HTTP utilisent effectivement gzip. Le tableau ci-dessous compare
deux **projections locales** : même objet JSON, même encodeur, même gzip niveau 6,
avec le HTML complet ou uniquement l'extrait du mode lecture. Il ne mélange pas
la compression du serveur et celle de la projection. Les résultats sont identiques
pour les deux habillages dans ces six cas.

| Wiki et taille du wiki stocké | JSON complet gzip, projection | JSON avec extrait gzip, projection | Économie | Réduction |
| --- | ---: | ---: | ---: | ---: |
| Français, 40 octets | 2 499 octets | 966 octets | 1 533 octets | 61,3 % |
| Français, 1 164 octets | 3 421 octets | 1 641 octets | 1 780 octets | 52,0 % |
| Français, 13 652 octets | 8 643 octets | 6 998 octets | 1 645 octets | 19,0 % |
| Anglais, 39 octets | 2 157 octets | 806 octets | 1 351 octets | 62,6 % |
| Anglais, 1 092 octets | 3 157 octets | 1 469 octets | 1 688 octets | 53,5 % |
| Anglais, 10 728 octets | 6 960 octets | 5 155 octets | 1 805 octets | 25,9 % |

Le HTML brut varie de 10,4 à 34,2 Ko dans cette série. Son volume ne représente
pas le téléchargement effectif : les médianes des corps HTTP gzip observés sont
de **2 151 à 8 611 octets**. Les petits écarts avec la projection proviennent
notamment de l'encodage et de la compression. En-têtes et coût TLS sont exclus
de ces tailles.

Les pages les plus riches gardent une grande partie de leur contenu dans
l'extrait. L'économie absolue reste de l'ordre de quelques Ko, même quand
l'économie sur les petites pages dépasse 60 %. Sur une liaison très lente ou
à grande échelle, cette économie peut avoir un intérêt ; elle ne prouve pas un
gain de temps équivalent sur les sites.

## Durées et contenu

Les médianes HTTP par combinaison sont comprises entre **482 et 790 ms** pour
les lectures répétées. Elles incluent le proxy de l'environnement, les connexions,
TLS, le traitement du site et le transfert. Elles ne mesurent ni le temps CPU
du parser, ni une latence mobile réelle, ni le gain d'une nouvelle API.

L'extraction utilise la vraie fonction `wkReadingExtractFragment` de
`modules/reading/core.js`, exécutée par Node, sans réécriture de son algorithme.
Sur 21 petits chronométrages locaux par réponse, les médianes sont toutes
inférieures à **0,009 ms**. Ce microbenchmark ne mesure pas l'insertion DOM,
le rendu ou les performances d'un téléphone, mais ne suggère pas un coût de
découpage important dans ce contexte. Les empreintes des extraits sont stables
entre les trois lectures de chaque combinaison.

**Les douze sondes `section=1` ont renvoyé `nosuchsection`.** Les en-têtes
Summary, Citations, Références, Justifications, Objections et Parent_debates
sont générés par les modèles ; leur `index` de section éditable est vide dans
les réponses étudiées. De plus, le mode lecture attend l'ensemble compris entre
Summary et Parent_debates, pas seulement le résumé. Le paramètre `section` ne
constitue donc pas un remplacement compatible de la requête actuelle.

## Ce qu'une API d'extraits devrait préserver

Une API dédiée **peut** réutiliser le parser cache : récupérer le même rendu de
page via les services MediaWiki, puis découper son HTML côté serveur. Elle
réduirait la réponse réseau ; elle ne supprimerait pas le travail initial de
rendu lors d'un échec du parser cache et ajouterait une étape d'extraction.

Avant de l'adopter, il faudrait vérifier les droits de lecture, les mêmes
options de parser et d'habillage, les langues, l'expiration et les dépendances
du rendu. Il faudrait aussi adapter le chargement et les aperçus mobiles : le
client actuel attend du HTML complet avec la borne Parent_debates ; un extrait
renvoyé sous `parse.text` sans modification du client ne serait pas compatible.

La projection du tableau remplace uniquement le texte pour rendre la comparaison
de volumes homogène. **Ce n'est ni un contrat d'API prêt à déployer, ni la mesure
d'une implémentation serveur.** Aucun gain de latence HTTP de cette API n'est
annoncé. Un essai de prototype avec le vrai parser cache, puis des mesures serveur
et sur réseau mobile, serait nécessaire pour le démontrer.

Compte tenu des relectures déjà sans réseau, du faible coût local de découpage
et de l'économie mesurée, cette API reste une piste secondaire. Préserver le
chemin de parser cache actuel est prioritaire.

## Reproduction

Depuis un environnement ayant le secret réseau configuré et un accès aux deux
domaines, avec Python 3 et Node disponibles :

```sh
python3 tests/argumentTransferStudy.py --output /tmp/argument-transfer-study.json
```

Ne pas passer le secret comme argument de commande. Le script enregistre les
résultats sans modifier les pages ni le fonctionnement de l'extension. Les
[résultats principaux](../tests/results/argument-transfer-study.json) incluent
les paramètres de méthode, les révisions, les trois échantillons, les en-têtes
HTTP pertinents, les sondes de section et les projections par réponse.
