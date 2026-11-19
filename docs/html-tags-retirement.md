# Retrait de la compatibilité HTML Tags

Le moteur DebatePHP n'utilise plus l'extension HTML Tags : les deux appels internes construisent uniquement des liens de navigation RunQuery par l'API PHP de MediaWiki. Le JSON-LD conserve son traitement PHP indépendant. Wikidebates ne déclare aucun hook public `htmltag`.

## Avant un déploiement

La migration PHP ne réécrit pas les modèles et modules Lua des deux wikis. **Vérifier sur les wikis français et anglais** les usages actifs de `<htmltag>`, `#tag:htmltag` et des autres fonctions fournies par HTML Tags, puis les migrer. La seule consultation du dépôt ne permet pas de certifier l'absence de tels usages dans la base de données. Une extension tierce HTML Tags éventuellement chargée reste indépendante.

Les liens internes n'admettent que `href`, `data-href`, `class` et `title`. Les URL utilisant des protocoles exécutables sont exclues, hormis le `javascript:void(0)` littéral de l'ancien bouton RunQuery, conservé uniquement pour l'attribut `href`. Les scripts JSON-LD ne passent pas par ce mécanisme.

## Tests

```sh
php tests/run.php
MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawiki.php
```

Le banc MediaWiki vérifie l'absence du hook public, l'encodage des liens, les attributs, les URL et le refus de balises arbitraires, ainsi que les rendus des moteurs `reference` et `optimized`. Les vérifications sur les modèles du site et les extensions sémantiques réelles demeurent nécessaires avant désactivation de HTML Tags sur le serveur.
