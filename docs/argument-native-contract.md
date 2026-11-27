# Contrat de rendu Argument sans Lua

Le fichier `debatephp/tests/fixtures/argument-contract.json` fige les 87 cas du dernier comparateur historique Argument. Il est indépendant du corpus de mesure `argument-cases.json`, dont le contenu n'est pas strictement identique au générateur de l'ancien comparateur.

Provenance : Core `ad37f75b2257b3bf0cb16d47ecf67fd20383a26e` ; capture du comparateur validée sur `e0062017ddb7eb79e3e3e33b50e9a5d8022a4f8b`  ; job GitHub Actions `140615961578` (identifiant dans le JSON).

Les 87 comparaisons Lua/PHP ont réussi avant la capture. Les entrées et les réponses simulées aux requêtes SMW sont conservées avec la date de publication fixe. Les empreintes SHA-256 contrôlent séparément le texte complet produit (y compris le JSON-LD), les prétraitements, les lectures et écritures sémantiques, les métadonnées SEO et les variables. L'encodage JSON ordonne les clés des objets, sans modifier les chaînes ni les tableaux ordonnés. Les empreintes attendues ne sont pas régénérées par les tests.

```sh
python3 debatephp/tests/argument_contract.py php
```

Les tests d'intégration MediaWiki/SMW avec les oracles Lua restent provisoirement inchangés. Toute évolution intentionnelle d'une sortie figée doit être examinée et documentée avant la modification du contrat.
