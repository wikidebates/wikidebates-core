# Retrait de la compatibilité HTML Tags

Le moteur DebatePHP n'utilise plus l'extension HTML Tags : les deux appels internes construisent uniquement des liens de navigation RunQuery par l'API PHP de MediaWiki. Le JSON-LD conserve son traitement PHP indépendant. Wikidebates ne déclare aucun hook public `htmltag`.

## Avant un déploiement

La migration PHP ne réécrit pas les modèles et modules Lua des deux wikis. **Vérifier sur les wikis français et anglais** les usages actifs de `<htmltag>`, `#tag:htmltag` et des autres fonctions fournies par HTML Tags, puis les migrer. La seule consultation du dépôt ne permet pas de certifier l'absence de tels usages dans la base de données. Une extension tierce HTML Tags éventuellement chargée reste indépendante.

Les liens internes n'admettent que `href`, `data-href`, `class` et `title`. Les URL utilisant des protocoles exécutables sont exclues, hormis le `javascript:void(0)` littéral de l'ancien bouton RunQuery, conservé uniquement pour l'attribut `href`. Les scripts JSON-LD ne passent pas par ce mécanisme.

## Inventaire complet depuis le serveur MediaWiki

Les API publiques de `fr.wikidebates.org` et `en.wikidebates.org` ont renvoyé **HTTP 403 / Cloudflare challenge** aux agents GitHub Actions le 19 novembre 2026. Ces réponses ne constituent **pas** un inventaire des pages. Une absence de résultat sur l'API publique ne prouve pas l'absence d'usage.

Le script `maintenance/FindHtmlTags.php` est **en lecture seule** : il parcourt la table `page` par lots, consulte le contenu principal de la dernière révision, puis signale les invocations littérales `<htmltag>` (ou `</htmltag>`) et `#tag:htmltag`. Il couvre **tous les espaces de noms** et signale les textes non inspectables, les révisions absentes et les erreurs. Les modèles transclus, modules Lua et pages ordinaires peuvent donc être identifiés sans passer par le réseau public.

Depuis la racine MediaWiki 1.43, sur une copie de la branche contenant le script :

```sh
php maintenance/run.php extensions/Wikidebates/maintenance/FindHtmlTags.php --wiki=fr
php maintenance/run.php extensions/Wikidebates/maintenance/FindHtmlTags.php --wiki=en
```

Pour limiter un premier passage et reprendre ensuite :

```sh
php maintenance/run.php extensions/Wikidebates/maintenance/FindHtmlTags.php --wiki=fr --limit=1000
php maintenance/run.php extensions/Wikidebates/maintenance/FindHtmlTags.php --wiki=fr --after=1000
```

**Attention :** l'option `--after` attend le dernier `page_id` affiché comme `resume_after`, **pas** le nombre de pages inspectées. Les résultats sur une réplique peuvent être légèrement en retard. Le script n'explore que les **versions courantes**, et une recherche littérale peut trouver des commentaires ou des exemples non exécutés. Inspecter chaque correspondance pour décider de sa migration. Un résultat à zéro ne valide pas les usages dynamiques construits par des modules ou d'autres extensions ni les anciens historiques.

Après migration, refaire l'inventaire, puis vérifier les rendus français et anglais, les boutons RunQuery et les métadonnées JSON-LD **sans** HTML Tags avant de déployer l'abandon en production.

## Tests

```sh
php tests/run.php
MW_INSTALL_PATH=/path/to/mediawiki-1.43 php tests/mediawiki.php
```

Le banc MediaWiki vérifie l'absence du hook public, l'encodage des liens, les attributs, les URL et le refus de balises arbitraires, ainsi que les rendus des moteurs `reference` et `optimized`. Les vérifications sur les modèles du site et les extensions sémantiques réelles demeurent nécessaires avant désactivation de HTML Tags sur le serveur.
