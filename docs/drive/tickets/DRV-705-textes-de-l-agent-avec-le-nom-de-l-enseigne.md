# DRV-705 — Textes de l'agent : le nom de l'enseigne en paramètre

- **Dépôt** : front (`drive-agent/src/ui/`, `drive-agent/src/diagnostic/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-704
- **Statut** : fait (PR front #249, 2026-10-30, agent 0.46.0 ; `photo-url.ts` reste à rendre propre à chaque enseigne par DRV-717). Effort recommandé : `medium` (texte).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 3.4, 5.3 ; [`../principes-produit.md`](../principes-produit.md) (voix, glossaire, erreurs en trois phrases) ; skills `repas-po` et `repas-agent-dev` ; DRV-627 (tutoiement), DRV-621 (test du glossaire).

## Contexte

`drive-agent/src/ui/strings.ts` (26 lignes) et le diagnostic disent « Leclerc » en dur : « Tes produits sont dans ton panier Leclerc », « Leclerc demande une vérification », « Connecte-toi à Leclerc »… Avec une deuxième enseigne, l'agent dirait « Leclerc » sur une page d'Auchan.

## Portée

1. Les textes de `strings.ts` deviennent des fonctions du **nom de l'enseigne** (`name`, et `nameDe` pour « de Leclerc » / « d’Auchan »), fournis par l'entrée de registre de l'enseigne active (DRV-704). Le nom ne se lit jamais dans le message du plan.
2. Le diagnostic nomme l'enseigne par son identifiant et son nom (les sondes restent propres à l'adaptateur).
3. Un seul registre de noms pour l'agent ; le nom est le même que celui du contrat des enseignes (DRV-701).
4. Le test du glossaire de l'agent échoue si « Leclerc » (ou un autre nom d'enseigne) est écrit dans un texte hors du registre.

## Hors portée

Changer un mot, un ton ou un parcours (le glossaire et la voix ne bougent pas) ; les textes du web (DRV-708) et de l'application (DRV-707).

## Critères d'acceptation

- Étant donné l'enseigne `leclerc`, alors **tous les textes rendus sont identiques, caractère pour caractère,** à ceux d'avant (test d'égalité sur l'ensemble des chaînes de `strings.ts`).
- Étant donné une enseigne fictive « Test » de nom « d’Exemple » dans un test, alors les textes qui disent « de … » élident correctement.
- Aucun texte ne contient « enseigne » ni « retailer ».

## Tests exigés

Test d'égalité des chaînes (instantané lisible, pas un snapshot opaque), test d'élision, test du glossaire, `make agent-verify`, `make check`. Captures de l'agent relues si un écran change (il ne doit pas).

## Definition of Done

Celle du dépôt front ; voix et glossaire respectés (`voice-and-glossary.spec.ts`) ; aucune publication ; empreinte de l'agent mise à jour.

## Pièges connus

- L'élision : « de Leclerc » mais « d’Auchan » ; ne pas construire « de » + nom par concaténation.
- Les textes de l'agent s'affichent dans la page de l'enseigne (Shadow DOM, 360 px) : ne pas allonger une phrase.
