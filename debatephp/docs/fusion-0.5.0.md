> Document amont conservé pour le portage. Pour le chargement intégré et les vérifications actuelles, voir [le README Wikidebates](../../README.md) et [le bilan de migration](../../docs/debatephp-upgrade.md). Les anciennes instructions `wfLoadExtension( 'DebatePHP' )` concernent le paquet autonome.

# Fusion 0.5.0 : Debate et Argument natifs

Cette version part de l’archive « DebatePHP 4.0.zip » fournie et y intègre le portage complet d’Argument de la première archive. Les fichiers communs ont été fusionnés ; les traductions ne sont pas dupliquées.

## Installer

1. Sauvegarder le dossier actuel et le wikicode des modèles.
2. Remplacer le dossier `extensions/DebatePHP/` par celui de cette archive. Garder `wfLoadExtension( 'DebatePHP' );` et les réglages existants de `LocalSettings.php`.
3. Copier `resources/Template-Argument.php.wikitext` dans la partie `<includeonly>` du modèle Argument ; conserver sa documentation hors de cette partie. Le nouveau fichier contient lui-même les balises `<includeonly>`. Il ne faut pas les imbriquer.
4. Le modèle Débat déjà converti conserve son appel `{{#debate:render}}`. Un exemplaire est fourni dans `resources/Template-Debat.php.wikitext`.
5. Garder les pages Module:Argument, Module:Debate, Module:WD/I18N et Module:WD/I18N/Data synchronisées avec les sources de `resources/`. Elles sont lues pour contrôler la version et enregistrer les dépendances ; le rendu PHP n’exécute pas leur Lua. Ne pas remplacer les sources sur le wiki si elles correspondent déjà.
6. Purger les pages concernées pour renouveler le cache.

MediaWiki 1.43 est la cible maintenue. Scribunto reste une dépendance de l’extension. SMW/SemanticScribunto, Page Forms, ShortDescription, WikiSEO et Variables/VariablesLua conservent leurs rôles respectifs lorsque ces fonctions sont utilisées. HTML Tags est facultatif : le rendu natif et un hook de compatibilité intégré couvrent les balises utilisées. Si HTML Tags reste chargé, son hook n’est pas remplacé.

Le mode `$wgDebatePHPMode` vaut `reference` par défaut et accepte `optimized` ; les deux moteurs sont en PHP. Il s’applique aux deux modèles. Les paramètres de profilage, diagnostics et politique d’attributs de la seconde archive sont conservés. Aucune sauvegarde de page, création de formulaire ni écriture de configuration du serveur n’est effectuée pendant le rendu. Le bouton d’autoédition ne réalise une modification qu’à son activation par l’utilisateur, via Page Forms.

## Opérations remplacées

| Opération Argument | Chemin de cette version |
| --- | --- |
| Boutons d’ajout `<htmltag>` | Attributs et contenu structurés, pipeline HTML PHP partagé, protection StripState |
| Script JSON-LD `#tag:htmltag` | Même pipeline PHP, politique et échappement de HTML Tags 0.3 conservés |
| Nom consacré `SHORTDESC` | Callback PHP enregistré par ShortDescription, avec ses métadonnées et catégories |
| Date `CURRENTYEAR` / `CURRENTMONTH` / `CURRENTDAY2` | Appels structurés au cœur du parseur, sans analyser une chaîne `{{…}}` ; horodatage, hooks, chiffres localisés et expiration du cache conservés |
| Bouton d’initialisation `#autoedit` | Callback Page Forms appelé directement avec un tableau de paramètres |
| Citations sérialisées littérales | Retour direct avec normalisation des fins de ligne ; pas de second passage du préprocesseur |
| Contenu littéral de balise ou d’attribut | Retour direct ; pas d’analyse du préprocesseur |

Le moteur Argument généré ne contient plus d’appel `preprocess()` ni de construction des wrappers listés ci-dessus. Le script de génération applique les opérations natives après traduction et produit le même fichier à chaque exécution.

Du wikicode dynamique peut toujours être contenu dans un champ (modèles, fonctions, notes, balises nowiki). Son expansion reste nécessaire. Le pipeline partagé conserve également les replis de compatibilité de la seconde archive pour les constructions ambiguës susceptibles d’absorber un séparateur ou de fermer un appel. Il ne prétend pas transformer automatiquement tout modèle du site en PHP. Ces exceptions préservent les effets de bord et le rendu de l’existant.

Les séparateurs des données, catégories, avertissements, références, parents et fils d’Ariane, propriétés SMW, variables de citations, SEO, formulaires et styles restent ceux de la source. Le paramètre global `reference-warnings` est transmis mais non rendu par le Lua d’origine ; ce comportement reste conservé.

## Vérifications exécutées pour cette livraison

Les rapports `tests/results/v050-*.json` et `v050-checks.txt` sont produits par cette session. Les autres rapports sont l’historique conservé des archives fournies.

- Analyse syntaxique de tous les fichiers PHP et vérification de l’autochargement.
- 174 comparaisons des moteurs Argument avec le Lua 5.1 fourni, sur 87 cas avec contexte simulé : rendu, requêtes, valeurs SMW, SEO, variables et développements de champs.
- 24 assertions de l’adaptateur MediaWiki/SMW avec doubles, notamment les paramètres, les dépendances et les tableaux imbriqués.
- 2 510 comparaisons des traductions et les tests existants des journaux.
- 52 comparaisons réelles des balises natives et de SHORTDESC avec HTML Tags 0.3 et ShortDescription, en modes HTML et prétraitement.
- 22 comparaisons réelles de date, citations et autoédition avec les anciens appels, dans ces deux modes.
- 172 comparaisons de pages Argument (86 cas valides × deux modes PHP) avec le Lua, avec HTML Tags ; même série sans HTML Tags, puis avec Variables/VariablesLua et sans HTML Tags.
- 70 comparaisons de pages Debate (35 cas × deux modes PHP) avec le Lua, incluant les cinq pages de référence de l’archive et les cas synthétiques.
- Vérification des graphes JSON-LD, catégories, propriétés, liens, dépendances de modèles, modules, drapeaux et expiration du cache. L’ordre des attributs HTML, clés JSON et paramètres URL distincts est normalisé ; leurs valeurs ne le sont pas.
- Régénération identique du moteur Argument ; contrôle d’intégrité ZIP et empreintes de tous les fichiers.

Un titre volontairement invalide du corpus simulé ne peut pas désigner une page MediaWiki et est exclu de la série réelle (87 → 86). L’environnement local est MediaWiki 1.43.0, PHP 8.2.32, SQLite, Scribunto/LuaStandalone 5.1.5, ParserFunctions, Cite, Page Forms 5.8.1, ShortDescription 4.0.2 et, selon la série, HTML Tags 0.3, Variables 2.6.0-beta et VariablesLua 1.7.0. Le relevé facultatif des ressources `/proc` de LuaStandalone est indisponible dans cet environnement ; seule son alerte connue est filtrée dans le test de pages, pas les erreurs de rendu.

**Limite :** ces contrôles ne sont pas un test du magasin SMW de votre serveur, des écritures après enregistrement, de ses tâches différées ou de toutes ses personnalisations. Les valeurs SMW sont comparées avec des doubles ; les graphes parents sont couverts par les tests de contexte. La procédure `--full-stack` est fournie pour une copie complète du site. Aucun gain de vitesse sur votre serveur n’est inventé.

## Reproduire les tests

Tests sans installation MediaWiki (PHP et Python avec `lupa`) :

```bash
php tests/argument_adapter.php
python3 tests/argument_differential.py /chemin/php
php tests/i18n_bundle.php
python3 tests/differential_i18n.py /chemin/php
php tests/telemetry.php
```

Tests sur un wiki de test contenant les modules sources synchronisés et les extensions citées :

```bash
php tests/integration/tags.php --wiki-root=/chemin/mediawiki
php tests/integration/argument_operations.php --wiki-root=/chemin/mediawiki
php tests/integration/argument_render.php --wiki-root=/chemin/mediawiki --corpus=/chemin/DebatePHP/tests/fixtures/argument-cases.json --output=/chemin/argument-raw.json
python3 tests/integration/check_argument_render.py /chemin/argument-raw.json /chemin/argument-report.json
```

Le script de pages ne sauvegarde pas les pages. Il teste le contenu du corpus et les extensions réellement chargées ; les parentés réelles dépendront donc du magasin du wiki de test. Les doubles sémantiques et bibliothèques de capture ne doivent être chargés que dans le wiki jetable prévu par la procédure, jamais sur un vrai magasin SMW.

Pour régénérer Argument : installer `luaparser`, puis lancer `python3 tools/buildArgument.py`. Le second script `applyArgumentNative.py` est appelé automatiquement. Pour revenir en arrière, restaurer le dossier sauvegardé et l’ancien wikicode des modèles, puis purger les pages concernées.
