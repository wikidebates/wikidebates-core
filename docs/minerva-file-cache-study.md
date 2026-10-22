# Faisabilité d'un cache fichier pour Minerva

L'étude ci-dessous concerne le prototype initial. Une extension autonome
installable, [MinervaFileCache](../extensions/MinervaFileCache/README.md), est
maintenant disponible avec ses propres tests d'intégration et sa notice
d'activation. Elle n'est pas chargée automatiquement par Wikidebates.

## Conclusion

Un cache fichier HTML distinct pour Minerva est techniquement possible sur
MediaWiki 1.43.11. Le prototype isolé conserve le HTML complet et invalide sa
copie après édition de la page, purge et modification d'un modèle dépendant.
Il apporte un gain supplémentaire **avec le parser cache déjà chaud**.

Il reste un prototype de laboratoire, chargé uniquement par le banc de test.
Le manifeste de Wikidebates, les requêtes du mode lecture et les sites français
et anglais ne sont pas modifiés. Aucune configuration serveur n'est activée.

## Pourquoi le mobile n'utilise pas le cache actuel

Dans la branche REL1_43 de MobileFrontend 2.4.1, le hook
[`onHTMLFileCache__useFileCache`](https://github.com/wikimedia/mediawiki-extensions-MobileFrontend/blob/6ad6fec0e68c585bd0a5b047bd3ab404160ea090/includes/MobileFrontendHooks.php#L1062)
renvoie `false` pour la vue mobile. Cela désactive le cache HTML fichier pour
cette vue ; cela ne désactive pas le parser cache du contenu de la page.

Le cache natif [`HTMLFileCache`](https://github.com/wikimedia/mediawiki/blob/8c65f3e15450df456092626693350a8c729bd003/includes/cache/HTMLFileCache.php)
identifie ses fichiers par page et action, sans distinguer Vector et Minerva.
Retirer simplement le veto de MobileFrontend risquerait donc de mélanger les
habillages. Le prototype utilise un sous-répertoire `minerva-study-v1` et laisse
le cache desktop natif fonctionner séparément.

## Résultats du 22 octobre 2026

MediaWiki 1.43.11, PHP 8.3.30, SQLite, Vector, MinervaNeue, MobileFrontend et
Wikidebates sont réellement chargés. La page synthétique contient deux sections,
un modèle et 200 paragraphes avec lien et mise en forme. Le navigateur est simulé
par un User-Agent Android ; il ne s'agit pas d'un téléphone physique.

Après calibration du compteur avec un rendu froid, la comparaison porte sur
31 requêtes HTTP séquentielles de chaque variante, en loopback dans le même
conteneur sans réseau externe. Le parser cache est chaud avant la mesure.

| Mesure | MobileFrontend habituel | Prototype, fichier Minerva chaud |
| --- | ---: | ---: |
| Durée HTTP totale médiane | 45,12 ms | 9,43 ms |
| 95e percentile, rang 30 sur 31 | 48,26 ms | 12,09 ms |
| Constructions d'habillage | 31 | 0 |
| Parsings du contenu de la page | 0 | 0 |
| Autres parsings observés, notamment messages d'interface | 279 | 0 |

La réduction de médiane est de **79,1 %**, soit environ **4,8 fois plus rapide**
dans ce scénario. Une première exécution complète avait donné 47,54 ms contre
9,23 ms ; le tableau et les [résultats bruts](../tests/minervaFileCache/results.json)
correspondent à l'exécution finale sur une nouvelle base jetable.

Le point essentiel est le zéro parsing de page dans les deux colonnes : le gain
observé ne vient pas d'une désactivation du parser cache. Le fichier HTML évite
le travail qui reste après ce cache, dont l'habillage et ses messages. Le parser
cache reste utile pour reconstruire le HTML après expiration ou invalidation.

Les durées mesurent la réponse HTTP complète, pas uniquement le TTFB. Les
requêtes chronométrées demandent `identity` ; les fichiers sont gzip sur disque
et leur restitution HTTP gzip est testée séparément. Les mêmes réglages de
compression de sortie sont utilisés des deux côtés. Il n'y a pas de mesure de
charge CPU, de capacité serveur, de concurrence ou de réseau mobile réel.

## Vérifications fonctionnelles

Les 125 assertions du scénario passent, avec notamment :

- HTML complet identique entre la création du fichier et sa restitution,
  y compris en gzip ; aucune transformation du contenu à lire n'est ajoutée.
- Réponse mobile en Minerva et cache Vector inchangé après activation du
  prototype mobile.
- Exclusion des requêtes avec cookies, d'une session réellement connectée
  (connexion API puis vérification de l'identité), des POST, des redirections et
  des variantes `uselang`, `useskin` et `oldid`.
- Après édition : cache manquant puis nouveau HTML contenant la version deux.
- Après purge : cache manquant puis reconstruction.
- Après modification du modèle et traitement des jobs : invalidation du HTML
  dépendant et restitution de la nouvelle valeur du modèle.

Le laboratoire raccorde l'invalidation au hook `TitleSquidURLs` du pipeline
d'invalidation HTML de MediaWiki 1.43. Le test de dépendance est important :
invalider uniquement la page directement modifiée laisserait des copies périmées
après modification d'un modèle.

## Ce qu'il reste à établir avant une installation

Le prototype remplace `Article` seulement dans le laboratoire, pour contourner
le veto mobile et prouver le chemin de lecture d'un fichier séparé. Cette
technique ne constitue pas encore une intégration de production : elle ne
préserve pas les éventuels vetos d'autres extensions au hook du cache fichier.
Il faut concevoir cette intégration en respectant ces vetos et les règles de
mise en cache de la pile réelle.

La prochaine validation doit porter sur une préproduction avec les vrais modèles,
Semantic MediaWiki, les extensions et la configuration de fr/en.wikidebates.org.
Elle doit vérifier les variantes mobile/desktop, langue, cookies et préférences,
les en-têtes de cache et de sécurité (dont CSP), les notifications et les pages
à accès restreint, ainsi que les suppressions, renommages et changements de
configuration. Le test actuel exclut tous les cookies : il ne permet pas de
déduire la proportion réelle des visiteurs anonymes éligibles.

Il faut aussi vérifier les écritures et invalidations concurrentes, la
reconstruction simultanée d'un fichier expiré, la durée de conservation et la
purge lors des déploiements. Le banc réutilise les opérations de fichier natives ;
il n'ajoute pas de mécanisme de verrouillage ou d'écriture atomique.

La moitié des visites sur téléphone constitue donc une bonne raison d'évaluer
ce cache, mais ne signifie pas une réduction de 79 % des ressources globales :
il manque le taux de hits réel, le coût des reconstructions et les mesures sous
charge sur des pages représentatives.

## Conséquence pour le mode lecture des arguments

Ce prototype accélère les vues HTML mobiles ordinaires. Les appels actuels à
`action=parse` ne lisent pas le cache fichier des vues et ne bénéficient donc
pas directement de ce travail. Le parser cache de leur chemin reste intéressant.

Récupérer une vue HTML mise en cache puis en extraire les sections du mode
lecture est une piste distincte : elle peut réduire le travail serveur, mais
transfère aussi l'habillage et davantage de HTML. Il faudra comparer sur des pages
réelles les octets gzip, les latences, les variantes et le résultat extrait avant
de changer cette requête. L'[étude précédente des transferts](argument-transfer-study.md)
reste applicable ; aucune nouvelle API d'extraits n'est introduite ici.

## Reproduction

Les sources épinglées, les commandes d'installation isolée et la méthode figurent
dans le [mode d'emploi du laboratoire](../tests/minervaFileCache/README.md).
Le [JSON des résultats](../tests/minervaFileCache/results.json) contient les
échantillons, compteurs, assertions et révisions précises des dépendances.
