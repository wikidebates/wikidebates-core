> Documentation originale du paquet autonome 0.8.0. Wikidebates intègre ce paquet avec un seul `wfLoadExtension( 'Wikidebates' )` ; voir [le README du dépôt](../README.md).

# DebatePHP 0.8.0 — MediaWiki 1.43

Rendu PHP des modèles Débat et Argument, avec `{{#debate:render|…}}` et `{{#argument:render|…}}`. Les paramètres français des modèles et des pages restent identiques.

Le dispositif de transition est supprimé : aucune lecture, comparaison de versions ou dépendance de cache envers `Module:Argument`, `Module:Debate`, `Module:WD/I18N` et `Module:WD/I18N/Data` pendant le rendu PHP. Le code et les traductions installés dans l’extension font autorité. Les fichiers Lua inclus servent uniquement aux tests et aux outils de développement facultatifs.

## Installation et mise à jour

1. Remplacer le dossier `extensions/DebatePHP/` par celui de cette archive.
2. Conserver dans `LocalSettings.php` :

   ```php
   wfLoadExtension( 'DebatePHP' );
   ```

3. Si les modèles utilisent déjà `#argument:render` et `#debate:render`, ne rien changer à leur texte. Pour une première installation, remplacer leur appel `#invoke` par la fonction PHP correspondante. Les modèles complets sont fournis dans `resources/Template-Argument.php.wikitext` et `resources/Template-Debat.php.wikitext` : copier leur contenu dans les pages modèles du wiki.
4. Appliquer la procédure habituelle de rechargement OPcache et purger les pages concernées. Aucune migration de base propre à cette extension.

DebatePHP ne requiert plus Scribunto pour ses moteurs. Les autres modèles du wiki peuvent encore utiliser Lua. Conserver les extensions nécessaires aux fonctions du site : SMW/SemanticScribunto pour les données sémantiques, Page Forms pour les formulaires, ShortDescription pour les descriptions, WikiSEO pour le SEO, Variables/VariablesLua si leurs variables sont utilisées. SemanticScribunto et VariablesLua peuvent eux-mêmes dépendre de Scribunto. Cette version ne remplace pas ces extensions.

HTML Tags est facultatif : les boutons utilisent le traitement PHP natif et le hook compatible fourni par DebatePHP. Le JSON-LD est émis directement dans une balise `script`, sans `#tag`, sans `htmltag`, sans prétraitement de ses chaînes et sans repli. Les caractères `<`, `>` et `&` sont protégés par des échappements JSON qui conservent leurs valeurs.

## Compter les prétraitements

Activer temporairement dans `LocalSettings.php`, avec un chemin de journal accessible au serveur :

```php
$wgDebatePHPProfiling = true;
$wgDebugLogGroups['DebatePHP'] = '/var/log/mediawiki/debatephp.log';
```

Chaque invocation exécutée produit un événement `profile`, avec la page, le moteur, le mode, les durées et ces compteurs :

| Champ sous `counters` | Signification |
| --- | --- |
| `preprocess_executed` | Appels au préprocesseur effectivement lancés par DebatePHP, y compris les tentatives échouées |
| `preprocess_cache_hits` | Demandes de prétraitement satisfaites par le cache, donc sans nouvel appel |
| `native_cache_hits` | Opérations natives satisfaites par le cache |
| `literal_fields` | Champs reconnus comme littéraux, y compris les demandes répétées |

`preprocessing` détaille `calls` et `milliseconds` par origine : chaque champ de Débat (`debate.introduction`, `debate.bibliography.pro`, etc.), citations d’Argument (`argument.quotes`), paramètre Page Forms (`autoedit.parameter`), description complexe (`shortdesc.fallback`) ou balise (`htmltag.*`). Pour chercher où gagner du temps, examiner les origines les plus coûteuses sur plusieurs pages représentatives.

Le compteur mesure les entrées supplémentaires explicitement déclenchées par l’extension, après prise en compte de son cache. Il ne compte pas chaque expansion interne d’un modèle, le développement initial des paramètres assuré par MediaWiki, ni les appels internes des extensions tierces. Les durées d’opérations imbriquées peuvent se recouvrir : ne pas les additionner au temps total du rendu.

Une invocation peut avoir **zéro** prétraitement supplémentaire. Une consultation entièrement servie par le cache de page n’exécute pas le moteur et ne produit pas d’événement. Purger une page pour obtenir une mesure de son rendu, puis remettre le réglage à `false` après observation. Aucun texte développé ni valeur sémantique n’est enregistré dans ces événements.

Les prétraitements conditionnels nécessaires au contenu wiki sont conservés : un modèle ou une balise présents dans un champ doivent encore être développés. Les 13 emplacements de Débat ne signifient donc pas 13 exécutions systématiques.

## Simplifications et traductions

Depuis 0.8.0, les conditions des deux moteurs utilisent les opérateurs PHP `&&`, `||` et `!` dans les contextes booléens. Les comparaisons ne passent plus par des conversions et des variables temporaires inutiles. Le code contient 747 appels à `Lua::truth()` de moins qu’en 0.7.0 ; ce décompte concerne les occurrences dans les fichiers, pas le nombre d’exécutions sur une page.

`NativeText.php` partage les fonctions d’ancres, d’infobulles, d’échappement d’attributs, de découpage au premier séparateur et d’encodage des segments de chemin. `SerializedData.php` assure aussi la lecture native des références et des citations d’Argument. Les caches inutilisés issus des anciennes fonctions Lua ont été retirés ; les caches actifs gardent leurs règles de taille, d’ordre FIFO et de volatilité. La normalisation des paramètres d’Argument capture uniquement sa table de paramètres.

Les générateurs conservent ces simplifications à chaque reconstruction. La compatibilité de vérité Lua reste nécessaire pour les chaînes vides, `"0"`, le nombre zéro et les tables vides : ces valeurs ne sont pas fausses en Lua. Les conversions conservées protègent ces comportements.

Les noms historiques `$wgDebatePHPMode = 'reference'` (défaut) et `'optimized'` restent acceptés. Tous deux exécutent du PHP ; le second active notamment la mémorisation des traductions. Les classes PHP `Lua` et `Table` préservent encore certaines règles du portage. Elles ne sont pas des interpréteurs. La simplification est progressive, sans réécriture globale des règles métier.

Les traductions sont chargées depuis `resources/I18nData.php`, sans contrôle des pages Lua. Modifier ces pages n’a aucun effet sur le rendu PHP. Une évolution des traductions PHP se déploie comme un changement de code, avec rechargement OPcache et purge. Les outils `compileI18n.php` et `maintainI18n.php` sont facultatifs pour importer/comparer une ancienne source ; seul ce dernier exige un wiki de référence avec Scribunto.

## Vérification

Voir [docs/simplification-0.8.0.md](docs/simplification-0.8.0.md) et les rapports `tests/results/v080-*`. Ils couvrent les deux moteurs, les sorties visibles, les métadonnées, les valeurs JSON-LD, les compteurs et le fonctionnement sans Scribunto. Les scripts de comparaison utilisant Lua sont des outils de test facultatifs et ne participent pas au rendu.

Les documents `direct-0.6.0.md`, `fusion-0.5.0.md`, `equivalence.md` et `resultats.md` décrivent aussi des étapes historiques. Les instructions de cette page et de `simplification-0.8.0.md` prévalent pour la version actuelle.
