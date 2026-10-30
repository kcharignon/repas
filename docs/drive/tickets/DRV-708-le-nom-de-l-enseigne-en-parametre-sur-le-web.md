# DRV-708 — Le web : le nom de l'enseigne en paramètre, plus de littéral `leclerc`

- **Dépôt** : front (`src/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-701 (contrat des enseignes et lecture `retailer` de `GET /drive/settings`)
- **Statut** : fait (PR front #248, 2026-10-30). Effort recommandé : `medium` (texte et alignement).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 3.3, 5.3 ; [`../principes-produit.md`](../principes-produit.md) ; skills `repas-front-dev` et `repas-po` ; DRV-621, 627 (glossaire et voix), DRV-628 (conditions d'utilisation) ; `src/i18n/__tests__/voice-and-glossary.spec.ts`.

## Contexte

Le web écrit « Leclerc » dans 44 lignes de `src/i18n/fr.ts` (erreurs, récapitulatif, envoi, réglages, aide, conditions d'utilisation), fige `DRIVE_HOME_URL` et `finalizeLink` dans `src/features/drive/leclerc.ts`, la règle d'adresse dans `storeAddress.ts`, et écrit `retailer: 'leclerc'` cinq fois dans les appels d'API (`queries.ts` l. 65 et 82, `choicesQueries.ts` l. 19 et 56, `choiceDraft.ts` l. 84).

## Portée

1. **Registre du web** `src/features/drive/retailers.ts` : pour chaque enseigne, `id`, `name`, `nameDe`, l'accueil du drive (repli de « Finaliser »), la règle d'adresse du magasin (`parseStoreAddress` la lit) ; son contenu est celui du contrat `retailers.sample.json` (test de concordance). `leclerc.ts` y est absorbé.
2. **Textes** : chaque « Leclerc » de `fr.ts` devient `{retailer}` ; « de Leclerc » devient `{retailerDe}` ; les clés ne se lisent plus comme propres à Leclerc. Les conditions d'utilisation gardent une **section par enseigne** (`terms.retailers.<id>`) et la partie commune est réécrite sans nom d'enseigne.
3. **Appels d'API** : l'enseigne vient de `useDriveSettings().data.retailer` (champ existant, sélection de DRV-703 plus tard) ; plus aucun littéral `'leclerc'` hors du registre.
4. **Test du glossaire** : échoue si le nom d'une enseigne (« Leclerc », « Auchan », casse ignorée) apparaît dans `fr.ts` hors de `retailers`/`terms.retailers` et des tests ; échoue si « enseigne » ou « retailer » apparaît dans un texte visible.
5. Les tests qui attendent le mot « Leclerc » passent aux valeurs du registre.

## Hors portée

Choisir une enseigne, afficher plusieurs enseignes (DRV-709, 710), l'application et l'agent, un texte propre à Auchan.

## Critères d'acceptation

- Étant donné Leclerc seul, alors **tous les textes rendus et tous les appels d'API (corps et paramètres) sont identiques à ceux d'avant** ; captures Chromium mobile et bureau de l'écran d'envoi, des réglages, du retour et des conditions d'utilisation **identiques** (lues).
- Étant donné une enseigne fictive dans un test, alors le récapitulatif dit « dans ton panier {nom} » et « Se déconnecter {nomDe} » correctement.
- Un grep du dépôt ne trouve plus `retailer: 'leclerc'`.

## Tests exigés

Vitest (registre, élision, glossaire, concordance avec le contrat), tests existants ajustés, Playwright (Pixel 7 et bureau) inchangés, captures lues, `make check`.

## Definition of Done

Celle du dépôt front : `make check` vert, couverture non abaissée, types de l'API générés si le contrat a bougé (`npm run api:update` avec la référence du back), textes dans `fr.ts`, test visuel, `CLAUDE.md` du front à jour (règle « ne pas écrire le nom d'une enseigne hors du registre »), voix et glossaire (`principes-produit.md` § 6) répondus dans « À relire ».

## Pièges connus

- L'élision (« de Leclerc » / « d’Auchan ») ne se construit pas par concaténation.
- Les conditions d'utilisation sont lues comme un texte unique par les proches : ne pas les couper en morceaux qui se contredisent ; la section de chaque enseigne ne dit que ce qui lui est propre (suspension de compte, programme de fidélité).
- Ne pas changer un mot du glossaire : ce ticket ne change aucun texte, il remplace un nom par un paramètre.
