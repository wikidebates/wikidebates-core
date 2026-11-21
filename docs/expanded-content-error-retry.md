# Retenter les chargements de contenu et de contributions recentes

Cible : MediaWiki 1.43, avec PHP 8.4 en production.

Les deux boutons qui demandent du wikicode additionnel (
`more-content-button` et `latest-changes-button.mw-ui-button`)
utilisent maintenant une meme procedure de chargement asynchrone :

- La commande reste visible pendant la requete. Elle porte `aria-busy`
	et `aria-disabled`, et les nouvelles activations du meme bouton sont ignorees.
- Un message de progression est affiche dans une zone `role=status`.
- Apres succes, le contenu parse remplace la zone cible comme auparavant,
	le bouton est masque et le message de progression est retire.
- Apres erreur reseau, reponse invalide ou insertion HTML impossible, le
	bouton reste visible, redevient utilisable, et une explication localisee
	apparait dans une zone `role=alert`. Un clic ulterieur tente a nouveau.
- Chaque bouton et chaque zone de contenu disposent de leur etat propre.
	Dans les contributions recentes, le titre de section n'est pas un bouton.
- Une reponse tardive ne reinjecte rien dans une zone qui a quitte le DOM.

## Pourquoi le reessai a besoin d'un traitement particulier

Le moteur commun `wkParseWikitext()` conserve les erreurs pendant 15 secondes
pour eviter de repeter inutilement des requetes qui echouent. Un nouveau clic
utilisateur peut maintenant passer `{ retry: true }` en troisieme argument :
seule la memoisation de l'erreur associee a cette requete est ignoree.
La protection des demandes automatiques et des infobulles reste active.

Les regles de priorite, la limite de deux requetes simultanees, la
separation par langue, le cache de succes des autres requetes et l'absence
de cache de succes navigateur pour les contributions recentes restent
inchanges. Ce correctif ne modifie ni les caches serveur ni les requetes SQL.

Les messages d'etat sont traduits en francais et en anglais, declares dans
les modules ResourceLoader correspondants.

## Verification

- Tests Node : clics rapproches, erreurs et seconde tentative avant 15 secondes,
	deux boutons independants, bon debat dans la requete, traductions,
	contenu detache du DOM et erreur d'insertion.
- Tests Chromium : vrais clics et retentatives, Vector et Minerva.
- Suite existante JavaScript, chargement des traductions et MediaWiki 1.43 / PHP 8.4
	avec GitHub Actions avant toute fusion.

## Installation

Aucune migration SQL, installation Composer/npm ni modification PHP n'est
necessaire. Apres fusion, la mise a jour habituelle suffit :

```sh
cd /home/users/webmaster/www/farm.wikidebates.org/w/extensions/Wikidebates
git switch main
git pull --ff-only origin main
```

Verifier le chargement de contenu et les contributions recentes, puis enregistrer
la nouvelle revision du sous-module dans le depot parent.
