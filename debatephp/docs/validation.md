> Document amont conservé pour le portage. Pour le chargement intégré et les vérifications actuelles, voir [le README Wikidebates](../../README.md) et [le bilan de migration](../../docs/debatephp-upgrade.md). Les anciennes instructions `wfLoadExtension( 'DebatePHP' )` concernent le paquet autonome.

> Version actuelle : voir [simplification-0.8.0.md](simplification-0.8.0.md). Les comparaisons Lua ci-dessous exigent un oracle de test, pas une dépendance du rendu PHP.

# Validation de DebatePHP sur Wikidébats

La validation locale distingue les sorties du parseur, les entrées envoyées à SMW et les écritures réellement effectuées lors d’un enregistrement. La première étape est réalisée ; la dernière demande une copie complète de la base et de la configuration du site.

## Comparaison sur une copie complète du site

Utiliser une copie disposant de Semantic MediaWiki 6.0.1, SemanticScribunto 2.3.3, WikiSEO, ShortDescription, HTML Tags et des personnalisations de Wikidébats. Charger DebatePHP, puis garder le modèle Débat existant. Le script de comparaison remplace son appel en mémoire pour chaque variante ; il ne modifie pas le modèle enregistré.

Ne pas charger `SemanticDouble.php`, `CaptureLibrary.php` ou leur configuration d’exemple dans cet environnement. Le mode `--full-stack` refuse leur présence pour éviter de confondre des valeurs enregistrées par un test avec des données SMW réelles.

Exporter les versions actuelles des pages témoins, depuis le dossier de l’extension :

```sh
php tools/exportCorpus.php \
  --wiki-root=/srv/mediawiki \
  --titles=tests/fixtures/titles.txt \
  --output=/tmp/debate-corpus.json
```

Comparer les trois variantes avec les vraies extensions :

```sh
php tests/integration/compare.php \
  --wiki-root=/srv/mediawiki \
  --corpus=/tmp/debate-corpus.json \
  --output=/tmp/debate-comparison.json \
  --full-stack --repeats=25

python3 tests/integration/check_results.py \
  /tmp/debate-comparison.json /tmp/debate-check.json
```

Le contrôle doit se terminer avec `all_passed: true`. Il compare le DOM et le JSON-LD, les catégories, les propriétés SEO, les liens, les modèles et révisions dépendants, les modules de ressources, les drapeaux et l’expiration de cache. Avec `--full-stack`, il compare aussi les propriétés sémantiques typées du `ParserOutput`, leurs séquences, leurs compteurs, leurs erreurs et les sous-objets.

Ce mode ne fournit pas de trace instrumentée des expansions Lua : les traces fournies dans le paquet ont été obtenues séparément dans l’environnement isolé. Il mesure le parsing avec les vrais services, mais n’enregistre pas les pages.

Le script suppose la forme actuelle de l’appel `#invoke:Debate|render` ou `#debate:render`. Une variante locale de ce modèle doit être examinée avant le test. Les gardes sur les sources arrêtent le portage si la logique de `Module:Debate` ou de `WD/I18N` a changé depuis les références incluses.

## Enregistrements et données stockées

Pour comparer les écritures, partir de deux copies identiques de la base et de la configuration. Garder Lua sur l’une et activer PHP sur l’autre. Enregistrer les mêmes changements sur les cinq pages, puis exécuter les mêmes tâches différées et mises à jour SMW sur les deux copies.

Vérifier les valeurs typées retournées par SMW, les sous-objets, les propriétés de page, les catégories et les dépendances de transclusion après l’exécution des tâches. Les comparaisons de `ParserOutput` précédentes ne remplacent pas ce contrôle du magasin persistant.

Mesurer séparément le temps HTTP total, le temps de parsing, les requêtes et écritures SQL, les mises à jour SMW et les tâches différées. Répéter les opérations et comparer les médianes ainsi que les valeurs hautes. Les révisions et identifiants de départ doivent correspondre entre les copies.

## Invalidation du cache

Sur la copie de test, vérifier successivement qu’une modification d’un modèle d’argument, de `Sous-partie` ou d’un modèle de référence invalide les pages dépendantes. Modifier aussi une valeur de `WD/I18N/Data` : la garde doit signaler que la table PHP doit être régénérée. Compiler cette nouvelle source, invalider les sorties concernées et confirmer sa prise en compte ; restaurer ensuite la source et sa table PHP d’origine.

Contrôler les aperçus des pages nouvellement créées, puis leur rendu après attribution d’un identifiant : le portage conserve `VARY_PAGE_ID`. Vérifier également les sorties après renommage et les paramètres de langue.

Tester des consultations avec cache de page rempli et vide. Sur un résultat déjà présent dans le cache de page, le moteur de rendu n’est normalement pas appelé ; le gain de parsing ne se transpose donc pas directement au temps de ces consultations.

Depuis 0.7.0, modifier les anciens modules ne bloque plus le rendu PHP et ne modifie pas sa logique. Une modification du code PHP demande la procédure habituelle de déploiement, de rechargement OPcache et d’invalidation du cache de page du site.

## Reproduction du test isolé fourni

Cette procédure utilise un wiki vide et jetable. Elle ne valide pas le magasin SMW.

1. Installer MediaWiki 1.43.9 avec PHP 8.3 et SQLite, ainsi que Scribunto REL1_43, ParserFunctions, Cite, HTML Tags REL1_43, ShortDescription 4.0.1 et le tag WikiSEO `v2.7.0`.
2. Utiliser les chemins `/w` et `/wiki/$1`, le serveur `https://fr.wikidebates.org` et la langue `fr` pour reproduire les URL des tests. Les formulaires Page Forms et les pages d’arguments liés ne sont pas importés ; leur absence est identique pour les trois variantes.
3. Charger DebatePHP et la configuration de test `tests/integration/LocalSettings.example.php`. Autoriser pour HTML Tags les attributs `href`, `data-href`, `class`, `title` sur `a`, et `type`, `class` sur `script`, comme dans le montage de test.
4. Importer les sources publiques et les auxiliaires des cas synthétiques :

   ```sh
   php /srv/mediawiki/maintenance/run.php importDump --no-updates /chemin/DebatePHP/tests/fixtures/wiki-snapshot.xml
   php /srv/mediawiki/maintenance/run.php importDump --no-updates /chemin/DebatePHP/tests/fixtures/helpers.xml
   ```

5. Exécuter la comparaison sans `--full-stack`, une fois avec LuaStandalone, puis avec LuaSandbox :

   ```sh
   php tests/integration/compare.php \
     --wiki-root=/srv/mediawiki \
     --corpus=tests/fixtures/pages.json \
     --output=/tmp/pages.json --repeats=25

   python3 tests/integration/check_results.py /tmp/pages.json

   php tests/integration/compare.php \
     --wiki-root=/srv/mediawiki \
     --corpus=tests/fixtures/synthetic.json \
     --output=/tmp/synthetic.json

   python3 tests/integration/check_results.py /tmp/synthetic.json
   ```

Le réglage du moteur se fait par `$wgScribuntoDefaultEngine`. LuaStandalone nécessite un exécutable Lua 5.1 ; LuaSandbox nécessite l’extension PHP correspondante. Les tests fournis utilisent LuaStandalone 5.1.5 et LuaSandbox 4.1.1.

Dans ce montage, le Lua de référence est instrumenté seulement pendant la vérification fonctionnelle. Les séquences d’expansion, leurs entrées, sorties, durées de vie et états de volatilité sont comparés. Les répétitions chronométrées utilisent les fonctions de production sans cette instrumentation ; SMW reste remplacé par l’enregistreur de paramètres.

Répéter aussi les contrôles fonctionnels en ajoutant `--no-trace` à la commande de comparaison : cette option vérifie les fonctions de production sans remplacer le callback PHP par celui qui collecte les traces. Les sorties et métadonnées sont toujours comparées ; les traces d’expansion sont alors vides. Le script fixe le même horodatage de parseur pour toutes les variantes, afin qu’un changement de seconde entre deux exécutions de `CURRENTTIMESTAMP` ne crée pas de faux écart. Il ne remplace pas ces mots magiques et ne neutralise pas leurs effets sur le cache.

## Traductions et génération

Le test indépendant de traductions emploie le véritable Lua 5.1 au moyen du paquet Python `lupa` :

```sh
python3 tests/differential_i18n.py /chemin/vers/php
```

Il compare 1 255 requêtes de traduction avec la version PHP de référence, puis avec sa mémorisation activée. Les données précompilées se régénèrent avec `tools/compileI18n.php`. La génération de `ReferenceRenderer.php` nécessite `luaparser` 4.2.0 et n’est jamais exécutée pendant le rendu des pages.

Depuis 0.2.0, ce test passe par le véritable chargement de la table PHP intégrée. Exécuter également :

```sh
php tests/i18n_bundle.php
```

Ce contrôle vérifie le chargement natif FR/EN, l’absence de dépendance envers les sources wiki et l’absence de chargement du lecteur de tables Lua pendant l’utilisation de `I18n::bundled()`.

## Maintenance et observations depuis 0.3.0

`tools/maintainI18n.php --wiki-root=/srv/mediawiki` utilise les callbacks MediaWiki pour lire les sources de référence du wiki de test. Il compare les requêtes FR/EN et les replis avec Scribunto, ainsi que sept cas de sélection de langue, dans les deux stratégies PHP. Avec les sources fournies, cela donne 2 510 comparaisons de traductions et 14 comparaisons de langue. Le module utilisé pour effectuer ces appels est remplacé uniquement dans le callback du parseur de contrôle ; aucune page de module n’est enregistrée.

Sans option, la commande vérifie ensuite que la table installée est synchronisée. Avec `--rebuild`, elle régénère la table PHP et la source de test après réussite des comparaisons. `--source=... --rebuild --output=...` prépare un candidat local sans toucher aux fichiers installés. Le rechargement OPcache et l’invalidation du cache de page suivent ensuite la procédure habituelle de déploiement ; cette commande ne les déclenche pas.

Sur le wiki jetable uniquement, vérifier également les échecs simulés et la maintenance :

```sh
php tests/telemetry.php
php tests/integration/observability.php --wiki-root=/srv/mediawiki --log=/tmp/debate-observability-new.log
python3 tests/integration/maintenance.py --php=/chemin/php --wiki-root=/srv/mediawiki
```

Le premier test contrôle notamment qu’un journal défaillant ne propage pas d’exception. Le second exige le double SMW jetable et compare les sorties avec observations activées et désactivées, en provoquant des exceptions SMW/SEO et une erreur retournée par SMW. Il vérifie les étapes mesurées et l’absence de valeurs dans les événements. Utiliser un nouveau chemin de journal à chaque exécution. Le troisième teste le contrôle de synchronisation, une modification valide de traduction, le rejet d’un format incompatible, la conservation du fichier précédent et des permissions, ainsi que l’absence de modification des fichiers installés.

Pour vérifier le rendu avec les mesures activées, ajouter `--no-trace --profile-log=/tmp/debate-profile-new.log` à `tests/integration/compare.php`, puis exécuter le contrôleur de résultats habituel. Les observations passent alors par les fonctions de production. Les mesures instrumentées servent à localiser les coûts ; les comparaisons de vitesse doivent se faire avec les observations désactivées.

## HTML natif et SHORTDESC depuis 0.4.0

Les traces distinguent maintenant les opérations natives des prétraitements conservés. Elles gardent la source équivalente de l’ancien appel à titre de contrôle. Pour les balises ordinaires, cette chaîne complète n’est reconstruite que lorsque l’instrumentation est activée ; leur clé de cache est calculée par fragments. La description conserve une courte chaîne d’identité pour son cache, qui n’est pas analysée sur le chemin littéral. Les cas syntaxiques complexes conservent une construction et une analyse complètes. Ces choix préservent les interactions avec les prétraitements de champs.

L’HTML protégé peut utiliser un marqueur `item` à la place de `htmltag`. Quand les marqueurs bruts diffèrent, le contrôleur compare leur contenu résolu comme du DOM/JSON, sans neutraliser les différences de contenu. Le DOM complet de la page, les catégories, les données envoyées à SMW, les propriétés, les dépendances et les durées de vie restent comparés. Le rapport compte séparément les prétraitements et les opérations natives.

Tester les chemins directs, leurs replis syntaxiques et le cache partagé :

```sh
php tests/integration/tags.php --wiki-root=/srv/mediawiki
```

Ce test exige HTML Tags comme oracle et réalise 52 comparaisons en modes HTML et prétraitement. Il couvre les attributs et leur casse, les valeurs entourées de guillemets, les booléens convertis en texte, les notes dans le corps et les attributs, le filtre d’attributs, les balises non autorisées, les balises/liens non fermés, les titres de section, les pipes de SHORTDESC et le cache entre les anciens appels et les nouvelles fonctions. Il doit passer avec les deux moteurs Scribunto.

### Vérifier l’indépendance de HTML Tags

Sur la copie de test, produire d’abord une comparaison avec l’extension HTML Tags chargée :

```sh
php tests/integration/compare.php --wiki-root=/srv/mediawiki \
  --corpus=/tmp/debate-corpus.json --output=/tmp/debate-with-htmltags.json
```

Désactiver ensuite le chargement de cette extension externe sur cette copie. DebatePHP fournit son propre hook compatible et conserve le filtrage configuré. Pour tester ses valeurs par défaut, retirer aussi la variable de configuration de l’ancienne extension, ou définir explicitement `DebatePHPHTMLAttributes` comme dans le guide. Exécuter :

```sh
php tests/integration/compare.php --wiki-root=/srv/mediawiki \
  --corpus=/tmp/debate-corpus.json --native-only \
  --oracle=/tmp/debate-with-htmltags.json --output=/tmp/debate-without-htmltags.json

python3 tests/integration/check_results.py /tmp/debate-without-htmltags.json \
  /tmp/debate-without-htmltags-check.json --oracle=/tmp/debate-with-htmltags.json
```

L’oracle fixe les mêmes horodatages et vérifie l’identité des sources du corpus. Le champ `html_tags_loaded` des résultats natifs doit être `false`. Le contrôleur réutilise le Lua exécuté avec l’extension d’origine, plutôt que de comparer uniquement deux chemins partageant le hook compatible.

### Comparer les coûts

Le test ciblé utilise 15 tours, avec un nouveau parseur par échantillon et 100 groupes de quatre opérations dont les contenus sont distincts pour éviter les réponses du cache de fragments :

```sh
php tests/integration/tag_benchmark.php --wiki-root=/srv/mediawiki --output=/tmp/debate-tag-times.json
```

Il compare les deux boutons, un petit script JSON-LD et une description littérale, avec et sans reconstruction des anciens appels. Il vérifie leurs sorties et métadonnées, mais ne mesure ni une page complète ni les écritures SMW.

Pour comparer la page complète avec l’ancienne construction PHP, préparer les classes de test à partir de l’archive 0.3.0, puis les ajouter aux répétitions mélangées :

```sh
python3 tests/integration/legacy.py /chemin/DebatePHP-0.3.0.zip /tmp/debate-legacy.php
php tests/integration/compare.php --wiki-root=/srv/mediawiki \
  --corpus=/tmp/debate-corpus.json --legacy-renderer=/tmp/debate-legacy.php \
  --repeats=25 --output=/tmp/debate-tag-page-times.json
python3 tests/integration/check_results.py /tmp/debate-tag-page-times.json
```

Les anciennes classes de rendu et leur callback sont chargés uniquement par ce test. Ils utilisent la même frontière MediaWiki que les variantes actuelles, pour comparer les chemins HTML/SHORTDESC sans ajouter un mode de production. L’instrumentation est désactivée pour les répétitions chronométrées.
