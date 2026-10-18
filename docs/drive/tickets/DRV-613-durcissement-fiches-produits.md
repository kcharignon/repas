# DRV-613 — Durcissement des fiches de produits partagées et plafonds

- **Dépôt** : back (partie 1) puis front `drive-agent/` (partie 2)
- **Jalon** : M2c Durcissement (revue de sécurité du back, constat C1, C2, C3)
- **Taille** : M
- **Dépend de** : DRV-204, DRV-305, DRV-306 (faits)
- **Statut** : à faire
- **Lire d'abord** : [`../revue-securite-back.md`](../revue-securite-back.md) (C1, C2, C3), [`DRV-204`](DRV-204-produits-et-choix.md), [`DRV-305`](DRV-305-orchestrateur-phase-a.md), [`DRV-306`](DRV-306-choix-guide-phase-b.md), skills `repas-drive-back` / `repas-agent-dev`, `docs/drive/consignes-agents.md`.

## Contexte

Les fiches `drive_product` sont publiques et communes à tous les utilisateurs du même magasin : n'importe quel utilisateur peut réécrire le libellé, le contenu et le prix du produit d'un autre (constat C1 : un contenu falsifié change le nombre de produits du plan d'un autre utilisateur). Les inscriptions sont ouvertes. Décision de l'assistant (2026-10-18) : défense en profondeur plutôt qu'une migration lourde « données par utilisateur ».

## Portée — partie 1 (back)

1. **Premier écrivain gagnant pour l'identité du produit** : une écriture venant d'un `ADD_PRODUCT` ou d'un `REJECT_PRODUCT` ne réécrit **ni le libellé, ni le contenu, ni le code-barres** d'une fiche déjà connue ; elle ne remplit que les champs encore vides. Le prix et sa date ne se mettent à jour que par `REFRESH_PRICE` et par l'`ADD_PRODUCT` de l'utilisateur qui accepte le produit (déjà accepté par lui). Tests : un second utilisateur n'altère pas la fiche du premier (preuve du constat C1 rejouée, rouge avant correction).
2. **Plafonds par utilisateur** (constat C2) : au plus 1 000 préférences (ingrédients) par utilisateur et par magasin, 5 000 écritures de produits par jour et par utilisateur ; au-delà `422 DRIVE_LIMIT_REACHED` ; les valeurs sont des constantes documentées ; tests.
3. **`reopen` permis interrupteur coupé** (constat C3) : `POST /shopping-lists/{id}/reopen` reste disponible même si `DRIVE_ENABLED` est faux (une liste figée par un retour arrière peut être rouverte) ; `send` reste derrière l'interrupteur ; mettre à jour l'ADR 0023 (paragraphe sur le déploiement), `docs/production.md`, `docs/drive/runbook.md`, le skill `repas-ops` (`references/drive.md`) et `architecture.md` ; tests.
4. `docs/api/openapi.json` régénéré, budgets, `make test` et `make quality` verts.

## Portée — partie 2 (front, `drive-agent/`)

5. **Le contenu en direct fait foi** : en phase A, l'agent relit le produit sur le site (il le fait déjà pour le stock) et **recalcule le nombre de produits** à partir du contenu lu en direct et de `needed` (comme en phase B, `packsFor`) quand les deux sont disponibles ; si le nombre diffère de celui du plan, il utilise le calcul en direct et le signale dans le récapitulatif (« contenu différent de celui mémorisé »). Sans `needed`, comportement actuel. Tests (jsdom, faux site), `make agent-verify`, `make android-agent-hash`.

## Hors portée

Données de produits par utilisateur (migration), activation des comptes par un administrateur (décision de l'utilisateur).

## Critères d'acceptation

- Un second utilisateur qui renvoie l'identifiant d'un produit connu avec un autre libellé, un autre contenu ou un autre prix ne modifie ni la fiche ni le plan du premier.
- Au-delà des plafonds, `422 DRIVE_LIMIT_REACHED`.
- `reopen` répond `204` interrupteur coupé pour une liste `SENT`.
- Avec un contenu différent en direct, l'agent met la quantité calculée sur le contenu réel.

## Definition of Done

Celle du dépôt concerné ; PR concises ; fusion si tous les contrôles de la CI sont verts.
