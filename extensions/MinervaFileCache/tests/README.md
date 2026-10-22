# Tests MinervaFileCache

Validation du 22 octobre 2026 : **26 assertions unitaires**, **61 assertions
d'intégration sans Wikidebates**, puis **61 avec Wikidebates**, toutes réussies.
Les résultats sont conservés dans [le JSON autonome](results-standalone.json) et
[le JSON avec Wikidebates](results-with-wikidebates.json).

Le banc utilise MediaWiki 1.43.11, PHP 8.3.30, le vendor REL1_43, MobileFrontend
2.4.1, MinervaNeue et Vector. Les révisions épinglées et l'installation des
sources se trouvent dans `tests/minervaFileCache/prepare.py` à la racine du dépôt
Wikidebates. Les tests ne lisent aucun `LocalSettings.php` existant et ne se
connectent à aucun wiki de production.

Depuis un clone complet du dépôt Wikidebates :

```sh
python3 tests/minervaFileCache/prepare.py /tmp/minerva-extension-lab
mkdir -m 0700 /tmp/minerva-extension-lab/state/mobile-cache
```

Reprendre la fonction Docker `minerva_study_php` du
[laboratoire initial](../../../tests/minervaFileCache/README.md), en utilisant
`/tmp/minerva-extension-lab` comme racine. Puis lancer :

```sh
minerva_study_php /src/extensions/MinervaFileCache/tests/unit.php
minerva_study_php /src/extensions/MinervaFileCache/tests/manage.php initialize
minerva_study_php /src/extensions/MinervaFileCache/tests/integration.php
```

Le script crée un serveur uniquement sur le loopback du conteneur sans réseau.
Le résultat JSON est enregistré dans `state/extension-tests.json`. Utiliser une
base neuve pour chaque série : le scénario modifie, renomme et supprime ses pages.
Les identifiants et mots de passe du banc sont fictifs.

L'intégration teste d'abord l'extension sans Wikidebates. Pour vérifier également
la cohabitation, refaire la série dans un laboratoire neuf en ajoutant
`-e WD_MINERVA_WITH_WIKIDEBATES=1` à `docker run`.

La configuration du banc active le gestionnaire de compression MediaWiki
(`output_buffering=0`, `zlib.output_compression=0`, compression MediaWiki active).
Il contrôle à la fois les restitutions `identity` et gzip, sans réglage spécial
de compression nécessaire à l'extension.

Les tests couvrent les caches desktop/mobile, l'intégrité du HTML et des
en-têtes, les namespaces, les variantes, les vetos des extensions, les sessions
réellement connectées, l'autorisation de lecture avant un hit, l'expiration,
l'époque de déploiement et les invalidations après édition, purge, modification
de modèle, suppression et renommage. Les tests du stockage contrôlent aussi un
remplissage concurrent et l'interdiction de publier après invalidation.

Ces contrôles ne reproduisent pas les modèles de production, SMW, une grappe de
serveurs PHP, un système de fichiers réseau ou une charge concurrente réelle.
