# Navigation au premier clic, liens locaux et chargements prioritaires

Périmètre : MediaWiki 1.43, PHP 8.4 en production. Les caches serveur, SMW,
`__NOCACHE__`, les réglages PHP et les durées de conservation restent inchangés.

## Premier clic et chargement différé

Le module `ext.wikidebates.view.ns0` continue à s'initialiser comme auparavant ;
les interfaces `ext.wikidebates.reading.ui.vector` et `.minerva` ne sont pas
préchargées par ce correctif. Un clic sur un titre d'argument ou une carte
récupère la promesse ResourceLoader avec `mw.loader.using()`, qui réutilise
un éventuel téléchargement déjà commencé par le chargeur différé.

La dernière intention de navigation gagne si plusieurs titres sont cliqués
pendant la même attente. Une destination externe ou sans titre local n'est pas
interceptée. Une cible détachée ou un document ayant changé de page n'est pas
rouvert après coup. Le titre en attente reçoit temporairement `aria-busy`.
Une erreur de chargement, ou un chargement n'exposant pas le point d'entrée
attendu, mène au lien normal de l'argument. Le chargement peut être retenté
au clic suivant ; aucune requête réseau supplémentaire ne démarre avant un clic.

## URL de pages locales

`hrefToPageTitle()` impose désormais l'origine courante et le protocole HTTP(S)
avant d'interpréter un `?title=` ou une URL `/wiki/...`. Il préserve le
traitement des noms de pages avec `%`, accents, espaces, `_` et signes `+`,
mais ne considère pas les URL d'autres hôtes comme des pages du wiki.

## Sections repliables

L'initialiseur traite également la racine transmise par `wikipage.content`,
sans lier deux fois les gestionnaires. Une section privée d'un bouton ou de
son contenu peut être initialisée lorsque ces éléments arrivent plus tard.
Les descendants repliés utilisent `inert` en plus de `aria-hidden` et le focus
revient au bouton si la fermeture a lieu depuis le contenu. Un bouton natif
conserve son activation clavier native ; les éléments avec `role=button`
conservent Entrée et Espace. Les clics sur des contrôles réellement imbriqués
ne replient pas le panneau.

## Priorité des analyses de wikicode

Les demandes explicites `more` et `latest` passent devant les `hover`
uniquement lorsqu'elles attendent dans la file. La limite existante de deux
requêtes et l'ordre FIFO à priorité égale sont conservés ; aucune requête
active n'est annulée. Le cache navigateur des extraits de lecture n'est pas
modifié. Cette règle évite un temps d'attente évitable en présence de files de
survol, sans démontrer un gain global de temps de chargement.

## Validation

- Tests Node/jsdom : premier et deuxième clics, chargement déjà disponible,
  cartes, lien externe, erreur du module et absence du point d'entrée ; titres
  `%` et origines ; injection dynamique de sections et inert ; priorité FIFO.
- Tests Chromium : premier clic réel et ordre clavier des liens repliés.
- Régressions générales de lecture, préchargement, formulaires et traductions.
- Pas de modification PHP, SQL, Composer, npm de production ni migration.

Après fusion, sur un dépôt local propre de l'extension :

```sh
cd /home/users/webmaster/www/farm.wikidebates.org/w/extensions/Wikidebates
git switch main
git pull --ff-only origin main
```

Dans le dépôt parent, enregistrer ensuite la nouvelle révision du sous-module
seulement après avoir vérifié le wiki (Vector et Minerva).
