# Tests MinervaFileCache

Ce dossier contient un laboratoire autonome pour MediaWiki **1.43.11**,
MobileFrontend **2.4.1**, MinervaNeue et Vector, avec une base SQLite
jetable. Aucune autre extension métier n'est requise.

## Tests unitaires

Depuis la racine de l'installation MediaWiki :

```sh
php extensions/MinervaFileCache/tests/unit.php
php extensions/MinervaFileCache/tests/prewarm-unit.php
php extensions/MinervaFileCache/tests/prewarm-command-unit.php
php extensions/MinervaFileCache/tests/prewarm-category-unit.php
```

Les tests de préchauffage sont isolés du réseau et de la base de données :
validation des options, User-Agents, checkpoints, pagination, reprise après
une réponse HTTP 429, simulation, filtres d'espaces de noms, sélection des
catégories et sous-catégories, cycles, dédoublonnage et pagination des membres.

## Laboratoire d'intégration indépendant

Prérequis : Python 3, Git, Docker et accès aux dépôts publics Wikimedia
pour télécharger les révisions épinglées. Le script refuse un répertoire
existant et ne lit aucun `LocalSettings.php` de production.

Depuis un dossier contenant une copie de l'extension :

```sh
MINERVA_EXTENSION_DIR="$(cd extensions/MinervaFileCache && pwd)"
MINERVA_LAB_ROOT=/tmp/minerva-file-cache-lab-new

python3 "$MINERVA_EXTENSION_DIR/tests/prepare.py" "$MINERVA_LAB_ROOT"
```

L'image employée dans le laboratoire initial était
`docker-registry.wikimedia.org/dev/bookworm-php83-fpm:1.0.0-s1`
(PHP 8.3.30). Le conteneur est isolé du réseau extérieur, ne publie aucun
port et monte l'extension en lecture seule. Le serveur interne écoute
uniquement sur 127.0.0.1.

```sh
MINERVA_LAB_IMAGE=docker-registry.wikimedia.org/dev/bookworm-php83-fpm:1.0.0-s1

minerva_lab_php() {
	docker run --rm --network none \
		--user "$(id -u):$(id -g)" \
		--mount "type=bind,src=$MINERVA_EXTENSION_DIR,dst=/extension,readonly" \
		--mount "type=bind,src=$MINERVA_LAB_ROOT,dst=/lab" \
		-e MINERVA_TEST_ROOT=/lab \
		--entrypoint php "$MINERVA_LAB_IMAGE" -d xdebug.mode=off "$@"
}

minerva_lab_php /extension/tests/manage.php initialize
minerva_lab_php /extension/tests/integration.php
```

Le banc crée un wiki temporaire et un compte de test fictif, puis modifie,
renomme et supprime exclusivement ses pages de test. Il refuse la
réinitialisation de la même base. Le résultat de chaque exécution est
enregistré dans `$MINERVA_LAB_ROOT/state/extension-tests.json`.
Pour refaire les tests, créer **un nouveau laboratoire** ; ne jamais
utiliser un wiki de production.

L'intégration contrôle les caches desktop et mobile, l'identité du HTML,
les en-têtes, les pages et espaces de noms, les veto des autres extensions,
les sessions authentifiées, les réponses compressées, les permissions,
le TTL, l'époque de déploiement et les invalidations après édition,
purge, modification d'un modèle, suppression et renommage.

Ces scénarios ne simulent pas la charge réelle, un stockage partagé sur
plusieurs serveurs, les modèles particuliers d'un site ou son proxy HTTP.

## Essai du préchauffage sur un wiki réel

Les tests unitaires ne prouvent pas la connectivité HTTP de production :
un reverse proxy peut refuser les requêtes internes. Sur un environnement
de préproduction, lancer d'abord une simulation, puis un petit lot :

```sh
php maintenance/run.php MinervaFileCache:prewarm --dry-run --limit=20
php maintenance/run.php MinervaFileCache:prewarm --limit=20 --resume
```

Sur une ferme, ajouter `--wiki=IDENTIFIANT` selon sa configuration.
Vérifier les états `MISS`/`HIT` et les fichiers `.cache` créés avant
un préchauffage de masse.
