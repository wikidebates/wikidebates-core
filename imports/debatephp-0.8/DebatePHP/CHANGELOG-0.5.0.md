# DebatePHP 0.5.0

- Fusion du portage Argument de la première archive et du rendu natif Debate de la seconde.
- Conservation des fonctions `#debate:render` et `#argument:render`, des réglages, traductions et outils de maintenance.
- Rendu PHP des boutons et du JSON-LD d’Argument par le code natif partagé, sans extension HTML Tags obligatoire.
- Date par appels structurés aux variables du parseur ; bouton d’initialisation via le callback PHP Page Forms ; description via le callback SHORTDESC.
- Chemin direct pour les citations et contenus de balises littéraux ; conservation des développements de vrai wikicode.
- Tests différentiels supplémentaires sur un MediaWiki 1.43 local, avec et sans HTML Tags et avec Variables/VariablesLua.

Installation et résultats : `docs/fusion-0.5.0.md`.
