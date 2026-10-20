# Optimisations de lecture et des formulaires

Ces changements conservent le fonctionnement de Vector 2022 et MinervaNeue,
ainsi que l'intégration de DebatePHP 0.8.0 et l'organisation des traductions.

## Lecture d'un argument

Le clic et le préchargement mobile partagent désormais la même requête lorsqu'ils
visent la même page avec les mêmes paramètres. Une ouverture pendant le
préchargement ne provoque donc plus un deuxième téléchargement.
Un cache LRU commun conserve au maximum 50 réponses API, distinctes selon la
langue, l'habillage et les autres paramètres. Le cache existant des extraits
reste dans le moteur de lecture.

La page entière reste demandée à MediaWiki, puis l'extrait est découpé dans le
navigateur. Les paramètres de rendu et les limites de l'extrait sont inchangés.
La création d'une API renvoyant uniquement l'extrait est différée.

## Modifications récentes

Les comptes **Wikirobot** (r minuscule) et **ChatGPT** sont exclus directement dans
la requête SQL, avant la limite des résultats. Les autres comptes, y compris les
autres robots, ne sont pas exclus par cette règle. Les consultations des groupes
de chaque auteur et les lots supplémentaires pour éliminer les robots en PHP
sont supprimés.

Le cache WAN configuré dans MediaWiki peut conserver le résultat pendant
**60 secondes maximum**. Aucun cache de résultat supplémentaire dans le navigateur
ou le parser ne prolonge ce délai. Une liste déjà affichée n'est pas actualisée
automatiquement. Un backend de cache opérationnel est nécessaire pour réutiliser
le résultat entre requêtes ; sans backend, le calcul continue de fonctionner.

## Navigateur et formulaires

Les notifications initiales portant sur le même document sont regroupées ; les
mises à jour suivantes ne parcourent que les zones transmises. Les recherches de
modules déjà chargés sont évitées.

Les événements rapprochés des formulaires sont regroupés. Seuls les champs et
les lignes interlangues modifiés sont relus. La dernière mise à jour du résumé
est exécutée avant la soumission, même si elle arrive juste après la saisie.

## Vérifications

Voir [les tests de régression](../tests/README.md). Onze tests JavaScript couvrent
notamment le préchargement Minerva, le partage des requêtes et la soumission des
formulaires. Le test PHP vérifie les vrais accès SQL sur un wiki SQLite jetable,
avec des tables sémantiques minimales et une horloge simulée pour les 60 secondes.

Après intégration avec `main`, les suites PHP autonomes, les tests MediaWiki 1.43,
les contrôles de traduction PHP/JavaScript et le test HTTP local ont également été
exécutés. Ces résultats ne chiffrent pas les performances en production et ne
remplacent pas un contrôle sur le site complet.

## Mise à jour sur le serveur

Exécuter les commandes avec le compte habituel de déploiement. Adapter le chemin.
Le répertoire doit être le checkout Git de l'extension, sur la branche `main`,
sans modifications locales. Le premier SHA affiché permet de noter la version
précédente. Les commandes s'arrêtent si une condition échoue.

```sh
set -e
cd /chemin/vers/mediawiki/extensions/Wikidebates
test -z "$(git status --porcelain)"
test "$(git branch --show-current)" = main
git rev-parse HEAD
git fetch origin main
git merge --ff-only origin/main
php -l src/RecentDebateChanges.php
git log -1 --oneline
```

Ces optimisations ne nécessitent ni migration SQL ni installation npm en
production. Si OPcache ne revalide pas automatiquement les fichiers, recharger
le service PHP-FPM ou Apache utilisé par le site, avec la procédure habituelle.
La commande dépend du service et de sa version ; un `opcache_reset()` lancé en
PHP CLI ne vide pas le cache du processus web.

Vérifier ensuite une ouverture d'argument sur Vector 2022, une navigation entre
arguments sur MinervaNeue, l'enregistrement d'un formulaire et la liste des
modifications récentes (Wikirobot et ChatGPT doivent en être absents).
