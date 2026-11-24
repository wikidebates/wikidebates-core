# Migration SEO native des pages Debate / Argument

## Périmètre accepté (24 novembre 2026)

- Le rendu et les métadonnées des pages de débats et d'arguments doivent pouvoir fonctionner sans WikiSEO.
- Les champs SEO existants (titres, descriptions, mots-clés, images, textes alternatifs, locale, auteur, section, compte X, politique robots) restent inchangés, sauf les corrections documentées.
- Aucune image n'est créée, téléchargée, remplacée ou recherchée. L'URL d'image définie dans les messages Wikidebates est réutilisée à l'identique.
- Date de création : valeur éditoriale du paramètre `creation-date` des modèles Débat et Argument, lorsqu'elle est valide (YYYY-MM-DD). Le format horodaté avec minuit UTC est conservé par compatibilité avec le JSON-LD existant. Aucune date n'est inventée si le champ manque.
- Dernière modification : `OutputPage::getRevisionTimestamp()`, horodatage MediaWiki de la révision affichée. Aucune date actuelle de secours.
- Description des arguments : contenu du paramètre `summary` (Résumé), ramené à du texte brut pour les métadonnées HTML ; si vide, description historique construite par le moteur Argument.

## Données générées

Wikidebates sérialise les paramètres SEO dans `ParserOutput::setExtensionData()`, et les transmet vers `OutputPage` via `OutputPageParserOutput`. `BeforePageDisplay` définit le titre et la politique d'indexation, sans nouvelle lecture SMW ni génération d'image. Si WikiSEO n'est pas installé, les balises natives sont émises directement. Si WikiSEO est chargé, le hook final `OutputPageAfterGetHeadLinksArray` remplace uniquement les balises SEO concurrentes de cette page, **sans toucher aux pages ordinaires**, aux balises de vérification de domaine, aux URL canoniques et aux liens `hreflang`. Le hook facultatif `WikiSEOPreAddMetadata` rétablit le titre natif si l'ordre des hooks de `BeforePageDisplay` est inversé.

- Compatibilité : `<title>`, `description`, `keywords`, `googlebot`, `robots`, `og:title`, `og:description`, `og:url`, `og:image`, `og:image:alt`, `og:site_name`, `og:locale`, `twitter:site`, `twitter:card`.
- Types Open Graph approuvés : `og:type=website` pour les débats et `og:type=article` pour les arguments.
- Champs réservés aux **arguments** : `article:section`, `article:published_time`, `article:modified_time` et `article:tag` (une balise distincte par mot-clé). `article:author` supprimé : une étiquette collective ne constitue pas l'URL de profil attendue pour un auteur Open Graph.
- Compléments sociaux : `twitter:title`, `twitter:description`, `twitter:image`, `twitter:image:alt`, `og:image:secure_url` pour les URL HTTPS, `og:image:type` calculé depuis l'extension d'URL, et `og:image:width` / `og:image:height` uniquement si déjà renseignées (jamais devinées).
- Dates corrigées : pour les arguments, `article:published_time` depuis `creation-date` ; `article:modified_time` depuis la révision affichée. Pour les débats, les dates sont portées par le graphe JSON-LD.
- Graphes JSON-LD Wikidebates : les types spécialisés `QAPage`, `Question` et `Claim` sont conservés. L'entité page (`QAPage` pour Débat, `WebPage` pour Argument) reçoit `author` de type `Organization` nommée « Contributeurs de Wikidébats » (FR) / « Wikidebates contributors » (EN), ainsi que `creditText` avec exactement le même texte. Elle reçoit également `datePublished` depuis `creation-date` et `dateModified` depuis le `RevisionRecord` réellement analysé (même lors du rendu d'une ancienne révision), avant que `ContentRenderer` ne renseigne l'horodatage de `ParserOutput`. Le rendu est déclaré dépendant de l'horodatage de révision pour que MediaWiki invalide un rendu préparatoire si la date enregistrée diffère. Le `publisher` pointe vers l'objet `Organization` Wikidebates déjà présent. Les contributeurs ne sont pas présentés comme auteurs intellectuels du `Claim`.
- Traductions : réutiliser **sans dupliquer** les messages i18n existants `wikidebates-debate-seo-author` (`i18n/fr.json` et `i18n/en.json`), et l'alias Argument `@Debate.seo.author`. Aucun nouveau message nécessaire. Ce champ `author` Schema.org est indépendant de `article:author` Open Graph, qui reste supprimé.

Ces décisions sur les types et les crédits ont été approuvées le 24 novembre 2026. Le remplacement de `QAPage` et les autres évolutions sémantiques non approuvées restent hors périmètre.

## Bascule et compatibilité

WikiSEO **reste chargé et actif sur toutes les autres pages** : les modèles et balises `<seo>`, les pages de projet, les vérifications de domaine, les métadonnées globales, le comportement des images de secours et les règles de canonical/hreflang ne sont pas modifiés. Le cas particulier Débat/Argument est déterminé par un marqueur natif dans `ParserOutput`, et non par le titre de la page ou une liste d'espaces de noms.

Sur ces deux types de pages, Wikidebates est la source unique des balises SEO visibles. Son moteur n'appelle ni `WikiSEO\\Validator`, ni les hooks Lua de WikiSEO, ni les `page_props` SEO historiques. Les balises génériques que WikiSEO génère encore au niveau de la requête sont neutralisées dans le `<head>` final ; le JSON-LD spécialisé de Wikidebates, déjà inclus dans le HTML du contenu, reste intact. Les anciens fichiers ParserCache ne possédant pas le marqueur continuent provisoirement à fonctionner avec WikiSEO, jusqu'à leur reconstruction.

**État déployé et contrôlé le 24 novembre 2026 :** ferme `953d3e240350158ff4624ef150b0c7afaf48f86a`, Wikidebates `2781d8a38e8a336ff4a397128d28bd6b121f7b44`, WikiSEO `3f52f9d7d196bcaed541201f4412f4bf1e6b71e1`. Le fork WikiSEO modernisé expose les hooks `WikiSEOPageMetadataMode` et `WikiSEOPageRevisionMode`. Wikidebates leur indique `global-only` pour les pages possédant le marqueur natif `wikidebates-native-seo` (`wd_kind=debate` ou `wd_kind=argument`).

Ce mode arrête les générateurs de métadonnées de page de WikiSEO **avant leur exécution** (Open Graph, X/Twitter, JSON-LD, titre, canonical spécifique et champs SEO persistés), tout en conservant les balises techniques globales non concurrentes (vérification de domaine, hreflang configuré et protections robots). Les autres pages conservent automatiquement le mode `full`. Le fork permet aussi `off` pour couper absolument tout WikiSEO sur une page.

**Compatibilité descendante :** si l'ancienne version de WikiSEO est encore installée, ces hooks ne sont simplement pas appelés et le remplacement sélectif du `<head>` final continue de fonctionner comme auparavant. Cette compatibilité reste utile pour les installations utilisant l'ancien WikiSEO. Le fork modernisé est désormais épinglé dans la ferme.

La migration est activée sur la ferme. Pour une future modification des générateurs SEO, appliquer les précautions de déploiement suivantes :

1. Comparer le serveur à la branche `main` de la ferme et vérifier les modifications locales sans rien écraser.
2. Fusionner la PR Wikidebates après CI, puis actualiser explicitement le gitlink `extensions/Wikidebates` de la ferme par une PR séparée. Ne pas mettre à jour tous les sous-modules.
3. **Conserver** `wfLoadExtension( 'WikiSEO' )` et sa configuration de production inchangés. Cette PR ne dépend pas du déploiement de la PR de modernisation indépendante de WikiSEO.
4. Sur un environnement de validation avec WikiSEO réellement chargé, comparer une page Débat, une page Argument (avec et sans résumé / image), une page ordinaire, et une page avec `<seo>` manuel. Contrôler le `<title>`, Open Graph, X, JSON-LD, les URL canoniques, les liens `hreflang` et les balises de vérification, sans doublons.
5. Après déploiement coordonné, invalider les sorties ParserCache et les caches HTML Vector et Minerva des pages Débat/Argument concernées, puis reconstruire selon les procédures existantes, sans vider arbitrairement toute la ferme.
6. Comparer les en-têtes FR/EN servis aux anonymes sur le serveur et via Cloudflare. En cas de régression, revenir au commit et au gitlink précédents de Wikidebates et reconstruire les caches concernés ; WikiSEO reste installé pendant toute l'opération.

### Contrôles de production effectués le 24 novembre 2026

Sur un débat, un argument et la page d'accueil de chacun des wikis FR et EN : HTTP 200, URL canonique unique, aucun doublon détecté. Les quatre pages spécialisées ont le mode `global-only` à l'affichage et pour les mises à jour de révision, un unique script JSON-LD natif, et une date de modification identique à celle de leur révision. Les deux pages d'accueil gardent le mode `full`. L'accueil FR ne fournit pas les mêmes métadonnées que l'accueil EN ; ce constat ne prouve pas une régression de la migration et reste hors périmètre.

Ces contrôles ponctuels ne prouvent pas que tous les fichiers HTML historiques sont renouvelés. Les tests d'invalidation ciblée existent déjà dans `tests/seoCacheInvalidation.php`. Ne pas lancer de reconstruction globale supplémentaire uniquement pour cette mise à jour documentaire et CI.

## Décisions en attente de validation éditoriale

- Faut-il remplacer `QAPage` par `WebPage` + `Question` pour les débats ? Le JSON-LD natif existant n'est pas modifié. Le générateur limite actuellement `suggestedAnswer` à trois arguments de chaque côté et calcule `answerCount` sur cette sélection, pas sur l'ensemble des arguments. Google demande que le compteur représente le total des réponses et que les réponses balisées contiennent leur texte complet ([documentation QAPage](https://developers.google.com/search/docs/appearance/structured-data/qapage)). Vérifier la correspondance avec les arguments effectivement affichés et décider de la sémantique avant toute modification. Ne pas inventer de réponse acceptée, de vote ou de texte.
- Relations `hreflang`, `og:locale:alternate`, `fediverse:creator`, `twitter:creator` : ne pas ajouter sans une source de vérité ou une identité attestée.

## Validation

- `php tests/run.php` : inclut `tests/seo.php` sans installation MediaWiki.
- `php tests/mediawiki.php` : transfert de données `ParserOutput` vers `OutputPage` sur MediaWiki 1.43.
- La CI sur les PR contrôle PHP 8.4, MediaWiki 1.43, JavaScript et navigateur. Un test supplémentaire sous PHP 8.4 charge le **vrai WikiSEO 2.7.0 épinglé**, vérifie le remplacement sélectif des balises et l'absence de régression sur les autres pages. Le workflow `wikiseo-page-optout.yml` utilise en outre le commit exact du fork WikiSEO déployé le 24 novembre 2026, indépendamment de toute branche de développement temporaire. Actualiser cette référence lors d'un changement du gitlink de production. Ces tests ne remplacent pas la comparaison sur le serveur réel.
