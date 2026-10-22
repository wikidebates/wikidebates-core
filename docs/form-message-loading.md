# Messages des formulaires chargés à la demande

Le module commun `ext.wikidebates.common.i18n` conserve les 21 messages utilisés
pour la lecture et la navigation. Les 71 messages `wk-summary-*`, y compris les
alias historiques, sont maintenant déclarés sur
`ext.wikidebates.features.editForm`. Ce module était déjà chargé uniquement sur
les formulaires : aucune nouvelle requête de module ni attente supplémentaire
n'est ajoutée.

ResourceLoader installe les messages d'un module avant d'exécuter ses scripts.
Les résumés de modification sont donc disponibles dès l'initialisation du
formulaire, avec leurs paramètres et leurs traductions inchangés. Le module
de formulaire utilise la même classe `I18nModule` que le module commun, afin de
conserver la normalisation des variantes `fr-ca`, `fr-fr` et `en-*`.

Les modules historiques `ext.wikidebates.i18n.fr` et
`ext.wikidebates.i18n.en` gardent la liste complète des 92 messages pour les
consommateurs qui les chargent explicitement. Le catalogue central, les alias
et les possibilités de personnalisation dans l'espace MediaWiki sont conservés.
Un autre script qui utilise un résumé doit déclarer sa propre dépendance ou ses
messages, au lieu de compter sur leur chargement implicite en lecture.

`tools/buildI18n.php` génère les deux listes à partir du catalogue existant ;
`--check` vérifie leur actualité. Les contrôles Python vérifient aussi que les
messages utilisés par chaque script sont disponibles via son module et ses
dépendances. Les contrôles JavaScript simulent le chargement lecture puis
formulaire, dans les 12 combinaisons de langues déjà couvertes. Le banc
`tests/mediawikiI18n.php` contrôle les deux blobs ResourceLoader lorsqu'un
laboratoire MediaWiki est disponible.

Ce changement réduit les messages chargés en lecture de 92 à 21. Il ne chiffre
pas un gain de latence en production : la compression et le cache ResourceLoader
influencent le volume effectivement transféré.
