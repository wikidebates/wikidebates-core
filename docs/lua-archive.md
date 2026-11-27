# Conservation des révisions Scribunto sans interpréteur

Le sous-répertoire `lua-archive` contient le gestionnaire d'archives inerte,
automatiquement enregistré par Wikidebates sur FR et EN sans moteur Lua.
Le manifeste autonome reste compatible avec les anciens déploiements.
Elle conserve les identifiants des espaces Module (828) et Discussion module
(829), le modèle de contenu historique `Scribunto` et son format texte.
Le code est uniquement affiché dans une balise pre échappée. Les anciennes
révisions, les sources, les différences et les permissions restent prises en
charge par MediaWiki. Le gestionnaire n'exécute pas de code, ne lance pas de
processus Lua, ne valide pas la syntaxe Lua, ne fournit pas de console et
refuse la transclusion directe du contenu archivé. Il ne reproduit pas les
redirections Lua ni l'affichage automatique des pages /doc. Il n'interdit pas
les modifications de texte aux utilisateurs disposant des droits ordinaires.

Wikidebates enregistre les mêmes espaces, le modèle et les hooks au démarrage
sur FR/EN si aucune des trois extensions Lua et aucune archive autonome
n'est chargée. Les quatre autres langues restent inchangées. Une ancienne
déclaration explicite de WikidebatesLuaArchive reste prise en charge sans
double enregistrement. La retirer après déploiement du Core et vérifier
le gestionnaire de contenu Scribunto plutôt que la présence de l'ancienne
extension autonome dans Special:Version. Ne jamais charger simultanément
l'archive autonome et le moteur Lua.

Le test d'intégration crée des révisions avec le vrai Scribunto sous MediaWiki
1.43.12, prend un instantané d'une base SQLite jetable puis démarre un nouveau
processus qui ne charge que le gestionnaire d'archives. Il vérifie les mêmes
IDs de révision et octets, y compris une ancienne version Lua d'une page
devenue wikitexte et une révision à visibilité restreinte.

## Procédure historique de migration (ancienne version du Core)

1. Déployer et vérifier la nouvelle version PHP/JS avec les extensions Lua
encore actives. Confirmer que les anciennes versions ResourceLoader ne
constituent plus une dépendance active.
2. Sauvegarder la base et la configuration, ainsi que la liste des modèles et
espaces de noms, avant tout changement. Tester les six wikis sur une copie.
3. Pour FR et EN seulement, remplacer le chargement de Scribunto,
SemanticScribunto et VariablesLua par :

```php
wfLoadExtension( 'WikidebatesLuaArchive', "$IP/extensions/Wikidebates/lua-archive/extension.json" );
```

4. Vérifier l'historique, l'accès à oldid, la source, les diffs, les permissions,
la configuration des espaces de noms et les infobulles. Les anciennes pages
wikitexte qui invoquent Lua ne sont pas promises à un rendu historique
identique : c'est le code source des modules et son historique qui est
préservé. Aucun renommage ni migration du modèle de contenu en base.
5. Ne retirer les fichiers des extensions et LuaSandbox qu'après ces contrôles.
En cas d'échec, rétablir les trois extensions et la configuration sauvegardée,
sans modification du contenu. Ne pas purger tous les caches par défaut.

Sur le Core actuel, le chargement explicite de l'archive à l'étape 3 n'est
plus nécessaire après retrait des extensions Lua. Les tests rejouent la
migration sur le même instantané avec l'archive autonome, le Core seul et
les deux chargements pour vérifier la transition.
