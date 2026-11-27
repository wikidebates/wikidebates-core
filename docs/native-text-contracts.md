# Contrats natifs de texte et de traduction

Les comparateurs historiques ont été exécutés une dernière fois sur le Core `ad37f75b2257b3bf0cb16d47ecf67fd20383a26e`, via le commit de capture `a03bad3cf369c4403f3e9eb43bf75ec5d187b188` (job GitHub Actions `140616831443`). Les 3 301 cas de texte et de données sérialisées et les 1 255 cas de traduction pour chacune des deux politiques de mémorisation ont réussi.

- `serialized-contract.json` fige le nombre de cas et une empreinte par groupe de méthodes (23 groupes). Le générateur déterministe utilise les mêmes entrées qu'auparavant : cas limites, Unicode, séparateurs, champs vides et valeurs scalaires. Chaque empreinte couvre les entrées ordonnées et les résultats exacts.
- `i18n-contract.json` conserve les requêtes et les valeurs attendues de l'oracle Lua, avec les corrections historiques déjà approuvées. Les deux politiques de mémorisation restent comparées, pour vérifier la transparence du cache.

Ces contrats ne sont pas régénérés lors des tests. Une évolution intentionnelle des traductions ou du comportement requiert une modification examinée et documentée des références. Les noms historiques des méthodes ne désignent plus de code Lua exécuté.

```sh
python3 debatephp/tests/serialized_contract.py php
python3 debatephp/tests/i18n_contract.py php
```

Les oracles du banc MediaWiki/SMW réel et les outils d'import historique font l'objet d'une migration distincte.
