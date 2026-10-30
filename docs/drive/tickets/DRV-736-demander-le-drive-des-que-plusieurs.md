# DRV-736 — Demander le drive dès que plusieurs sont configurés

- **Dépôt** : back (`src/Drive/Domain/Service/RetailerSelection.php`, descriptions de `docs/api/openapi.json`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-703
- **Statut** : fait (2026-10-30). Pendant front : DRV-737 (fait par l'assistant en parallèle). Effort recommandé : `medium`.
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) § 5 ; [ADR 0027](../../adr/0027-plusieurs-enseignes-de-drive.md) (amendement du 2026-10-30).

## Contexte

Décision du propriétaire (2026-10-30) : « Un utilisateur qui n'a configuré qu'un seul drive, quand il envoie au drive il n'a pas le choix. Un utilisateur qui a configuré plusieurs drives choisit, au moment d'envoyer, à quel drive il envoie. Un utilisateur qui n'a configuré aucun drive ne voit pas le bouton « Envoyer au drive ». » Elle remplace la règle R3 (« `AUTO` sur le drive habituel ») et le parcours R1 d'origine. Sans drive configuré, le bouton « Envoyer au drive » est masqué, et le menu additionnel « ⋮ » de la liste en cours affiche une entrée « Configurer un drive » qui mène à l'écran où l'on choisit quel drive configurer (aujourd'hui Auchan ou Leclerc ; une seule enseigne allumée : directement à la lecture de son magasin). L'écran d'envoi garde « Choisir mon magasin » comme filet de sécurité quand on y arrive directement. C'est le front (DRV-737) qui le fait ; côté back, `selection.mode = NONE` suffit à le déclencher, rien à changer dans l'API.

## Portée

1. `RetailerSelection::resolve` : 0 candidate `NONE` ; 1 candidate `AUTO` ; **2 candidates ou plus toujours `ASK`**, jamais `AUTO`. Présélection : le drive habituel s'il est candidat (même quand la dernière utilisée est une autre), sinon la dernière utilisée si elle est candidate, sinon la première configurée. Une enseigne éteinte n'est jamais candidate ; un drive habituel éteint est ignoré.
2. Tests : table `RetailerSelectionTest`, loader des réglages, parcours de bout en bout (`DriveRetailerPreferenceApiFlowTest`).
3. Descriptions OpenAPI du mode, de l'enseigne présélectionnée et du drive habituel ; `make openapi`.
4. Documentation : `multi-enseignes.md` § 5 (R1 à R5), ADR 0027 (amendement), `architecture.md`, skill `repas-drive-back`, `principes-produit.md`, `CLAUDE.md`.

## Hors portée

Aucun changement de forme de l'API : le champ `defaultRetailer`, `isDefault`, la route `PUT /drive/default-retailer` et la table `drive_retailer_preference` restent ; pas de migration. L'écran est le ticket front **DRV-737** : bouton « Envoyer au drive » masqué sans drive configuré (entrée « Configurer un drive » dans le menu « ⋮ » de la liste en cours), tuiles toujours affichées avec plusieurs drives (drive habituel coché), lien « Envoyer ailleurs » retiré.

## Critères d'acceptation

- Un seul drive configuré (cas de tous les utilisateurs d'aujourd'hui) : `AUTO`, mêmes réponses qu'avant, même avec un drive habituel ou une dernière enseigne qui désignent une enseigne éteinte.
- Deux drives configurés et un drive habituel : `ASK`, présélection = le drive habituel, même si la dernière utilisée est l'autre.
- Deux drives configurés, drive habituel éteint ou absent : `ASK`, présélection = la dernière utilisée si elle est candidate, sinon la première configurée.
- Aucun drive configuré : `NONE` (le front masque le bouton et propose « Configurer un drive » dans le menu « ⋮ »).
- `make test` et `make quality` verts, budget SQL inchangé.
