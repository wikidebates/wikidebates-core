# Organisation de la lecture Minerva

Le module ResourceLoader `ext.wikidebates.reading.ui.minerva` garde ses
dépendances et ses styles. Ses fichiers JavaScript sont assemblés dans le même
module, dans l'ordre déclaré dans `extension.json` : aucune nouvelle requête de
module ni étape de chargement différé n'est ajoutée.

| Fichier sous `modules/reading/` | Responsabilité |
| --- | --- |
| `minerva/shared.js` | Identifiants DOM, messages et emplacement du panneau |
| `minerva/header.js` | Titre complet, bandeau fixe, fondu, boutons et actions |
| `minerva/sheet.js` | Construction, ouverture et fermeture du sommaire |
| `minerva/prefetch.js` | Recherche des arguments voisins et préchargement partagé |
| `minerva/previews.js` | Aperçus des voisins et du parent, transformations visuelles |
| `minerva/gestures.js` | Gestes, verrouillage du défilement et navigation |
| `ui.minerva.js` | Adaptateur du moteur de lecture et cycle de vie du DOM |

Les composants communiquent par `Wikidebates.minervaReading`. Les fabriques de
navigation et d'aperçus reçoivent l'interface courante ; leur état reste associé
à cette interface. Les événements, seuils, durées, limites de cache, paramètres
API et mécanismes de nettoyage existants sont conservés.

Les fixtures JavaScript et Chromium chargent les fichiers dans l'ordre du
manifeste, au lieu de maintenir une seconde liste. La suite Chromium existante
couvre le titre, les actions, les aperçus, les gestes, le sommaire et la fermeture.

Ce découpage vise la maintenance. Il ne revendique aucune accélération du rendu.
