# Entretien autonome des caches anonymes : après le remplissage FR et EN

Cette procédure concerne MediaWiki 1.43 / PHP 8.4 sur la ferme Wikidébats.
Elle n'est **pas** un nouveau remplissage initial. Ne jamais supprimer les
caches HTML, le dossier privé de préparation, les fichiers d'erreur ou les
checkpoints. Ne pas lancer d'autre superviseur pendant le backfill existant.

## Pendant le remplissage initial

Ne pas changer les quatre tâches cron existantes ni les deux scripts de
supervision installés dans le répertoire personnel. Le nouveau balayage
refuse lui-même de travailler si le checkpoint parallèle FR **ou** EN
n'annonce pas `state=complete`.

Contrôles en lecture seule, à exécuter depuis la racine de MediaWiki :

~~~sh
php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=fr --status
php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=en --status
php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=fr --report-errors
php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=en --report-errors
~~~

L'état `running` avec heartbeat récent, curseurs progressant et
`last_failure=null` ne nécessite aucune intervention. `checkpoint=null`
sur EN est normal **tant que FR n'est pas terminé**. Les créations de
fragments Lecture peuvent être rares si des fragments valides existaient
déjà : contrôler des URLs et des fichiers réels avant toute conclusion.

## Balayage historique par fenêtre de temps

Le script `maintenance/scanCacheWindow.sh` traite un wiki pendant une
fenêtre maximale de 45 minutes (2700 secondes), en répétant les lots
`Wikidebates:prepareDailyCaches --limit=100`, ce qui contourne **sans
changer** la borne de 100 pages imposée par la commande PHP.

- Aucun lot ne commence s'il reste moins de 900 secondes dans la fenêtre.
- Chaque lot conserve le curseur atomique de `prepareDailyCaches` et
  garde l'ordre Vector, Minerva Android/iPhone, Lecture.
- Le résultat JSON est validé (type, wiki, état, compteurs). Les sorties
  inconnues, erreurs ou `retry-required` ne sont jamais traitées comme un
  succès ; la boucle cesse.
- `system-load` met fin au travail jusqu'au prochain créneau, sans
  relance en boucle. Le plafond par défaut est de 10 de charge moyenne
  Linux sur une minute ; il peut être réduit si les mesures l'exigent.
- Un verrou privé `flock` empêche deux fenêtres du même wiki de se
  chevaucher. Le verrou du coordinateur PHP protège également les
  opérations concurrentes avec le cron horaire.
- Le script journalise des agrégats courts et des curseurs numériques,
  pas des réponses HTML, titres ou URL. Le préchauffage HTTP reste soumis
  à Cloudflare, CrawlerBurstProtection et aux ACL habituels.

Pour une page défaillante dans le balayage ordinaire, le nouveau
`DailyCacheDeferral` relit sa révision sur PRIMARY, enregistre d'abord
une reprise dans la file durable et une erreur privée, puis seulement
autorise le curseur à avancer. Une barrière sémantique non résolue ou une
révision absente ne sont pas converties en reprise directe artificielle.
Douze pages consécutives différées au même lot provoquent un arrêt de
précaution. Le lot du cron suivant pourra reprendre au même curseur.

La reprise des erreurs individuelles appartient à la **file des éditions**
et reste prioritaire. Les pages valides ne sont pas régénérées si les
commandes Vector/Minerva/Lecture constatent leurs fichiers valides.

## Supervision et courriels

`--report-errors` affiche maintenant les erreurs individuelles et un
éventuel incident de superviseur, même si le nombre d'erreurs de pages est
zéro. Les incidents détectés sont :

- état parallèle `failed`, avec code interne borné ;
- état `running` dont le heartbeat n'a pas été renouvelé depuis plus
  de 15 minutes (un arrêt gracieux récent n'est pas une erreur) ;
- pour EN : absence de checkpoint plus d'une heure après la fin
  observée du checkpoint FR, afin de détecter un enchaînement qui n'a
  jamais lancé la seconde langue.

`--report-errors --mail-errors` regroupe les incidents et les erreurs de
pages, déduplique par session/signature et limite les envois à un
maximum d'un nouveau résumé par heure et par wiki. Un échec de livraison
n'acquitte pas un incident. Les noms de pages, URL, traces et contenu HTML
ne sont pas inclus.

Pour tester réellement la **livraison**, après déploiement :

~~~sh
php maintenance/run.php Wikidebates:prepareParallelCaches \
	--wiki=fr --report-errors --mail-errors --test-mail
~~~

Le résultat attendu est `mail_status=test-sent`, puis la réception
effective sur **erreurcache@wikidebats.org** doit être confirmée.
Cette option explicite n'est jamais utilisée par les crons et ne
modifie ni le cache, ni le checkpoint, ni la signature des alertes.

## Mise en production des crons (seulement après validation)

**Attendre la fin réelle de FR et EN**, la réussite des workflows GitHub
sur la PR, la synchronisation du sous-module dans la ferme et le déploiement
contrôlé. Conserver une sauvegarde du crontab avant de l'éditer et
ne remplacer **que** les deux lignes de balayage historique, en
conservant les deux lignes de file horaire, sitemaps, moteur de recherche,
Milito, alertes crawlers et PHP-FPM.

Remplacer les deux crons de balayage par :

~~~cron
45 2 * * * /bin/sh /home/users/webmaster/www/farm.wikidebates.org/w/extensions/Wikidebates/maintenance/scanCacheWindow.sh fr >> /home/users/webmaster/wikidebates-cache-logs/scan-window-fr.log 2>&1
45 3 * * * /bin/sh /home/users/webmaster/www/farm.wikidebates.org/w/extensions/Wikidebates/maintenance/scanCacheWindow.sh en >> /home/users/webmaster/wikidebates-cache-logs/scan-window-en.log 2>&1
~~~

Ajouter deux commandes horaires de notification (sans `--test-mail`) :

~~~cron
10 * * * * cd /home/users/webmaster/www/farm.wikidebates.org/w && /usr/bin/php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=fr --report-errors --mail-errors >> /home/users/webmaster/wikidebates-cache-logs/errors-fr.log 2>&1
25 * * * * cd /home/users/webmaster/www/farm.wikidebates.org/w && /usr/bin/php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=en --report-errors --mail-errors >> /home/users/webmaster/wikidebates-cache-logs/errors-en.log 2>&1
~~~

Chaque exécution écrit son résultat JSON dans un journal privé.
Une panne du Mailer MediaWiki doit être recherchée dans ces journaux ;
la présence du code d'envoi ne prouve jamais la réception du courrier.
La rotation des journaux doit être vérifiée avec les autres journaux
d'exploitation, sans purge intempestive des états privés.

Après la première nuit, contrôler les lignes `CACHE_WINDOW_BATCH`,
`cursor`, `scan_deferred`, `stop_reason`, la file d'erreurs, la
charge machine et les vrais HIT sur des pages anonymes. Ajuster
`WD_CACHE_WINDOW_MAX_LOAD` et `WD_CACHE_WINDOW_SECONDS` en fonction
des mesures, sans supprimer les protections ni agrandir aveuglément le
nombre de travailleurs. La fenêtre de 45 minutes évite par défaut
le chevauchement FR/EN prévu par les horaires ci-dessus.

## Limites de cette procédure

Ce script ne remplace ni la validation publique des trois variantes ni
les tests d'invalidation et de confidentialité. Les tests GitHub sont
jetables, tandis que les performances, la réception mail et le coût réel
de balayage doivent être mesurés sur `dialectica`.

Le courrier de monitoring dépend du Mailer MediaWiki et d'un cron actif :
un incident qui empêcherait simultanément PHP et le Mailer de fonctionner
nécessiterait une supervision externe indépendante.
