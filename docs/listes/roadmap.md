# Types de liste de courses : feuille de route

[ADR 0036](../adr/0036-types-de-liste-de-courses.md) (acceptée le 2026-10-31), issue #380. Maquette : 5 écrans Claude Design (liste avec pastilles, Moi > Types de liste, un type, un produit à part, message après planification).

Un agent à la fois sur le back (une PR par ticket, fusionnée avant la suivante : migrations et `docs/api/openapi.json` se marchent dessus), le front après que le contrat du back est fusionné (`make api-update` sur le commit du back). Livraison : back, puis site (jamais les deux ensemble), l'application Android ne change pas.

| Ticket | Dépôt | Contenu | Effort | Dépend de | Statut |
|---|---|---|---|---|---|
| [LST-001](tickets/LST-001-adr-et-cadrage.md) | back | ADR 0036, `CLAUDE.md`, glossaire, skill, feuille de route | medium | — | fait (cette PR) |
| [LST-002](tickets/LST-002-types-de-liste.md) | back | Migration unique, types de liste (CRUD), rayons rattachés, voter, purge, suppression | xhigh | 001 | à faire |
| [LST-003](tickets/LST-003-destination-des-produits.md) | back | `ListDestinationResolver`, produit à part, copie / fusion | high | 002 | à faire |
| [LST-004](tickets/LST-004-plusieurs-listes-en-cours.md) | back | Une liste en cours par type, ouverture à la demande, lecture des listes ouvertes | high | 002 | à faire |
| [LST-005](tickets/LST-005-ajouts-eclates.md) | back | Planifier, augmenter, diminuer, groupe de repas, retirer du plan | xhigh | 003, 004 | à faire |
| [LST-006](tickets/LST-006-front-types.md) | front | Moi > Types de liste, fiche d'un type | high | 002, 003 | à faire |
| [LST-007](tickets/LST-007-front-listes.md) | front | Pastilles de listes, fiche d'un produit, message, hors ligne | high | 004, 005, 006 | à faire |
| [LST-008](tickets/LST-008-voix.md) | back | Alexa : ajouter, demander, retirer sur toutes les listes ouvertes | high | 005 | à faire |
| [LST-009](tickets/LST-009-recette-et-release.md) | humain | Recette sur téléphone, releases back puis site | — | 007, 008 | à faire |

## Règles qui valent pour tous les tickets

- **Zéro type = rien ne change** : les tests existants passent sans être modifiés ; un compte sans type n'a aucune requête de plus au-delà d'un budget fixé par ticket.
- Lire `CLAUDE.md`, l'ADR 0036 et les skills `repas-dev`, `repas-po` (écrans et textes), `repas-front-dev` pour le front.
- Budgets SQL (`tests/Repas/WriteBudget`) : constants quel que soit le nombre de produits ; `make test`, `make quality` verts avant la PR.
- Toute table ou colonne qui désigne un utilisateur est dans `PurgeUserData` (`UserOwnedDataCoverageTest`).
- Textes : voix de `repas-po` (tutoiement, 15 mots au plus), glossaire de `docs/drive/principes-produit.md` § 5.
