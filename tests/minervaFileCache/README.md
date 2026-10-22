# Laboratoire du cache fichier Minerva

Ce banc démontre la faisabilité d'un cache HTML mobile distinct du cache desktop
sur MediaWiki 1.43.11, avec MobileFrontend, MinervaNeue et Wikidebates réellement
chargés. Aucun fichier de ce dossier n'est enregistré dans `extension.json`.
`Prototype.php` est un prototype de laboratoire, pas une configuration à installer
sur le site. Voir le [rapport et ses limites](../../docs/minerva-file-cache-study.md).

## Préparer une installation jetable

Prérequis : Python 3, Git, Docker et accès aux dépôts publics Wikimedia. Le script
suivant refuse un répertoire existant, télécharge les révisions épinglées des
sources et du vendor, puis crée les liens nécessaires. Il n'utilise aucune base
du site ni aucun `LocalSettings.php`. Garder les paramètres proxy et certificats
fournis par l'environnement pour les téléchargements.

Depuis la racine de ce dépôt :

```sh
python3 tests/minervaFileCache/prepare.py /tmp/wikidebates-minerva-lab-new
```

L'image PHP utilisée pour la mesure est
`docker-registry.wikimedia.org/dev/bookworm-php83-fpm:1.0.0-s1` (PHP 8.3.30).
Le serveur de test écoute uniquement sur le loopback d'un conteneur sans réseau
externe. Le dépôt est monté en lecture seule ; seuls les fichiers du laboratoire
sont modifiables. Aucun port n'est publié sur l'hôte.

```sh
minerva_study_repo="$PWD"
minerva_study_root=/tmp/wikidebates-minerva-lab-new
minerva_study_image=docker-registry.wikimedia.org/dev/bookworm-php83-fpm:1.0.0-s1

minerva_study_php() {
    env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS \
        -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH \
        docker --host=unix:///var/run/docker.sock run --rm --network none \
        --user "$(id -u):$(id -g)" \
        --mount "type=bind,src=$minerva_study_repo,dst=/src,readonly" \
        --mount "type=bind,src=$minerva_study_root,dst=/lab" \
        -e WD_MINERVA_STUDY_ROOT=/lab --entrypoint php \
        "$minerva_study_image" -d xdebug.mode=off "$@"
}

minerva_study_php /src/tests/minervaFileCache/manage.php initialize
minerva_study_php /src/tests/minervaFileCache/run.php
```

Le résultat est écrit dans
`/tmp/wikidebates-minerva-lab-new/state/minerva-file-cache-study.json`.
Le scénario modifie ses propres pages et modèle, puis refuse une deuxième
exécution sur cet état. Utiliser un nouveau laboratoire pour reproduire la série.
Le compte et son mot de passe visibles dans le banc sont des données fictives
créées dans sa base SQLite isolée.

## Mesures et contrôles

Le banc calibre le compteur de parsing avec un rendu froid, puis compare
31 lectures mobiles avec parser cache chaud à 31 lectures du fichier Minerva.
Il mesure la durée HTTP totale en loopback, pas uniquement le TTFB, et compte les
rendus d'habillage et parsings du contenu. Les parsings de messages d'interface
sont comptés séparément : ils ne signifient pas que le parser cache de page a raté.

Les mesures utilisent `Accept-Encoding: identity`. Les fichiers restent gzip
sur disque ; une vérification distincte contrôle la restitution HTTP gzip.
La compression de sortie MediaWiki et celle de PHP sont désactivées dans les
deux variantes pour éviter un double encodage lors de la restitution native.

Les assertions contrôlent également l'identité du HTML entre création et
restitution du fichier, la séparation avec Vector, l'exclusion des cookies,
sessions authentifiées, variantes de requêtes, POST et redirections, puis
l'invalidation après édition, purge et mise à jour d'un modèle avec traitement
des jobs de dépendances. Le banc ne mesure ni la charge concurrente, ni le réseau
mobile, ni les extensions sémantiques et les modèles de production.
