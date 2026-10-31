# Types de liste de courses : feuille de route

[ADR 0036](../adr/0036-types-de-liste-de-courses.md) (acceptée le 2026-10-31), issue #380. Maquette : 5 écrans Claude Design (liste avec pastilles, Moi > Types de liste, un type, un produit à part, message après planification).

Un agent à la fois sur le back (une PR par ticket, fusionnée avant la suivante : migrations et `docs/api/openapi.json` se marchent dessus), le front après que le contrat du back est fusionné (`make api-update` sur le commit du back). Livraison : back, puis site (jamais les deux ensemble), l'application Android ne change pas.

| Ticket | Dépôt | Contenu | Effort | Dépend de | Statut |
|---|---|---|---|---|---|
| [LST-001](tickets/LST-001-adr-et-cadrage.md) | back | ADR 0036, `CLAUDE.md`, glossaire, skill, feuille de route | medium | — | fait |
| [LST-002](tickets/LST-002-types-de-liste.md) | back | Migration unique, types de liste (CRUD), rayons rattachés, voter, purge, suppression | xhigh | 001 | fait |
| [LST-003](tickets/LST-003-destination-des-produits.md) | back | `ListDestinationResolver`, produit à part, copie / fusion | high | 002 | fait |
| [LST-004](tickets/LST-004-plusieurs-listes-en-cours.md) | back | Une liste en cours par type, ouverture à la demande, lecture des listes ouvertes | high | 002 | fait |
| [LST-005](tickets/LST-005-ajouts-eclates.md) | back | Planifier, augmenter, diminuer, groupe de repas, retirer du plan | xhigh | 003, 004 | fait |
| [LST-006](tickets/LST-006-front-types.md) | front | Moi > Types de liste, fiche d'un type | high | 002, 003 | fait (front #288) |
| [LST-007](tickets/LST-007-front-listes.md) | front | Pastilles de listes, fiche d'un produit, message, hors ligne | high | 004, 005, 006 | fait (front #289) |
| [LST-008](tickets/LST-008-voix.md) | back | Alexa : ajouter, demander, retirer sur toutes les listes ouvertes | high | 005 | fait |
| [LST-009](tickets/LST-009-recette-et-release.md) | humain | Recette sur téléphone, releases back puis site | — | 007, 008 | fait (back v1.12.0 et site v1.15.0 déployés, recette sur le téléphone de test le 2026-10-31) |
| [LST-010](tickets/LST-010-retirer-les-pastilles-de-l-ecran-liste.md) | front | Retirer les pastilles de l'écran « Liste » : on change de liste par « Mes listes » (demande du propriétaire, 2026-10-31) | high | 007 | fait (front #294) |

## Règles qui valent pour tous les tickets

- **Zéro type = rien ne change** : les tests existants passent sans être modifiés ; un compte sans type n'a aucune requête de plus au-delà d'un budget fixé par ticket.
- Lire `CLAUDE.md`, l'ADR 0036 et les skills `repas-dev`, `repas-po` (écrans et textes), `repas-front-dev` pour le front.
- Budgets SQL (`tests/Repas/WriteBudget`) : constants quel que soit le nombre de produits ; `make test`, `make quality` verts avant la PR.
- Toute table ou colonne qui désigne un utilisateur est dans `PurgeUserData` (`UserOwnedDataCoverageTest`).
- Textes : voix de `repas-po` (tutoiement, 15 mots au plus), glossaire de `docs/drive/principes-produit.md` § 5.

## Reste à faire et limites connues (2026-10-31)

- **LST-009** : fait le 2026-10-31 (back v1.12.0, site v1.15.0, recette sur le téléphone de test avec le compte de démonstration : deux types, recette répartie sur trois listes, message « Ajouté à … », pastilles, terminer une liste, ouverture à la demande, produit à part).
- **`GET /shopping-lists/active/summary`** ne couvre que la liste principale : le front ajoute lui-même les recettes et les quantités des listes de types. Plus propre côté back : un ticket si le propriétaire le veut.
- Le champ « Liste de courses » n'est pas proposé à la **création** d'un produit (seulement à sa modification).
- Fusionner deux produits d'une même recette répartie entre deux listes peut déduire la quantité fusionnée dans les deux listes au retrait d'un repas (cas rare, non traité).
- Budget `retirer-ingredient-par-la-voix-sans-liste` : de 1 à 2 requêtes, un cas que le webhook n'atteint jamais (la lecture répond avant).
- Retirer une recette ne laisse plus de reste flottant dans une ligne (#387).
