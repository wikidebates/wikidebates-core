> Document amont conservé pour le portage. Pour le chargement intégré et les vérifications actuelles, voir [le README Wikidebates](../../README.md) et [le bilan de migration](../../docs/debatephp-upgrade.md). Les anciennes instructions `wfLoadExtension( 'DebatePHP' )` concernent le paquet autonome.

# Expansions et équivalence de DebatePHP

Le portage conserve les effets des expansions du module fourni. Depuis 0.4.0, les blocs HTML générés passent par une construction PHP directe lorsque leur structure est connue, et les descriptions littérales sont transmises au callback PHP enregistré. Les prétraitements des champs ne sont pas supprimés sur l’hypothèse qu’ils seraient déjà développés. Les cas où la syntaxe peut modifier les frontières d’un appel reprennent l’analyse originale.

## Cadres Scribunto et PPFrame

Lorsque `Modèle:Débat` appelle `#invoke:Debate|render`, Scribunto crée un cadre enfant portant le titre `Module:Debate`. Le cadre Lua courant contient les paramètres anglais de l’invocation ; son parent contient les paramètres du modèle Débat. Les valeurs des arguments de cet enfant sont développées dans le contexte de leur appelant par le fonctionnement normal de `PPTemplateFrame_Hash`.

La fonction PHP reçoit directement le cadre du modèle Débat. Il correspond au **parent** du cadre Lua courant. Utiliser son propre parent pour récupérer les arguments du modèle serait une erreur. Développer ensuite le texte produit dans ce seul cadre PHP serait également une erreur : les références de paramètres apparues au second passage pourraient lire la mauvaise valeur.

Le portage utilise `Parser::SFH_OBJECT_ARGS`, conserve les nœuds d’arguments et crée un véritable enfant `PPFrame` intitulé `Module:Debate`. Il retire le sélecteur `render`, sans décalage des indices des paramètres suivants. Dans Scribunto, le sélecteur était le premier paramètre positionnel après le nom du module et nécessitait un décalage de 1 ; avec `#debate:render`, il se trouve dans le premier argument de la fonction de parseur, en amont de ces positions.

Les noms et valeurs ne sont jamais reconstruits à partir d’une chaîne développée puis découpée sur `=` ou `|`. Le préprocesseur traite les nœuds d’origine, y compris les noms dynamiques et les paramètres positionnels.

La fusion des arguments conserve les règles Lua : tous les arguments du parent sont forcés, puis ceux de l’enfant ; les valeurs sont nettoyées avec le jeu d’espaces ASCII de `mw.text.trim` ; seules les valeurs non vides écrasent une valeur héritée. Les paramètres non transmis explicitement par le modèle, tels que `lang`, peuvent ainsi rester disponibles. Un paramètre explicite vide ne supprime pas une valeur non vide du parent.

Le test « Doubles expansions multiples » produit au premier passage un texte contenant `{{{token|DEFAULT}}}`. Le second passage doit lire `MODULE`, présent dans le cadre d’invocation, alors que le parent contient `PARENT`. Les trois implémentations produisent `MODULE` pour les arguments, l’introduction, Wikipédia et une référence sitographique.

## Expansions implicites des arguments

L’itération sur `parent.args` et `frame.args` force leurs valeurs dans Scribunto. Le module le fait pour tous les arguments, y compris ceux qu’il n’affiche pas ensuite. Les effets de balises, les paramètres de langue et les dépendances ne se limitent donc pas aux 24 champs explicitement transmis dans le modèle.

PHP conserve ces lectures avec `getArguments()` sur les deux cadres. Une optimisation consistant à ne lire que les champs connus serait incompatible. Le cas « Argument inutilisé avec effet » vérifie le développement d’une note définie dans un argument inutilisé, puis citée ailleurs.

## Chaque expansion explicite

Les lignes ci-dessous se rapportent au fichier Lua de référence fourni. Une famille de lignes peut comporter plusieurs sites d’appel ou un helper appelé plusieurs fois.

| Site dans le Lua | Rôle nécessaire | Traitement PHP et possibilité d’optimisation |
| --- | --- | --- |
| `tag`, ligne 489 | Développer un appel `#tag` construit à partir d’un nom, d’un contenu et d’attributs. Le hook de balise peut produire un marqueur, des dépendances ou d’autres effets. | Le helper est conservé. Il n’est pas appelé par le chemin `render` actuel ; il ne représente donc aucun coût à retirer de ce chemin. |
| `wkRunQueryHtmlTagButton`, ligne 597 | Développer le contenu et les attributs autorisés des boutons, puis protéger leur HTML par un marqueur. | Construction PHP native dans les deux modes. Le contenu et les attributs gardent leurs expansions, l’échappement et le filtre. Un marqueur général est réservé avant les expansions du corps, comme dans `extensionSubstitution`, pour conserver l’ordre des marqueurs de notes. Une fermeture `htmltag` dans le contenu reprend l’analyse complète. |
| `computeArgumentMapsFromArgs`, lignes 903 et 906 | Développer les deux chaînes déjà obtenues par lecture des arguments. Ce passage peut exécuter du wikicode produit par le premier, puis obtenir les marqueurs `page⟬titre⟬avertissements⟭`. | Deuxième passage conservé séparément pour chaque côté non vide. Ensuite, le découpage en PHP natif évite des appels auxiliaires sans réévaluer le texte. |
| `renderIntroduction`, ligne 1348 | Obtenir les sous-parties et leurs champs `⟬⟬⟬`, en exécutant aussi les modèles et balises retardés du contenu. | Expansion conservée ; seul le découpage des enregistrements est optimisé. Remplacer le résultat par un simple HTML préassemblé pourrait modifier les notes et la structure du parseur. |
| `renderWikipediaLinks`, ligne 1373 | Développer les producteurs de marqueurs `Titre⟭`, y compris quand leur appel est produit au premier passage. | Expansion conservée, puis extraction des titres. Une chaîne contenant déjà `⟭` n’est pas une preuve qu’il n’y a plus rien à développer. |
| Bibliographies, lignes 1936, 1944 et 1952 | Développer les références pour, contre et neutres avant extraction des douze champs. Les valeurs servent au rendu, aux propriétés SMW et au JSON-LD. | Trois passages conditionnels conservés, au même endroit et dans le même ordre. Le texte développé est réutilisé pour le JSON-LD, comme dans Lua. |
| Sitographies, lignes 1991, 1999 et 2007 | Produire les six champs de chaque référence ; conserver les auteurs par côté et les effets des modèles ou balises. | Trois passages conservés. Les valeurs SMW sont transmises comme paramètres PHP, sans fabrication d’un nouvel appel `#set` en wikicode. |
| Vidéographies, lignes 2046, 2054 et 2062 | Produire les quatre champs avant le rendu et la sélection des données `subjectOf` du JSON-LD. | Trois passages conservés ; extraction pure optimisée après expansion. |
| `tagHtmlTag`, ligne 2409 | Préparer le script, développer son contenu puis conserver le second passage du hook et la protection par un marqueur. | Construction PHP native du script à sa position d’origine. Les deux passages de contenu et les deux passages d’attributs de `#tag` sont reproduits. Les constructions pouvant absorber les séparateurs reprennent l’analyse complète avec le hook PHP intégré. Le résultat utilise intentionnellement `Html::element`, avec le même échappement que HTML Tags 0.3. |
| `SHORTDESC`, lignes 3028 et 3031 | Exécuter la branche choisie, développer le sujet si nécessaire, puis appeler ShortDescription avec ses effets complets. | Pour un texte littéral, la valeur développée est exactement le texte après normalisation des retours à la ligne : appel direct à `Parser::callParserFunction`, qui conserve le callback et son nettoyage. En présence de `{`, `}`, `|`, `<`, `[`, `]` ou `=`, ou sans l’alias attendu, analyse complète. Ces gardes couvrent paramètres, tags/commentaires, liens et titres ; elles évitent de changer une frontière d’argument. |

Le Lua comporte jusqu’à **17 appels explicites** à `preprocess` par invocation : 13 champs, une description courte, deux boutons et un script JSON-LD. Depuis 0.4.0, sur la page « Dieu existe-t-il ? », PHP réalise 13 prétraitements de champs et quatre opérations natives. Les expansions internes des balises et les expansions implicites des arguments s’ajoutent à ce décompte. Une syntaxe complexe peut conserver les appels enveloppants ; aucun résultat n’est supposé identique sur la seule absence de doubles accolades.

### Pourquoi les 13 expansions de champs restent nécessaires

Les paramètres du modèle sont déjà développés lors de `getArguments()`, mais leurs résultats peuvent contenir de nouveaux modèles, références de paramètres ou tags. La seconde passe les exécute dans le cadre `Module:Debate`. Les marqueurs `⟭` et `⟬` ne permettent pas d’écarter ce besoin. Les titres de section sont aussi traités par `PPFrame` : même un texte sans doubles accolades peut produire un marqueur de titre, une note, une suppression d’inclusion ou un effet d’extension. Le découpage PHP est donc effectué après ce développement, au même point qu’en Lua.

### Pourquoi le texte littéral de SHORTDESC n’a pas besoin d’un arbre de prétraitement

Dans MediaWiki 1.43, les nœuds de prétraitement ayant des effets proviennent notamment des modèles/paramètres, tags/commentaires/inclusions et titres. Le chemin direct exclut leurs caractères de départ et les délimiteurs pouvant modifier l’appel enveloppant ; il exclut aussi les crochets de liens. Le reste est du texte littéral, pour lequel le résultat de l’expansion est connu. La normalisation CRLF/CR et le nettoyage d’argument sont conservés. Le dispatch public `callParserFunction` utilise toujours le callback enregistré, y compris s’il a été remplacé par le site. Une syntaxe non littérale est analysée complètement, notamment si un paramètre supplémentaire doit être développé même si le callback l’ignore.

## Réutilisation des expansions

`LuaEngine::preprocess()` force d’abord `getArguments()` sur le cadre courant, même avant une réponse trouvée dans son cache. Le portage fait de même. Il normalise ensuite CRLF et CR en LF, crée l’arbre avec les règles d’inclusion correspondant à la profondeur du cadre, puis appelle `expand()` sur ce même cadre.

La réutilisation est limitée à une invocation. Le résultat n’est enregistré que si le cadre n’est pas volatil. La limite et l’éviction suivent le comportement de Scribunto REL1_43. Les durées de vie et la volatilité sont comparées dans les traces instrumentées. Aucun cache de fragments développés n’est partagé entre pages ou entre requêtes.

Les opérations natives utilisent les mêmes clés que les anciens appels enveloppants et le même cache. Une expansion de champ identique à un ancien appel `SHORTDESC` ou `htmltag` peut donc réutiliser son résultat. Les tests vérifient notamment qu’un `SHORTDESC` déjà mémorisé ne réécrit pas une description modifiée entre-temps. Les noms des marqueurs internes peuvent passer de `htmltag` à `item` : les tests comparent alors leur HTML résolu, ainsi que le DOM complet, les données et les dépendances. Les traces indiquent explicitement `native-shortdesc` et `native-htmltag` pour distinguer ces opérations des prétraitements conservés.

## SMW et SEO

Le Lua accumule déjà les propriétés puis appelle `mw.smw.set` une fois à la fin. PHP conserve cet ordre et cet appel groupé. Il reproduit la conversion des scalaires et listes effectuée par SemanticScribunto 2.3.3 et passe par sa `LibraryFactory`, son processeur de paramètres et le parseur `#set` de SMW. Il ne réécrit pas les propriétés directement dans la base.

Les tests isolés comparent toutes les valeurs transmises, avec leurs doublons. Le mode `--full-stack` compare également les données sémantiques réelles du `ParserOutput` lorsque SMW est installé. Les mises à jour du magasin après enregistrement constituent une validation distincte.

Pour WikiSEO, PHP appelle le même validateur, exécute `WikiSEOLuaPreAddPageProps` et écrit les propriétés validées dans le `ParserOutput`. Ces propriétés ont été comparées avec l’implémentation Lua et le code du tag WikiSEO `v2.7.0`.

Les wrappers `smwAskSafe`, `vset` et `vget` n’ont aucun appel dans le chemin de rendu fourni. Le portage n’ajoute donc ni requête SMW ni manipulation de VariablesLua à ce chemin.

## Intégration native de WD/I18N et WD/I18N/Data (0.2.0)

Les deux modules transmis pour cette intégration sont identiques aux références incluses, hormis leur saut de ligne final. `I18n.php` porte la logique du premier module ; `resources/I18nData.php` contient la conversion complète du second en tableau PHP, avec ses quatre domaines. Le test différentiel utilise les deux sources Lua comme oracle indépendant et vérifie les deux stratégies PHP sur 1 255 requêtes chacune.

Les deux modes du rendu chargent maintenant cette table PHP directement, ce qui permet à OPcache de la conserver compilée sur le serveur. Le mode de référence n’analyse plus la table littérale Lua. Le mode optimisé conserve sa mémorisation des messages non formatés. Aucun résultat formaté avec des arguments propres à un appel n’est mémorisé.

Les sources wiki sont encore récupérées via `fetchTemplateAndTitle()` pour conserver les callbacks et les dépendances de cache. Elles servent seulement aux gardes de synchronisation ; aucun `require` Lua, `mw.loadData()` ni analyse de table littérale n’est exécuté par le portage pendant le rendu. Si la table wiki change, la garde demande sa recompilation hors ligne. Cette contrainte remplace le repli automatique sur le lecteur de tables présent jusqu’en 0.1.1.

## Cache et sérialisation

Les transclusions passent par `fetchTemplateAndTitle()`, qui enregistre leurs dépendances et respecte les callbacks de modèles. Les dépendances des trois modules sont conservées, ainsi que celles produites par les expansions. L’accès à l’identifiant courant conserve le drapeau `VARY_PAGE_ID` ; les liens spéciaux créés par l’équivalent de `mw.title.new` sont également enregistrés.

La comparaison n’exige pas l’identité octet par octet des dictionnaires JSON, de l’ordre des attributs HTML ou de l’ordre de clés distinctes dans une chaîne de requête URL. Elle compare le DOM et les valeurs du JSON, sans masquer de différence de texte affiché, de propriété ou de catégorie.

Une adaptation de sérialisation est nécessaire : les clés d’objet ne sont pas énumérées dans le même ordre par Lua et PHP. Avec un ordre PHP différent, trois accolades structurelles consécutives pouvaient survivre au remplacement Lua non chevauchant de `}}` et fermer `#tag` trop tôt. PHP insère donc des espaces JSON uniquement entre fermetures structurelles adjacentes avant d’appliquer la protection d’origine. Les chaînes JSON restent inchangées par cette étape. Les graphes JSON-LD obtenus sont comparés comme des données.

Les compteurs de ressources et les limites propres au moteur Lua ne peuvent pas être identiques après son remplacement. La validation près des limites de profondeur et de taille, les modes de substitution, Parsoid et les personnalisations locales du serveur restent hors du périmètre de certification de cette version candidate. La cible effectivement testée est le parseur PHP historique de MediaWiki 1.43.9.

## Observations et maintenance (0.3.0)

Le site utilise un seul appel à Débat par page. Cette version ajoute des observations limitées à cette invocation, sans cache supplémentaire partagé entre rendus. Quand les deux options sont désactivées, aucun objet de télémétrie n’est créé et aucune horloge ni écriture de journal n’est appelée par les points de mesure.

Les mesures encadrent les appels existants sans en changer l’ordre ni les paramètres. Les exceptions de SMW et de WikiSEO restent absorbées comme dans les `pcall` Lua ; les diagnostics facultatifs n’enregistrent que leur classe et leur étape. Les erreurs textuelles retournées par le parseur SMW sont également signalées sans leur contenu. Les erreurs de rendu qui étaient propagées continuent de l’être. Une défaillance de la journalisation est absorbée séparément.

La maintenance compare le candidat à Scribunto avant d’écrire une table PHP. Cette validation est hors du rendu : elle peut exécuter le Lua de référence et lire une table littérale, alors que l’appel PHP normal à Débat continue d’utiliser exclusivement les traductions PHP compilées.

## Sources techniques

- [Scribunto REL1_43 LuaEngine](https://github.com/wikimedia/mediawiki-extensions-Scribunto/blob/REL1_43/includes/Engines/LuaCommon/LuaEngine.php) et [création du cadre dans invokeHook](https://github.com/wikimedia/mediawiki-extensions-Scribunto/blob/REL1_43/includes/Hooks.php).
- [PPFrame de MediaWiki 1.43](https://github.com/wikimedia/mediawiki/blob/REL1_43/includes/parser/PPFrame.php) et [Parser de MediaWiki 1.43](https://github.com/wikimedia/mediawiki/blob/REL1_43/includes/parser/Parser.php).
- [SemanticScribunto 2.3.3](https://github.com/SemanticMediaWiki/SemanticScribunto/blob/2.3.3/src/ScribuntoLuaLibrary.php).
- [WikiSEO tag v2.7.0](https://github.com/wikimedia/mediawiki-extensions-WikiSEO/blob/v2.7.0/includes/SeoLua.php).
- Révisions exactes des sources Wikidébats : [tests/fixtures/revisions.json](../tests/fixtures/revisions.json).
