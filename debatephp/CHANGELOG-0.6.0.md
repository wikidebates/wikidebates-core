# DebatePHP 0.6.0

- JSON-LD émis directement en PHP pour Debate et Argument, sans #tag/htmltag ni repli.
- Suppression des protections de syntaxe wiki dans les scripts ; conservation des caractères réels dans les valeurs JSON et protection des fermetures de script.
- Les 13 champs Debate évitent une seconde expansion lorsqu’ils contiennent des données littérales, comme les citations d’Argument.
- Conservation des expansions réellement dynamiques pour maintenir les cas de modèles imbriqués et de références.
- Nouveaux contrôles des données JSON-LD et des chemins littéraux/dynamiques ; tests des deux moteurs relancés.

Voir docs/direct-0.6.0.md. Les modèles existants #debate:render et #argument:render restent inchangés.
