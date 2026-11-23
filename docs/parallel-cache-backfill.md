# Remplissage HTML parallele exceptionnel

**Commande opt-in, sans effet sur les crons ordinaires.** Ne pas utiliser avant
validation de la PR et des tests. Ne pas supprimer les caches, les verrous, les
curseurs ou les modifications locales pour repartir de zero.

## Architecture et securite

- Un seul superviseur par wiki detient le verrou exclusif habituel de
  CachePreparationQueue. Le balayage quotidien refuse un journal parallele
  inacheve.
- Les processus travailleurs detiennent chacun un verrou de plage independant,
  balayent les page_id en ordre croissant et publient un checkpoint atomique
  apres la verification de leurs phases Vector, Minerva et Lecture.
- Le curseur global reste en retrait de toute plage inachevee. Il n'est remis
  a zero que lorsque toutes les plages du cycle ont ete epuisees.
- Une reprise conserve les plages et refuse les anciens jetons de processus.
  Elle refuse egalement une signature de configuration des caches differente.
- Les mutations de pages continuent d'ecrire dans la file, avec generation
  et confirmation semantique. Les pages non pretes ne sont pas acquittees.
- La protection anti-doublon de rendu par page ne prend PAS le verrou de courte
  duree reserve aux hooks d'edition.
- Les fichiers HTML sont toujours prepares par les commandes deja securisees.
  Les controles d'identite publique et de revision sont inchanges.
- Les erreurs HTTP et les retours inconnus ne sont jamais des succes.

## Prerequis

MediaWiki 1.43 / PHP 8.4, extension cURL, proc_open, Linux /proc/meminfo,
stockage prive de preparation par wiki et commandes existantes Vector, Minerva
et Lecture operationnelles.

Verifier au prealable les modifications Git locales, la configuration du
repertoire prive et la reponse HTTP du site public depuis le serveur. Ne
jamais desactiver Cloudflare ou CrawlerBurstProtection pour accelerer les GET.

## Lancement explicite

Depuis la racine MediaWiki :

    php maintenance/run.php Wikidebates:prepareParallelCaches \
    	--wiki=fr \
    	--execute \
    	--workers=4 \
    	--minerva-devices=android,iphone \
    	--minerva-delay-ms=250 \
    	--max-load=10 \
    	--reserve-mb=2048 \
    	--max-health-ms=2500 \
    	--max-db-ms=500 \
    	--max-seconds=28800 \
    	--health-title="Titre public existant"

Commencer par un seul wiki, puis etudier le second. Le superviseur lance
ses propres enfants : ne pas lancer plusieurs exemplaires concurrents de
backfillDailyCaches.sh.

Les permissions de debut de nouveaux rendus sont ajustees en fonction de la
charge sur une minute, de la memoire disponible, du code HTTP, de la latence
publique et de la latence d'une requete SQL indexee sur la base primaire. Une degradation importante donne zero permission sans abandonner les
phases deja en cours. La reprise est progressive.

Pour prendre egalement en compte la file d'attente PHP-FPM, fournir
l'option --fpm-status-url=http://127.0.0.1:PORT/STATUS?json. Le statut doit
etre expose uniquement en loopback et fournir la sortie JSON standard de
PHP-FPM. S'il a ete configure mais devient illisible, les nouveaux rendus
sont suspendus. Sans endpoint disponible, la charge FPM est estimee
indirectement par la latence publique, pas par un compteur PHP-FPM.

Une erreur HTTP 429 ou 500/502/503/504 de rendu peut donner lieu a deux
nouvelles tentatives maximum. Une pause partagee entre les travailleurs
empeche d'amplifier une surcharge. Chaque tentative reverifie la revision
et la visibilite anonyme. HTTP 403, NO_HEADER et les sorties inconnues
restent des echecs a inspecter ; ils ne font pas avancer les curseurs.

## Titres depassant la taille d'un fichier Vector natif

MediaWiki 1.43 encode le titre entier dans un composant du nom de fichier
HTMLFileCache, suivi de .html ou .html.gz. Sur Linux, le composant du nom
ne peut normalement pas depasser 255 octets. Un titre valide dans MediaWiki
peut franchir ce seuil apres encodage URL (notamment les accents).

L'outil Vector verifie la longueur du composant natif avant tout rendu ou
ecriture. Il rapporte SKIPPED_FILENAME_TOO_LONG, qui n'est pas une erreur
et n'invente pas de fichier Vector. Les phases Minerva et Lecture restent
soumises a leurs propres controles normaux : elles peuvent toujours utiliser
leurs caches s'ils sont compatibles avec le titre. Aucun chemin de cache
natif n'est renomme ou raccourci artificiellement.

Une veritable erreur de stockage (WRITE_FAILED) ou de rendu reste bloquante,
et une commande enfant Vector qui quitte avec un code 1 conserve desormais
un diagnostic interne connu, sans exposer ses messages d'erreur arbitraires.

## Sortie imprevisible d'un processus enfant

Le superviseur distingue desormais l'etat reelement termine d'une plage
(checkpoint `done` confirme) du code de sortie rapporte par PHP. Sur PHP 8.4,
`proc_get_status()` peut rendre `exitcode=-1` (signal ou code non disponible).
`proc_close()` est egalement examine et le signal est rapporte explicitement.

Si la plage n'est PAS terminee et que l'etat global est sain, le superviseur
relance au plus deux fois ce processus avec la meme session et la meme plage
de pages. Il ne valide ni fichiers ni pages en cas de sortie inconnue.
La file des editions peut elle aussi etre relancee si une autre plage reste
a traiter. Si la plage est deja terminee (ou si l'arret global est demande),
un code de sortie indisponible ne transforme plus un travail reussi en echec.

Chaque terminaison produit un evenement `worker-exit` comprenant `worker`,
`exit_code`, `signal`, `action`, et `restarts` sans exposer de titre, URL ou
sortie du sous-processus. Apres epuisement des reprises, le checkpoint passe
en `failed` et retient la cause et le nom de la plage. Les controles HTTP,
base de donnees, ressources et ACL restent inchanges.

## Erreurs locales : poursuivre sans masquer les lacunes

Une erreur sur une page ne doit pas immobiliser tout un wiki. Si la
preparation echoue apres les tentatives bornees, le balayage :

- conserve la cause dans un dossier prive /parallel/page-errors (ID, revision,
  code interne uniquement, jamais de titre, URL, sortie HTML ni message PHP) ;
- ajoute la page a la file persistante des reprises, sans effacer ou remplacer
  une generation de modification plus recente ;
- n'avance son checkpoint de plage qu'APRES preuve de cette mise en file ;
- journalise un evenement scan-deferred avec le motif et comptabilise une
  page deferred, distincte des caches crees, des pages terminees et des
  exclusions de politique de cache ;
- poursuit les pages suivantes. Au bout de 12 erreurs consecutives dans
  une meme plage, l'erreur est consideree comme potentiellement generale et
  provoque un arret de securite pour investigation.

Une erreur du travailleur de la file de modifications ne supprime aucun
element de cette file et ne stoppe plus tout le superviseur. Les reprises
sont espacees et les generations plus recentes restent prioritaires.

Les arrets globaux existants (indisponibilite HTTP de controle, ressources,
configurations incoherentes, acces et protections) restent actifs. Le
remplissage peut se terminer avec des pages deferred : ces pages ne sont
PAS declarees cachees. Le cron quotidien reprend la file lorsque FR et EN
sont termines. Il n'interrompt plus son lot complet pour une erreur isolee
sur une page deja en attente.

Pour lister les erreurs et leur etat, sans declencher de rendu :

    php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=fr --report-errors
    php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=en --report-errors

Pour envoyer un recapitulatif deduplique au maximum une fois par heure,
au destinataire fixe erreurcache@wikidebats.org :

    php maintenance/run.php Wikidebates:prepareParallelCaches \
    	--wiki=fr --report-errors --mail-errors

L'envoi utilise le Mailer MediaWiki configure sur le serveur. Il n'est
jamais effectue par les travailleurs de rendu. Une erreur d'envoi ne
supprime pas les evenements ni ne bloque la preparation. La premiere alerte
et les nouveaux problemes sont resumes avec le nombre de pages affectees
et jusqu'a trente identifiants, revisons et motifs ; les rapports inchanges
ne sont pas renvoyes. Aucun email n'est envoye sans planification explicite
de cette commande.

## Pages explicitement non cacheables

Une page dont la sortie anonyme refuse la mise en cache, notamment par le mot
magique __NOCACHE__, ne doit pas bloquer le balayage. Lorsque Vector a
explicitement ignore le cache et que toutes les variantes Minerva demandee
renvoient BYPASS-parser-expiry, la phase Lecture est ignoree, et la page est
journalisee comme skipped-cache-policy au lieu de complete. La source wikitexte
n'est pas lue pour faire une recherche naive du mot magique : le refus repose
sur la politique effective du parseur.

Si page_touched change pendant ces requetes, la page est revalidee contre
PRIMARY (identite publique et revision inchangée) avant tout saut. Aucun
fichier n'est declare cree sur la base d'un simple BYPASS. Cette meme
regle est appliquee au coordinateur quotidien pour qu'il n'arrete pas le
curseur apres une exclusion NOCACHE verifiee. Les erreurs HTTP,
les refus ACL et les pages avec des fichiers Vector ou Minerva effectivement
crees ou reutilises n'entrent pas dans cette exception. L'etat du balayage et
la file de modifications ne sont jamais remis a zero.

Quand une page change d'identite pendant la creation (revision ou page_touched),
le balayage reinterroge la base PRIMARY et refait toutes les phases de la
page avec la nouvelle revision. Au plus deux nouvelles tentatives courtes
sont autorisees par page, sans relacher les controles ACL/RevDel. Les refus
explicites de cache (par exemple SKIPPED_NONCONTENT et BYPASS-parser-expiry)
restent des exclusions normales et ne sont pas forces. Si l'identite demeure
instable, le journal s'arrete sans marquer la page terminee ; consulter
le premier evenement scan-error dans le journal avant une nouvelle reprise.


## Journaux et commandes de controle

    umask 077
    mkdir -p -m 700 /home/users/webmaster/files/cache-preparation/wikidebates/logs

    nohup php maintenance/run.php Wikidebates:prepareParallelCaches \
    	--wiki=fr --execute --workers=4 \
    	--minerva-devices=android,iphone --minerva-delay-ms=250 \
    	--max-load=10 --reserve-mb=2048 --max-health-ms=2500 \
    	--max-db-ms=500 \
    	--max-seconds=28800 \
    	--health-title="Titre public existant" \
    	> "/home/users/webmaster/files/cache-preparation/wikidebates/logs/parallel-fr-$(date -u +%Y%m%dT%H%M%SZ).jsonl" 2>&1 < /dev/null &
    echo "Superviseur PID : $!"

    php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=fr --status
    php maintenance/run.php Wikidebates:prepareParallelCaches --wiki=fr --stop

L'arret propre est traite a la frontiere des pages. Pour reprendre, relancer
avec les memes options --workers et la meme configuration des caches. Le
checkpoint persiste les phases terminees, les erreurs, les creations et
reutilisations Vector/Minerva/Lecture et les debits observes. Les identifiants
de page etant discontinus, un ETA fonde seulement sur leur maximum serait
trompeur : ne pas inventer de nombre de pages restantes.

Le mode --queue-only du coordinateur ordinaire peut servir les edits directs
pendant une pause, lorsque le verrou du superviseur est libere. Un cycle
entierement termine autorise un nouveau cycle explicite avec --new-cycle.

## Limites de validation

Les tests d'integration GitHub utilisent un environnement MediaWiki 1.43.11
jetable sous PHP 8.4, SQLite, plusieurs processus PHP et de vrais caches
Vector, Minerva et Lecture. Ils ne remplacent PAS une mesure A/B du debit,
du temps de reponse et de la saturation PHP-FPM/MariaDB sur le serveur 12
coeurs. La borne optimale de travailleurs doit etre etablie lors de ces
mesures sans perturber les sites publics.
