# Infobulles : cache local sensible à la langue et à la requête

Ce correctif concerne `modules/features/hover.js` (habillage Vector) sur
MediaWiki 1.43. Le moteur commun `wkParseWikitext()` isole déjà les réponses
par `wgUserLanguage`, mais l'infobulle gardait une copie HTML propre à chaque
élément dans une `WeakMap`, identifiée uniquement par le type et les paramètres
contextuels de l'infobulle. Si la langue changeait sans rechargement de page,
la copie locale pouvait réafficher du HTML dans l'ancienne langue.

La clé locale est maintenant une représentation non ambiguë de la langue
courante et de la requête exacte de wikicode. Une réponse asynchrone n'est
ni enregistrée dans cette copie ni affichée si la langue ou la requête de
l'élément a changé pendant son chargement. Le moteur commun continue de
conserver chaque réponse dans le compartiment de sa langue ; un retour à
une langue déjà utilisée peut donc réutiliser cette réponse.

La temporisation de 140 ms avant une infobulle, les annulations de requêtes
en attente après sortie de l'élément, les limites de concurrence et les
caches de fragments de lecture restent inchangés. Une requête déjà active
n'est pas interrompue : c'est uniquement son insertion dans une infobulle
devenue obsolète qui est empêchée.

## Vérifications

- Quatre tests Node/jsdom : changement de langue après mise en cache, réponse
	ancienne arrivant après changement de langue, requête modifiée pendant
	le chargement, et sortie de l'infobulle avant réponse.
- Un test Chromium sur Vector : passages français/anglais/français avec
	les vrais événements de survol, la réponse locale et le cache de parsing.
- Les tests de lecture, des requêtes asynchrones, des formulaires, des
	traductions et de MediaWiki sont conservés.

Aucun changement PHP, SQL, SMW, politique de cache serveur, `NOCACHE`,
configuration PHP 8.4 ou procédure de déploiement. Après fusion, le
`git pull --ff-only origin main` habituel dans `extensions/Wikidebates`
suffit à installer ce changement de JavaScript.
