# Validation des extraits préparés

Ce banc charge Wikidebates, MediaWiki 1.43.11, MobileFrontend, Vector, MinervaNeue
et Cite dans un wiki SQLite jetable. Il ne lit aucun `LocalSettings.php` existant
et n'accède à aucun site de production.

Préparer les sources épinglées avec le script de l'étude Minerva, puis ajouter
Cite et le répertoire d'extraits :

```sh
python3 tests/minervaFileCache/prepare.py /tmp/wikidebates-reading-lab
git clone --depth 1 https://github.com/wikimedia/mediawiki-extensions-Cite.git /tmp/wikidebates-reading-lab/Cite
git -C /tmp/wikidebates-reading-lab/Cite fetch --depth 1 origin 3f6a8e0395112bbdbe3b53732e259cad6375aaab
git -C /tmp/wikidebates-reading-lab/Cite checkout --detach FETCH_HEAD
mkdir -m 0700 /tmp/wikidebates-reading-lab/state/reading-cache
```

Reprendre la fonction Docker `minerva_study_php` du
[laboratoire Minerva](../minervaFileCache/README.md) avec cette nouvelle racine.
L'image PHP utilisée est `docker-registry.wikimedia.org/dev/bookworm-php83-fpm:1.0.0-s1`
(PHP 8.3.30). Puis lancer :

```sh
minerva_study_php /src/tests/readingFragments/unit.php
minerva_study_php /src/tests/readingFragments/manage.php initialize
minerva_study_php /src/tests/readingFragments/integration.php
```

L'intégration démarre un serveur HTTP sur le seul loopback du conteneur sans
réseau externe. Les opérations d'écriture, renommage et suppression portent
uniquement sur cette base jetable. Utiliser une base neuve pour chaque série.
Le résultat est écrit dans `state/reading-tests.json`.

La [référence validée](results.json) conserve les versions utilisées et les
32 contrôles d'intégration réussis. Les 23 assertions unitaires, les 26 tests
JavaScript et les 23 tests Chromium passent également, ainsi que la suite PHP
existante et la commande de nettoyage des extraits.

Les cas de l'extracteur de `extractor-cases.json` ont été calculés avec la vraie
fonction JavaScript `wkReadingExtractFragment`. Les tests PHP comparent leurs
résultats octet par octet. Le stockage est également testé pour l'expiration,
la séparation des variantes et l'impossibilité de publier après invalidation.

Les tests du navigateur et les fixtures JavaScript se lancent depuis `tests/` :

```sh
npm test
npm run test:browser
```

Les vrais modèles du site, SMW et une grappe de serveurs PHP ne sont pas reproduits
dans ce banc. L'identité du contenu sur les deux habillages, les références Cite,
les droits, les sessions connectées, le repli du stockage, les jobs et les
invalidations sont contrôlés dans MediaWiki réel.
