# Version 0.6.0 : scripts directs et champs littéraux

## JSON-LD

Les deux moteurs transmettent le JSON généré à `MediaWikiContext::jsonLd()`. Cette méthode vérifie la validité du JSON, échappe `<`, `>` et `&` au moyen d’échappements Unicode JSON, construit directement une balise `<script type="application/ld+json" class="navigation-not-searchable">` en PHP, puis protège son insertion avec le StripState MediaWiki.

Ce chemin ne consulte pas HTML Tags, ne construit pas d’appel `#tag` ou `htmltag`, n’exécute aucun modèle contenu dans les chaînes JSON et ne possède aucun repli vers un prétraitement. Une valeur contenant `</script>` reste une chaîne JSON et ne ferme pas la balise.

L’ancienne protection de séparateurs `|`, `=` et `}}` n’est plus appliquée au JSON. Elle servait à faire passer le JSON dans la syntaxe `#tag`, devenue inutile ici. L’ancien pipeline HTML Tags échappait aussi `&` et `<` en HTML à l’intérieur du script : les chaînes JSON pouvaient donc contenir `&amp;`, `&amp;#124;` ou `&amp;#61;` au lieu des caractères initiaux. L’émission directe corrige ces valeurs, notamment les URL. Il s’agit d’une correction attendue des données JSON-LD, pas d’un changement de texte visible.

Les comparaisons de cette version exigent un HTML visible et des métadonnées identiques. Pour le JSON-LD, elles vérifient que les différences avec Lua correspondent exactement à la suppression de cet ancien échappement : l’encodage hérité est reproduit sur les nouvelles valeurs avant comparaison. Des tests séparés exigent la conservation exacte des valeurs, y compris les entités déjà présentes littéralement, les accolades, liens wiki, références et fermetures de script.

Le pipeline historique `htmlTag()` reste présent pour les boutons, les usages de compatibilité et d’éventuelles balises écrites par les contributeurs. Le JSON-LD des deux moteurs ne l’appelle plus. La configuration des attributs HTML du pipeline historique ne filtre donc pas le script JSON-LD généré, dont le type et la classe sont fixes.

## Les 13 champs Debate

Les deux listes d’arguments, l’introduction, les liens Wikipédia et les neuf listes de références ne passent plus directement par `preprocess()`. Ils utilisent `expandField()`, partagé avec les citations d’Argument.

- Sans ouverture de modèle `{{` et sans caractère `<`, la valeur est renvoyée directement, après normalisation des retours à la ligne. Les séparateurs sérialisés, les liens wiki et les signes `=` et `|` n’imposent pas une seconde analyse.
- Si la valeur contient encore une ouverture de modèle ou une balise possible, une expansion est conservée pour reproduire les modèles imbriqués, notes, nowiki et leurs effets de bord.

La première expansion des paramètres, faite par MediaWiki pour appeler le modèle, existe toujours. Cette optimisation vise le second passage ajouté par l’extension. Il reste 13 lectures de champs possibles ; ce ne sont plus 13 passages systématiques au préprocesseur. Les champs vides continuent à être ignorés.

Argument conservait déjà une expansion conditionnelle pour les citations, ainsi que des fonctions communes capables de développer du wikicode. Son bon fonctionnement ne démontrait donc pas que toute expansion pouvait être supprimée. La même optimisation est maintenant appliquée à Debate ; les cas de doubles expansions du corpus restent fonctionnels.

## Installation

Depuis 0.5.0, remplacer uniquement `extensions/DebatePHP/` par le dossier de cette archive et purger les pages concernées. Les modèles et leurs paramètres ne changent pas. Conserver les modules de référence, les traductions synchronisées et les extensions fonctionnelles du wiki. Aucune migration de base propre à DebatePHP n’est ajoutée.

## Vérifications

Les nouveaux résultats sont dans `tests/results/v060-*`. Les fichiers `v050-*` restent l’historique de la version précédente.

- Comparaisons Argument avec le vrai parseur, avec HTML Tags, sans HTML Tags et avec Variables/VariablesLua : 86 cas valides × deux modes PHP × trois configurations.
- Comparaisons Debate avec le vrai parseur : 35 cas × deux modes PHP, avec conservation des valeurs SMW capturées.
- Comparaisons des balises/boutons/SHORTDESC et des opérations date/citations/autoédition déjà présentes.
- 31 assertions nouvelles : JSON décodable, valeurs identiques aux données d’entrée, absence d’exécution wiki dans le script, impossibilité de fermer le script par une valeur et distinction entre champs littéraux et dynamiques, en modes HTML et prétraitement.
- Comparaisons de rendu Argument avec contexte simulé, des traductions et tests de l’adaptateur, journaux, syntaxe et régénération identique des deux moteurs.

La comparaison du JSON-LD tient explicitement compte de la correction d’encodage décrite ci-dessus ; cette correction n’est pas déclarée comme une équivalence octet par octet avec les anciens scripts. Les autres écarts sont des échecs.

L’environnement reste MediaWiki 1.43.0, PHP 8.2.32, LuaStandalone 5.1.5, SQLite et les extensions décrites dans `fusion-0.5.0.md`. Le magasin SMW de production, ses écritures et tâches différées ne sont pas disponibles ici.

Tests supplémentaires sur le wiki de test :

```bash
php tests/integration/direct_jsonld.php --wiki-root=/chemin/mediawiki
python3 tests/integration/check_direct_jsonld.py /chemin/resultats-bruts.json /chemin/rapport.json
```

Pour régénérer les moteurs, `tools/buildArgument.py` et `tools/buildReference.py` appliquent automatiquement les opérations natives révisées. Un contrôle vérifie que la régénération conserve les fichiers à l’identique.
