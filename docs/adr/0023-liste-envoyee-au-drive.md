# 0023 — Liste envoyée au drive : statut `SENT` et verrou

- **Statut** : Proposed (passe `Accepted` avec le ticket DRV-101)
- **Date** : 2026-10-17
- **Liens** : [0002](0002-modele-domaine-riche.md), [0007](0007-api-rest-v1.md) §11 et §12, [0013](0013-agregats-references-par-identifiant.md), [0016](0016-controle-de-version-etag-if-match.md), [0017](0017-deploiement-et-releases.md) règle 6, [0022](0022-remplissage-du-panier-drive.md), [`docs/drive/architecture.md`](../drive/architecture.md) § 4

## Contexte

Une liste envoyée au drive ne doit plus être modifiable : elle devient le relevé de ce qui a été commandé, et le plan donné à l'agent doit rester stable pendant l'envoi.

Faits (code lu le 2026-10-17) :

- `ShoppingListStatus` vaut `ACTIVE`, `PAUSED` ou `COMPLETED` ; la colonne `status` est un texte, une valeur de plus ne demande pas de migration.
- L'agrégat `ShoppingList` n'a aucun invariant sur le statut. `PlannedMeal`, `RemoveMealFromPlan`, `AddIngredientToShoppingList` et `RemoveIngredientToShoppingList` n'agissent que sur la liste active du propriétaire. `UpdateServingMeal`, `UpdateShoppingList` (renommer), `TickLineOnShoppingList`, `UncheckLineOnShoppingList`, `ActivatedShoppingList` et `PausedShoppingList` agissent par identifiant sans regarder le statut ; `UncheckLine` repasse même une liste terminée en active.
- L'ancien code ne connaît pas une valeur `SENT` : `ShoppingListStatus::from()` lèverait. Le retour arrière ne défait ni le schéma ni les données ([0017](0017-deploiement-et-releases.md) règle 6).

## Décision

1. Nouveau statut **`SENT`** (« envoyée au drive ») et colonne **`sent_at`** nullable.
2. Transitions : `ACTIVE → SENT` par `POST /api/v1/shopping-lists/{id}/send` (liste non vide, `If-Match` accepté, idempotent si déjà `SENT`) ; `SENT → ACTIVE` par `POST …/reopen` (explicite, met en pause la liste active éventuelle) ; `SENT → COMPLETED` par `complete` existant. `PAUSED → SENT` et `SENT → PAUSED` sont interdits.
3. **Sur une liste `SENT`** : autorisés = lire, terminer, rouvrir, supprimer. Tout autre écriture répond `409` avec le message `SHOPPING_LIST_SENT` (ajouter ou retirer une recette, portions, `+`/`-` d'ingrédient, renommer, cocher, décocher, activer, mettre en pause).
4. Le verrou est un **invariant de l'agrégat** : `ShoppingList::assertEditable()` est appelée dans chaque mutateur et en tête des handlers qui modifient un repas par identifiant. Cocher et décocher posent la question par un port booléen `ShoppingListRepository::isSent()`.
5. Codes (messages = codes en majuscules, comme `PROTECTED_ACCOUNT` de [0021](0021-gestion-des-comptes-par-l-administrateur.md)) : `SHOPPING_LIST_SENT` (409), `SHOPPING_LIST_NOT_SENDABLE` (409), `SHOPPING_LIST_NOT_SENT` (409), `SHOPPING_LIST_EMPTY` (422), `DRIVE_DISABLED` (404).
6. L'envoi libère la place de liste active ; `CreateShoppingList` ne touche pas une liste `SENT` ; `RemoveShoppingListCompletedEventListener` ne supprime que les listes `COMPLETED`.
7. Aucun événement de domaine à l'envoi ni à la réouverture ; `ShoppingListCompletedEvent` reste émis par `complete`, y compris depuis `SENT`.
8. Les DTO de lecture ajoutent `isSent` et `sentAt` ; la documentation OpenAPI ajoute `SENT` à l'énumération `status`.
9. **Déploiement** : l'interrupteur `DRIVE_ENABLED` est coupé par défaut ; tant qu'il l'est, `send` répond `404 DRIVE_DISABLED` et aucune liste `SENT` n'existe. Retour arrière avec des listes `SENT` : `UPDATE shopping_list SET status = 'ACTIVE', sent_at = NULL WHERE status = 'SENT';`.

## Conséquences

- Positives : le plan d'envoi est stable ; la liste sert de relevé de commande ; le verrou protège aussi contre une modification depuis un autre appareil ; la place de liste active est libre pour la semaine suivante.
- Négatives / coûts : une erreur d'envoi bloque la liste jusqu'à une réouverture explicite ; une transition et un statut de plus à gérer dans l'interface ; `send` et `reopen` coûtent une écriture chacun (budget SQL) ; l'ancien code ne lit pas `SENT` (d'où l'interrupteur).
- Garde-fou : tests de domaine (chaque mutateur refuse une liste `SENT`), tests de handlers par identifiant, parcours de bout en bout (envoyer, tenter de modifier → 409, rouvrir, modifier), scénarios `WriteBudget`.

## Alternatives écartées

- Verrouiller à la fin du passage de l'agent → la liste peut changer pendant l'envoi ; le plan n'est plus stable.
- Aucun moyen de rouvrir → un envoi raté bloquerait la liste à jamais.
- Un simple indicateur booléen sur une liste `ACTIVE` → la liste garderait la place de liste active et resterait modifiable par les chemins par identifiant.
- Autoriser renommer et cocher sur une liste envoyée → contraire à « non modifiable » et `UncheckLine` pourrait la réactiver.
