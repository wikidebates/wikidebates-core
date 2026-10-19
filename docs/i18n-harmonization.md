# Harmonisation de l’internationalisation

Les textes affichés de Wikidebates et DebatePHP ont désormais une source commune :
`i18n/en.json`, `i18n/fr.json` et la documentation `i18n/qqq.json`. Ils sont
chargés par le système de messages MediaWiki. Le rendu PHP, les contributions
récentes, les résumés automatiques et l’interface JavaScript utilisent ces
catalogues. Cette modification complète la PR #1 pour MediaWiki 1.43 ; le défaut
`reference`, les deux modes et les paramètres existants sont conservés.

## Sources et compatibilité

| Fichier | Rôle |
| --- | --- |
| `i18n/en.json`, `fr.json` | 390 messages affichés, avec paramètres standards `$1`, `$2`, etc. |
| `i18n/qqq.json` | Documentation des clés et paramètres pour les traducteurs |
| `resources/wiki-names.json` | 277 valeurs techniques/configuration du rendu et 25 noms ou cibles du wiki par langue de contenu |
| `resources/i18n-map.json` | Correspondance des 623 entrées historiques DebatePHP, alias et provenance de l’import |
| `i18n/compat/*.json` | Valeurs de compatibilité générées ; ne pas les modifier directement |
| `debatephp/resources/I18nData.php` | Point d’entrée de compatibilité pour les outils anciens ; reconstruit leur tableau depuis les sources JSON |
| `src/I18nModule.php` | Module ResourceLoader normalisant les variantes régionales françaises/anglaises pour cette extension |

Les anciens catalogues JavaScript `modules/i18n/*.js` et les petits fichiers
`debatephp/i18n/*.json` sont retirés. Leurs messages utiles sont intégrés aux
sources communes. Les identifiants `htmltags-*` et les API JavaScript `wkMsg`,
`wkMsgD`, `wkTpl`, `wkParam`, `wkForm` et leurs variantes sont conservés.
Les deux anciens modules ResourceLoader `ext.wikidebates.i18n.en` et `.fr`
restent disponibles comme modules de messages de compatibilité.

L’adaptateur DebatePHP garde les domaines historiques (`Common`, `Debate`,
`Argument`, `ArgumentMap`) et le constructeur utilisable avec un tableau de
fixtures. Les messages installés passent par `wfMessage()` sous MediaWiki ;
les tests autonomes lisent les mêmes JSON. Les paramètres peuvent être réordonnés,
répétés, contenir des signes `%` ou des chaînes comme `$2`, sans substitution
récursive. Les appels hérités qui attendent encore un modèle `printf` restent
compatibles. Les snapshots Lua sont des oracles de test, sans lecture à l’exécution.

La table historique entière n’est pas reconstruite pour chaque rendu. Les JSON
immuables sont chargés à la demande pendant la requête ; MediaWiki fournit son
cache de messages. Le mode `optimized` conserve sa mémorisation supplémentaire
par appel de rendu. Aucun cache partagé supplémentaire n’est introduit.

## Corrections fonctionnelles

Les cinq clés JavaScript anglaises contenant `debate`/`debates` sont alignées
sur les identifiants `debat`/`debats` effectivement demandés par le code. Les
anciens identifiants anglais sont conservés comme alias générés. Les libellés
manquants du bouton de navigation entre arguments, du sommaire et du résumé de
suppression générique disposent maintenant de messages français et anglais.
Le libellé interlangue de Minerva et les replis français écrits dans le JavaScript
utilisent également les messages communs.

Le chargement ne construit plus un nom de module à partir de `wgUserLanguage`.
Les messages sont disponibles avant l’exécution du JavaScript grâce à
ResourceLoader. Les tests réels ont montré que MediaWiki 1.43 ne repliait pas
ces demandes `fr-ca`/`fr-fr` vers le catalogue français. `I18nModule` normalise
ces variantes pour ses propres messages ; les replis globaux de MediaWiki sont
inchangés. Une langue sans traduction, comme l’allemand, utilise le repli anglais.

Les quatre résumés d’ajout d’`AutoParentLink` sont désormais des messages
MediaWiki avec paramètre. Le texte initial et la langue du wiki sont conservés.
L’erreur demandant une adresse électronique à la création du compte possède une
clé traduisible ; la condition de validation reste la même.

## Textes affichés et identifiants techniques

Les libellés de l’interface JavaScript suivent la langue du visiteur. Avec une
interface anglaise sur un wiki français, les actions continuent à viser les
formulaires, modèles et paramètres français du wiki. Le contenu produit par le
parseur conserve sa langue explicite ou celle du wiki, indépendamment du visiteur. Les noms et cibles techniques sont fournis au
JavaScript dans `wgWikidebatesWikiNames`, selon la langue du contenu. Le bouton
Minerva de modification d’une page utilisateur suit désormais cette même règle.

Les noms de propriétés, catégories et paramètres du rendu restent les valeurs
d’origine, choisies selon la langue explicite du rendu, comme avant la migration.
Personnaliser un libellé commun ne peut donc pas renommer une propriété SMW.
Les configurations techniques propres aux hooks, dont `AutoParentLink`, restent
conservées ; aucun modèle, formulaire, catégorie ou attribut existant n’est renommé.

Les anciennes personnalisations des propriétés de contributions récentes via
les messages `wikidebates-recent-changes-parent-property` et
`wikidebates-recent-changes-breadcrumb-property` restent prises en compte dans
la langue du contenu. Les identifiants techniques de compatibilité sont documentés
comme des réglages du wiki, à distinguer des libellés de l’interface.

## Traduire et générer

Pour un texte affiché, modifier les JSON de langue et sa documentation `qqq`.
Conserver les identifiants de messages, les numéros des paramètres et le balisage
nécessaire aux rendus. Les identifiants techniques se modifient explicitement
dans `resources/wiki-names.json`, avec examen des modèles et données concernés.

```sh
php tools/buildI18n.php
php tools/buildI18n.php --check
python3 tests/checkI18n.py
php tests/run.php
node tests/i18n.js
```

Le générateur actualise les valeurs de compatibilité et les déclarations de
messages du manifeste. Le navigateur reçoit les messages `wk-*` utiles à ses
modules ; le catalogue du rendu PHP n’est pas intégralement envoyé au navigateur.
Les anciens alias et les valeurs techniques ne constituent pas une deuxième
source de traduction à éditer.

Les personnalisations du namespace `MediaWiki:` sont désormais disponibles
pour les nouveaux messages du moteur, comme pour les messages natifs de
l’extension. Elles sont sélectionnées dans la langue du rendu ; les libellés
JavaScript suivent la langue de l’interface.

Les commandes historiques de compilation restent utilisables pour produire un
candidat PHP séparé. Lorsque le compilateur historique vise l’ancien fichier
actif, il importe maintenant dans les catalogues JSON et préserve le point
d’entrée de compatibilité. `maintainI18n.php --rebuild` sans `--output` applique
également l’import JSON après ses validations Lua/PHP. Une réimportation explicite
peut actualiser des identifiants techniques : son diff doit être examiné comme
une modification de configuration. Aucune synchronisation depuis le wiki
n’est effectuée automatiquement pendant les rendus.

## Validation

Les résultats structurés sont dans
[tests/results/i18n-migration.json](../tests/results/i18n-migration.json) et
[tests/results/debatephp-i18n-fullstack.json](../tests/results/debatephp-i18n-fullstack.json).

- 390 messages avec mêmes clés et paramètres FR/EN et documentation ; absence
  de doublons JSON et contrôle de 91 références JavaScript.
- Aller-retour des 623 entrées historiques et import atomique dans une copie
  temporaire, avec permissions préservées. Exécution réelle du compilateur
  historique sur cette copie : les JSON changent, le point d’entrée PHP reste intact.
- 12 combinaisons JavaScript de langue du visiteur et du contenu, exécutées sous
  Node : noms techniques, alias, paramètres et configuration reçue tardivement.
- MediaWiki 1.43.11 avec base SQLite neuve, séparément en français et anglais :
  six langues ResourceLoader (`en`, `fr`, `fr-ca`, `fr-fr`, `de`, `xx`), alias,
  résumés et validation du courriel. Des messages du namespace `MediaWiki:` sont
  réellement sauvegardés pour vérifier les personnalisations, le réordonnancement
  des paramètres et l’indépendance des noms sémantiques.
- 2 492 comparaisons de traductions conservées et 2 510 comparaisons face à Lua.
- Banc MediaWiki avec Wikidebates seul : manifeste, hooks, 13 cas de rendu et
  14 cas d’opérations natives dans les deux modes.
- Comparaison historique 0.6/0.7/0.8 : 121 cas, 726 comparaisons, une répétition
  de chronométrage pour ce contrôle de non-régression.
- Vraies extensions SMW 6.0.1, SemanticScribunto 2.3.3 et VariablesLua 1.7.0 :
  134 cas, 268 comparaisons ; HTML, JSON-LD, propriétés métier, variables et
  dépendances de cache. Écritures différées, job SMW, 18 variantes persistées,
  liens parentaux et contributions récentes restent vérifiés.

Pour reproduire les vérifications MediaWiki :

```sh
MW_INSTALL_PATH=/path/to/mediawiki php tests/mediawikiI18n.php
WD_TEST_LANGUAGE=fr MW_INSTALL_PATH=/path/to/mediawiki php tests/mediawikiI18n.php
MW_INSTALL_PATH=/path/to/mediawiki php tests/mediawiki.php
MW_INSTALL_PATH=/path/to/mediawiki WD_FULLSTACK_AUTOLOAD=/path/to/dependencies/vendor/autoload.php python3 tests/fullstack.py --php php --output /tmp/wikidebates-i18n-fullstack.json
```

L’installation isolée et les versions tierces sont décrites dans le
[rapport de validation complet](debatephp-fullstack-validation.md). Aucun
`LocalSettings.php`, serveur ou base de production n’a été utilisé ou modifié.

## Limites

Les contrôles JavaScript portent sur les fonctions de traduction et les
ressources MediaWiki réellement produites. Ils ne remplacent pas une navigation
complète dans un navigateur avec clics et formulaires. La phase de réimportation
et le compilateur sont vérifiés sur copies temporaires ; la commande de
maintenance complète avec son oracle Scribunto n’a pas été réexécutée ici.

Une valeur SEO héritée, `Argument.seo.breadcrumb_home_label`, référençait déjà
un alias absent (`@Debate.seo.breadcrumb_home_label`). Sa valeur par défaut est
préservée pour conserver les graphes comparés à l’oracle Lua. Le contrôle statique
l’identifie explicitement et refuse tout nouvel alias manquant. Le message
correspondant est maintenant accessible dans le catalogue standard pour une
correction éditoriale distincte.

Les conditions d’invalidation de caches après publication et les limites de
validation opérationnelle restent celles du rapport précédent : données actuelles,
MariaDB, caches partagés, concurrence et charge non validés. Les mesures locales
sont conservées dans le résultat du banc ; aucun gain en production n’est déduit
de cette réorganisation.
