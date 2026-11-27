# Audit préalable au retrait de Lua

L'indépendance des moteurs PHP est validée. Cela ne démontre pas que les autres
pages ou extensions des six wikis sont indépendantes de Scribunto.

## Inventaire en lecture seule

Sur la ferme, exécuter depuis la racine MediaWiki :

```bash
umask 077
reports=$(mktemp -d "${HOME}/wikidebates-lua-audit.XXXXXX")
for lang in fr en de es it pt; do
	if ! php maintenance/run.php Wikidebates:AuditLuaUsage --wiki="$lang" >"$reports/$lang.jsonl" 2>"$reports/$lang.err"; then
		printf 'Inventaire incomplet : %s\n' "$lang" >&2
	fi
done
printf '%s\n' "$reports"
```

Ne pas exécuter en root. Les rapports restent privés. Ils comportent les titres,
identifiants de page et de révision, slots, types de contenu et catégories de
signal, sans extrait de source ni contenu masqué.

Le script examine toutes les pages courantes et tous leurs slots par lots de
200 par défaut. Il ne rend pas les pages, ne crée aucune révision, n'exécute pas
les jobs, ne purge ni ne reconstruit les caches. Les lectures sont effectuées
sur la réplique configurée. Les options `--after`, `--limit`, `--batch-size`
permettent un contrôle ciblé ou la reprise. Un segment n'est pas un audit complet.
Le plafond d'identifiant de page est relevé au début : ce n'est pas un snapshot
transactionnel. En cas de modifications simultanées, répéter sur une copie stable.

Le contrôle signale aussi les liens enregistrés vers l'espace Module et le
nombre de lignes de contenu de modèle Scribunto/Lua encore présentes en base.
Ces données sont indicatives : les liens enregistrés peuvent être périmés et
les lignes de contenu ne sont pas un nombre de révisions. Une ancienne révision
ou une révision supprimée peut encore utiliser le gestionnaire Scribunto.
Ne pas supprimer ce gestionnaire, ni effacer l'historique, pour faire passer
l'audit.

## Lecture des résultats

- `candidate` : source à examiner, pas preuve d'exécution. Les commentaires,
  exemples nowiki et appels localisés sont volontairement inclus.
- `stored_module_link` : dépendance déjà enregistrée, possiblement périmée.
- `full_current_scan` : scan complet des sources courantes sans erreur ni
  contenu illisible. Ne signifie pas « Lua peut être désactivé ».
- `native_argument` et `native_debate` : appels natifs informatifs, pas des
  dépendances Lua.
- Les compteurs de `signals` comptent des slots, non des occurrences.

L'inventaire lexical ne résout pas toutes les expansions dynamiques, les
transclusions distantes, les messages par défaut d'extensions, ni les
dépendances de code/configuration hors base. Avant tout retrait :
revoir les candidats et les configurations chargées, vérifier les extensions
dépendantes, décider comment conserver l'accès aux anciennes révisions et
tester une copie des six wikis sans les extensions Lua. Ne pas déployer ni
modifier les caches dans cette étape.
