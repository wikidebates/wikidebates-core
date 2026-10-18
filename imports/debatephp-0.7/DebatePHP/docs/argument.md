# Portage natif de Module:Argument

Le portage initial ajoutait `{{#argument:render}}` à l’extension fournie. `{{#debate:render}}` reste disponible. Les deux fonctions utilisent le même réglage `$wgDebatePHPMode` (`reference` ou `optimized`). Aucun interpréteur Lua n’exécute le rendu Argument en PHP. Les autres modèles et modules éventuellement présents dans les paramètres peuvent encore invoquer Lua.

## Installation

1. Sauvegarder le dossier actuel `extensions/DebatePHP/` et le wikicode du modèle Argument.
2. Remplacer ce dossier par le dossier `DebatePHP/` de l’archive. Conserver `wfLoadExtension( 'DebatePHP' );` dans `LocalSettings.php`. Aucune migration de base de données n’est ajoutée.
3. Remplacer uniquement la partie de transclusion du modèle Argument par le contenu de `resources/Template-Argument.php.wikitext`. Conserver sa documentation et ses catégories hors de `<includeonly>` si elles existent. Les 20 paramètres français sont conservés. Le changement d’appel essentiel est :

   ```wikitext
   {{#argument:render
   ```

   à la place de `{{#invoke:Argument|render`.
4. Les anciennes pages Lua ne sont plus lues ni vérifiées par le rendu PHP depuis 0.7.0. Les traductions installées dans `resources/I18nData.php` font autorité.
5. Purger les pages Argument concernées pour reconstruire le cache de rendu. Pour un grand nombre de pages, employer la procédure habituelle de rafraîchissement et de gestion des tâches du wiki.
6. Vérifier quelques pages représentatives sur une copie du wiki, puis choisir si souhaité :

   ```php
   $wgDebatePHPMode = 'optimized';
   ```

Le réglage modifie également le mode de `#debate`, comme auparavant. `reference` constitue aussi un rendu PHP natif. Il n’y a pas de bascule automatique vers Lua en cas d’erreur.

## Compatibilité

Cible : MediaWiki 1.43, comme l’extension d’origine ; PHP 8.1 ou ultérieur selon les exigences de MediaWiki. Les règles françaises et anglaises de WD/I18N sont réutilisées, sans recopier les traductions dans un second fichier.

- Semantic MediaWiki et SemanticScribunto : lecture des parents, cartes et fils d’Ariane via les classes PHP de SemanticScribunto, puis enregistrement groupé des propriétés. Adaptateur basé sur l’API de SemanticScribunto 2.3.3. Aucune requête SQL directe.
- Variables et VariablesLua : conservation des variables `WD_QUOTES_*` et `WD_QUOTE_*` dans le magasin du parseur, lorsque ces extensions sont présentes. En leur absence, les wrappers restent sans effet, comme dans le Lua fourni.
- WikiSEO : même adaptateur natif que pour Debate.
- Page Forms : callback PHP natif pour l’initialisation ; HTML Tags facultatif. Les formulaires, classes CSS et scripts du site doivent toujours exister.
- Scribunto : plus requis par DebatePHP depuis 0.7.0 ; d’autres extensions ou modèles du wiki peuvent en avoir besoin.

`reference-warnings` est transmis par le modèle et conservé dans les arguments ; le module fourni n’en fait pas de rendu global. Les avertissements des références sérialisées sont, eux, traités. Ce comportement n’a pas été réinventé pendant le portage.

## Opérations natives de la version 0.5.0

Le rendu Argument utilise désormais les opérations PHP `htmlTag`, `shortDescription`, `currentDate`, `autoEdit` et `expandQuotes`. Les appels enveloppants `#tag:htmltag`, `<htmltag>`, `SHORTDESC`, `#autoedit` et les trois variables de date ne sont plus construits par son moteur. Les données littérales de citations et de balises évitent également l’analyse du préprocesseur. Le développement du wikicode réellement contenu dans un champ et les replis de compatibilité pour les constructions ambiguës restent nécessaires pour reproduire le comportement existant. Voir [fusion-0.5.0.md](fusion-0.5.0.md).

## Ce qui est porté

Titres et noms consacrés, bandeaux et catégories, mots-clés, résumé, citations et leurs variables, références bibliographiques/sitographiques/vidéographiques, justifications, objections, initialisation et boutons Page Forms, débat dédié, parents, fils d’Ariane, rubriques, interlangues, données SMW, métadonnées SEO et graphe JSON-LD.

Les séparateurs sont conservés : `⟭` / `⟬` pour les références et arguments, `⟭⟭⟭` / `⟬⟬⟬` pour les citations. Les règles parfois surprenantes de la source sont conservées : par exemple une justification sans séparateur d’item `⟭` n’est pas reconnue par son parseur Lua.

Les caches sont limités à un contexte de rendu, avec bornage des requêtes et titres. Aucun cache global ne réutilise des données sémantiques d’une autre page. Les paramètres passent par de véritables nœuds et cadres du préprocesseur MediaWiki : un `=` ou un `|` produit par l’expansion d’une valeur ne devient pas un nouveau paramètre.

## Vérifications effectuées ici

- Analyse syntaxique de tous les fichiers PHP.
- 24 assertions sur l’adaptateur natif avec doubles MediaWiki/SMW : enregistrement des hooks, cadres, paramètres, dépendances, résultats SMW imbriqués, variables partagées, erreurs et volatilité du cache.
- 20 comparaisons du rendu Debate avec la version 0.3.0 fournie (10 cas, deux modes, contexte simulé).
- 2 510 comparaisons des traductions à Lua, ainsi que les tests préexistants du chargement i18n et des journaux.
- 87 cas de rendu comparés à l’exécution du Lua fourni avec Lua 5.1, pour chacun des deux moteurs PHP : **174 comparaisons réussies**.
- Comparaison du wikicode/HTML produit, des appels de prétraitement, des requêtes SMW, des propriétés transmises, des données SEO, des variables et du JSON-LD. L’ordre des clés JSON et des paramètres distincts des URL est normalisé ; les valeurs sont comparées.
- Cas FR/EN, valeurs vides et `0`, extensions facultatives absentes, erreurs de requête et d’expansion, bandeaux traduits, citations multiples, références, parents directs et hérités, chaînes courtes et longues, initialisation pour les quatre types et corpus sérialisé déterministe.

Les tests ci-dessus utilisent un contexte simulé. Ils sont complétés dans 0.5.0 par les tests avec le vrai parseur MediaWiki décrits dans [fusion-0.5.0.md](fusion-0.5.0.md), y compris sans HTML Tags et avec Variables/VariablesLua. Les anciens rapports 0.3.0/0.4.0 ne sont pas présentés comme une nouvelle exécution. Aucune mesure de gain sur votre serveur n’est annoncée.

Pour reproduire les tests du moteur, depuis le dossier de l’extension :

```bash
python3 -m pip install lupa
python3 tests/argument_differential.py /chemin/vers/php
php tests/argument_adapter.php
php tests/i18n_bundle.php
python3 tests/differential_i18n.py /chemin/vers/php
php tests/telemetry.php
```

`tools/buildArgument.py` nécessite `luaparser` ; il régénère uniquement `src/ArgumentReferenceRenderer.php`. Les helpers de compatibilité et les frontières MediaWiki sont maintenus à part.

## Comparaison et mesure sur un vrai wiki

Sur une copie du wiki avec les vraies extensions chargées et sans les doubles de test de Debate, préparer un fichier JSON contenant des titres d’arguments existants et leurs appels complets au modèle (avec les paramètres réels). Le script ne remplace pas `{{Argument}}` par le contenu de la page : il faut fournir les appels réels, ou les transclusions de pages entières adaptées au wiki.

```json
[
  {"title": "Titre exact", "text": "{{Argument|résumé=Texte réel|mots-clés=Énergie}}"}
]
```

Puis lancer :

```bash
php tests/integration/compare_argument.php --wiki-root=/chemin/mediawiki --corpus=/chemin/arguments.json --output=/chemin/argument-comparison.json --no-trace --repeats=10
python3 tests/integration/check_results.py /chemin/argument-comparison.json /chemin/argument-report.json
```

Ce script compare les variantes Lua, PHP de référence et PHP optimisée dans un vrai parseur, avec les données sémantiques du `ParserOutput`, les catégories, liens et métadonnées. Il ne sauvegarde pas les pages et ne remplace pas la vérification d’un enregistrement réel et des tâches SMW. Le mode full-stack de ce script n’a pas été exécuté ici faute de magasin SMW complet du site. Les autres tests de parseur réellement exécutés sont détaillés dans fusion-0.5.0.md.

Pour distinguer les coûts du rendu PHP, des expansions et de SMW :

```php
$wgDebatePHPProfiling = true;
$wgDebatePHPDiagnostics = true;
$wgDebugLogGroups['DebatePHP'] = '/chemin/prive/debatephp.log';
```

Le journal inclut maintenant `module: Argument` et un poste `semantic-query`. Il reste facultatif et désactivé par défaut. Les durées internes peuvent se chevaucher ; `render` contient ses sous-étapes. Les mesures du parseur n’incluent pas toutes les écritures et tâches déclenchées par un enregistrement.

## Retour arrière

Restaurer l’appel `{{#invoke:Argument|render` dans le modèle, puis purger les pages concernées. La source Lua est conservée. Pour revenir intégralement à 0.3.0, restaurer également le dossier d’extension sauvegardé.

## Sources de compatibilité

- https://www.mediawiki.org/wiki/Manual:Parser_functions
- https://github.com/SemanticMediaWiki/SemanticScribunto/blob/2.3.3/src/LibraryFactory.php
- https://github.com/SemanticMediaWiki/SemanticScribunto/blob/2.3.3/src/ScribuntoLuaLibrary.php
- https://github.com/wikimedia/mediawiki-extensions-Variables/blob/master/includes/ExtVariables.php
- https://github.com/Liquipedia/VariablesLua

Le Lua Argument de référence est la copie exacte du fichier texte joint par l’utilisateur. Son historique de révisions n’était pas joint.
