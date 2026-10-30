# DRV-722 — `offerId` facultatif dans le plan et le résultat (protocole, agent, web)

- **Dépôt** : front (`drive-agent/protocol/`, `drive-agent/src/`, `src/features/drive/`, `android/core` si le pont valide le plan)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-704 (registre et protocole par enseigne), DRV-721 (le plan du back porte `offerId`)
- **Statut** : fait (PR front #251, 2026-10-30, agent 0.47.0). `offerId` est refusé sur un produit refusé, sur `replaces` et sur une ligne `ADDED` ; le front fige le back à `78d51f21` (à relever par DRV-727). Effort recommandé : `high`.
- **Lire d'abord** : [`../spikes/DRV-715-decision-auchan.md`](../spikes/DRV-715-decision-auchan.md) § 2 point 3 ; [`../consignes-agents.md`](../consignes-agents.md) (« Modifier le plan ou un message du protocole », « Publier un changement de protocole », incident du 2026-10-20) ; `drive-agent/protocol/README.md` ; skills `repas-agent-dev`, `repas-front-dev`, `repas-android-dev` (si `DrivePlan.kt` valide le plan).

## Portée

1. **Schéma et validateurs** : `offerId` **facultatif** (chaîne bornée à 40 caractères, motif de l'enseigne du message) dans le produit du plan (connu et proposé) et dans le produit lu du résultat ; exemples valides et invalides ; `plan.schema.json` et `protocol/plan.ts`, `types.ts`.
2. **Web** : `buildPlanMessage` recopie le champ **champ par champ** (liste blanche, jamais l'objet du back en bloc) ; le retour l'enregistre dans le corps de `PUT /drive/choices` (`offerId` facultatif).
3. **Agent** : `Candidate` et `Content` portent `offerId` (absent pour Leclerc) ; aucun changement de comportement.
4. **Application** : si `DrivePlan.kt` (ou un autre contrôle du pont) rejette les clés inconnues d'un produit, le laisser passer `offerId` ; sinon ne rien changer.
5. **Contrat** : l'exemple du plan avec `offerId` (DRV-721) est recopié (`make samples-update`) et validé contre le schéma ; le test de concordance (`contract.spec.ts`) le rejoue.

## Hors portée

L'entrée d'Auchan (DRV-716, 727, 728), l'adaptateur (DRV-717, 724, 725).

## Critères d'acceptation

- Étant donné un plan de Leclerc, alors rien ne change (exemples, empreinte de l'agent hors table des champs, textes).
- Étant donné un plan d'une enseigne de test avec `offerId`, alors le web l'envoie, l'agent le reçoit et le résultat le rend.
- Un récepteur ancien ne reçoit jamais `offerId` : seules les enseignes qui ont une offre (Auchan, éteint) le portent, et le web ne leur envoie un plan que si l'application les annonce.

## Tests exigés

`make agent-verify`, `make check`, `make android-test` si le Kotlin change, test de contrat.

## Definition of Done

Celle du dépôt front ; **dire dans le rapport qu'il faut publier le front avant l'application Android** ; aucune étiquette ; dire ce qu'il faut cocher dans le back.

## Pièges connus

- Un récepteur qui ne connaît pas un champ **rejette le message entier** : ne jamais ajouter une clé obligatoire.
- Le résultat ne gagne **aucun** champ obligatoire (`multi-enseignes.md` § 8 point 4) : `offerId` y est facultatif.
