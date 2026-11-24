# Migration SEO native des pages Debate / Argument

## Périmètre accepté (24 novembre 2026)

- Le rendu et les métadonnées des pages de débats et d'arguments doivent pouvoir fonctionner sans WikiSEO.
- Les champs SEO existants (titres, descriptions, mots-clés, images, textes alternatifs, locale, auteur, section, compte X, politique robots) restent inchangés, sauf les corrections documentées.
- Aucune image n'est créée, téléchargée, remplacée ou recherchée. L'URL d'image définie dans les messages Wikidebates est réutilisée à l'identique.
- Date de création : valeur éditoriale du paramètre `creation-date` des modèles Débat et Argument, lorsqu'elle est valide (YYYY-MM-DD). Le format horodaté avec minuit UTC est conservé par compatibilité avec le JSON-LD existant. Aucune date n'est inventée si le champ manque.
- Dernière modification : `OutputPage::getRevisionTimestamp()`, horodatage MediaWiki de la révision affichée. Aucune date actuelle de secours.
- Description des arguments : contenu du paramètre `summary` (Résumé), ramené à du texte brut pour les métadonnées HTML ; si vide, description historique construite par le moteur Argument.

## Données générées

Wikidebates sérialise les paramètres SEO dans `ParserOutput::setExtensionData()`, et les transmet vers `OutputPage` via `OutputPageParserOutput`. `BeforePageDisplay` assemble alors les balises, sans nouvelle lecture SMW ni génération d'image.

- Compatibilité : `<title>`, `description`, `keywords`, `googlebot`, `robots`, `og:title`, `og:description`, `og:url`, `og:image`, `og:image:alt`, `og:site_name`, `og:locale`, `article:author`, `article:section`, `article:tag`, `twitter:site`, `twitter:card`.
- Compléments sociaux : `twitter:title`, `twitter:description`, `twitter:image`, `twitter:image:alt`, `og:image:secure_url` pour les URL HTTPS, `og:image:type` calculé depuis l'extension d'URL, et `og:image:width` / `og:image:height` uniquement si déjà renseignées (jamais devinées).
- Dates corrigées : `article:published_time` depuis `creation-date` ; `article:modified_time` depuis la révision affichée.
- Les graphes JSON-LD spécifiques Wikidebates déjà émis par ses moteurs PHP ne sont pas modifiés.

Les types n'évoluent **pas** sans accord : `og:type=article` pour les arguments, et absence de type explicite dans les paramètres historiques des débats. L'éventuel `og:type=website` sur les débats, ainsi que les changements du graphe `QAPage` / `Claim`, doivent être discutés séparément.

## Bascule et compatibilité

Tant que WikiSEO est chargé, les propriétés traditionnelles lui sont transmises et son générateur reste seul actif. Le PHP natif stocke déjà ses données mais **n'émet aucune balise en double**. Dès que WikiSEO n'est plus chargé, la génération PHP native prend automatiquement le relais pour Débat et Argument.

**Ne pas désactiver WikiSEO tant que les usages de l'extension sur les autres pages n'ont pas été recensés et validés par le propriétaire.** En particulier, les autres espaces, les formulaires `<seo>`, les vérifications de site, les réglages globaux et les liens canoniques/hreflang peuvent dépendre de WikiSEO ou de MediaWiki.

L'activation nécessite une opération de déploiement distincte, à effectuer après approbation et vérification :

1. Comparer le serveur à la branche `main` de la ferme et vérifier les modifications locales sans rien écraser.
2. Fusionner la PR Wikidebates après CI, puis actualiser explicitement le gitlink `extensions/Wikidebates` de la ferme par une PR séparée. Ne pas mettre à jour tous les sous-modules.
3. Dans un changement coordonné de configuration **après recensement des autres pages**, désactiver `wfLoadExtension( 'WikiSEO' )` tout en conservant la configuration nécessaire à MediaWiki et aux autres extensions.
4. Purger le parser cache des pages Débat et Argument et les fichiers HTML Vector et Minerva concernés, puis reconstruire les caches via les procédures existantes, pour éviter de servir des en-têtes périmés.
5. Comparer les `<head>` FR/EN avec le comportement antérieur : titres, images exactes, types, robots, dates, nombre de balises, cartes sociales, JSON-LD ; vérifier que les pages ordinaires restent couvertes selon la politique convenue.
6. Conserver la possibilité de réactiver temporairement WikiSEO si nécessaire (l'ancien chemin de page props reste disponible tant que l'extension est chargée).

Aucune étape de serveur, cache ou configuration n'est exécutée depuis cette PR.

## Décisions en attente de validation éditoriale

- Activer explicitement `og:type=website` pour les débats, en conservant `article` pour les arguments ?
- Souhaite-t-on conserver les champs `article:*` sur les débats qui n'ont pas `og:type=article`, ou les limiter aux arguments ? Cela touche aux dates sociales.
- Faut-il convertir l'ancien `article:author` (chaîne de texte) en URL de profil Open Graph ou le supprimer ? Un compte social collectif ne représente pas automatiquement l'auteur intellectuel d'une affirmation.
- Faut-il remplacer `QAPage` par `WebPage` + `Question` pour les débats ? Le JSON-LD natif existant n'est pas modifié.
- Gestion des autres pages et de l'extension WikiSEO au niveau de la ferme avant de la désactiver.
- Relations `hreflang`, `og:locale:alternate`, `fediverse:creator`, `twitter:creator` : ne pas ajouter sans une source de vérité ou une identité attestée.

## Validation

- `php tests/run.php` : inclut `tests/seo.php` sans installation MediaWiki.
- `php tests/mediawiki.php` : transfert de données `ParserOutput` vers `OutputPage` sur MediaWiki 1.43.
- La CI sur les PR contrôle PHP 8.4, MediaWiki 1.43, JavaScript et navigateur ; elle ne remplace pas la comparaison sur le serveur réel.
