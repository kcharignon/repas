# STORE-011 — Signaler une recette d'un autre utilisateur (back)

- **Dépôt** : back (`src/Repas/`, `src/User/` pour la purge, `docs/api/openapi.json`, migration, tests, budgets SQL)
- **Jalon** : M2 Conformité aux règles de Google Play
- **Taille** : M
- **Dépend de** : STORE-001 (**conditionnel** : seulement si D5 = B)
- **Faisable sans téléphone ni compte Play** : oui
- **Bloquant pour AUTH-002 / la connexion Google** : non (mais STORE-017 et STORE-019 en dépendent si D5 = B)
- **Statut** : à faire (conditionnel)
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 6.f ; skills `repas-dev` et `repas-drive-back` (migrations compatibles, purge) ; [ADR 0021](../../adr/0021-gestion-des-comptes-par-l-administrateur.md) §10 ; `src/Repas/Infrastructure/Loader/RecipePostgreSQLLoader.php` (`listOfOtherPeople`).

## Contexte

La règle de Google sur les contenus d'utilisateurs demande, pour une application qui en montre : des conditions acceptées avant de créer du contenu, une modération raisonnable et un moyen de **signaler et de bloquer** dans l'application. Les noms et le texte des recettes des autres sont visibles par tout compte actif ; ouvrir l'application au public rend ce contenu visible à des inconnus. Le choix minimal retenu (option B) : **signaler** une recette ; l'administrateur la retire. Pas de messagerie ni de commentaires dans Repas : le blocage d'un utilisateur est sans objet (aucune interaction directe).

## Portée

1. **Modèle** : `RecipeReport` (`id`, `recipeId`, `reporterId`, `reason`, `createdAt`) ; raison parmi une liste fermée (`OFFENSIVE`, `NOT_A_RECIPE`, `SPAM`, `OTHER`) ; une seule signalisation par couple (recette, signaleur). Table `recipe_report`, **sans clé étrangère**, clé unique `(recipe_id, reporter_id)`, migration additive compatible avec la version précédente du code ([0017](../../adr/0017-deploiement-et-releases.md) règle 6).
2. **Écriture** `POST /api/v1/recipes/{id}/report` (corps `{"reason": "…"}`), `204` ; recette inconnue : `404` ; sa propre recette : `422` ; déjà signalée par lui : `204` (idempotent) ; plafond par utilisateur.
3. **Lecture pour l'administrateur** : `GET /api/v1/recipe-reports` (`ROLE_ADMIN`, liste `{member, totalItems}` : recette, raison, nombre de signalements, dates) via un `Loader`.
4. **Retrait** : l'administrateur supprime la recette signalée par un cas d'usage de suppression par l'administrateur (`DELETE /api/v1/recipes/{id}` réservé à l'administrateur, ou son équivalent existant) ; la purge efface les signalements de la recette. Une recette retirée disparaît des listes (les copies d'autres utilisateurs deviennent originales, comme à ADR 0021 §7 ; décision à noter).
5. **Purge du compte** : `RecipeReport::class => ['reporterId']` entre dans `UserOwnedDataCoverageTest` et dans `PurgeUserData` ; budgets SQL de `supprimer-compte` et du nouveau cas d'usage mesurés et figés.
6. OpenAPI, `make openapi`, relire le diff.

## Hors portée

Le front (STORE-012) ; le blocage d'utilisateur ; la modération automatique ; les notifications à l'administrateur (il consulte la liste) ; la modification de la visibilité des recettes.

## Critères d'acceptation

- Étant donné une recette d'un autre, quand un utilisateur la signale avec une raison valide, alors `204` et un signalement existe ; le même utilisateur la signale de nouveau : `204`, un seul signalement.
- Étant donné sa propre recette ou une raison inconnue, alors `422` ; une recette inconnue, `404`.
- Étant donné un administrateur, quand il liste les signalements, alors il voit les recettes signalées regroupées ; un utilisateur ordinaire : `403`.
- Étant donné la suppression d'un compte signaleur, alors ses signalements sont effacés ; la suppression de la recette efface ses signalements.

## Tests exigés

Handlers en mémoire ; dépôt et loader sur base réelle ; controllers avec doubles de bus (clés JSON, `403`, `404`, `422`, `429`) ; parcours avec les vrais bus ; `UserOwnedDataCoverageTest` ; budgets `WriteBudget` ; `make test`, `make quality`, `make openapi-check`.

## Definition of Done

Celle du skill `repas-dev` § 4 ; ADR 0025 (décision 5e) à jour ; `note-vie-privee.md` / politique : le signalement est une donnée (signaleur, raison) conservée jusqu'à la suppression du compte ou de la recette ; `CLAUDE.md` si une convention change.

## Pièges connus

- Ne pas révéler à l'auteur **qui** a signalé.
- Une recette signalée n'est pas retirée automatiquement : un seul signalement malveillant ne doit pas pouvoir retirer la recette d'un autre.
- `listOfOtherPeople` filtre déjà les recettes dont l'utilisateur a une copie : ne pas casser cette règle.
- Le signalement est une **donnée personnelle** (qui a signalé quoi) : l'ajouter à la politique de confidentialité (STORE-010) et à la purge.
- Si D5 = A (cacher « Recettes des autres »), ce ticket est supprimé ; si D5 = C, il reste à faire avant STORE-019.
