# 0021 — Gestion des comptes par l'administrateur : activer, désactiver, supprimer

- **Statut** : Accepted
- **Date** : 2026-10-16
- **Liens** : [0001](0001-architecture-hexagonale.md), [0003](0003-cqrs-lecture-ecriture.md), [0006](0006-buses-messenger.md), [0007](0007-api-rest-v1.md) §11 et §12, [0008](0008-auth-session-et-jwt.md) §7 et §8, [0013](0013-agregats-references-par-identifiant.md), [0015](0015-refresh-token-du-spa.md), [0020](0020-confirmation-d-adresse-email.md)

## Contexte

Un administrateur ne pouvait que lister les comptes (`GET /api/v1/users`). Il ne pouvait ni confirmer à la main un compte resté en attente ([0020](0020-confirmation-d-adresse-email.md)), ni couper l'accès d'un compte, ni supprimer un compte et ce qu'il possède.

Faits :

- Le statut `UserStatus` (`PENDING`, `ACTIVE`, `DISABLED`) existe depuis [0020](0020-confirmation-d-adresse-email.md) ; `UserChecker`, `RotateRefreshTokenHandler`, `OwnerVoter` et `SendEmailVerificationHandler` refusent déjà un compte désactivé. Aucun cas d'usage ne le désactivait.
- Aucune clé étrangère ne lie les tables à `user` : `recipe.author`, `ingredient.creator`, `shopping_list.owner`, `refresh_token.user_id` et `email_verification_token.user_id` sont de simples colonnes texte ([0013](0013-agregats-references-par-identifiant.md)). Rien dans la base n'efface en cascade.
- Les contextes `User` et `Repas` ne s'importent pas ([0001](0001-architecture-hexagonale.md)) : ce qui circule passe par `Shared`.
- Une recette copiée garde l'identifiant de son original (`recipe.orignal`) ; la liste « recettes des autres » ne propose que les recettes dont `orignal` est nul (`RecipePostgreSQLLoader::listOfOtherPeople`). Copier une recette copie aussi ses ingrédients personnalisés au nom du copieur (`CopyRecipeHandler`) : une copie ne référence jamais l'ingrédient d'un autre.
- Le bus de commandes est transactionnel ([0006](0006-buses-messenger.md)) et les écouteurs d'événements sont synchrones.

## Décision

1. **Trois transitions, pas plus** : `PENDING → ACTIVE` (`User::activate()`), `ACTIVE → DISABLED` et `PENDING → DISABLED` (`User::disable()`). Toute autre transition (activer un compte actif ou désactivé, désactiver un compte déjà désactivé) lève `UserException::invalidStatusTransition()` : `409` (conflit avec l'état courant, [0007](0007-api-rest-v1.md) §12). Réactiver un compte désactivé n'est pas offert : un compte désactivé le reste jusqu'à sa suppression. Le permettre serait une ligne de plus dans `User::activate()` et un ADR amendé.
2. **Trois endpoints réservés à `ROLE_ADMIN`**, un cas d'usage chacun ([0007](0007-api-rest-v1.md) §11) : `POST /api/v1/users/{id}/activate`, `POST /api/v1/users/{id}/disable`, `DELETE /api/v1/users/{id}`, tous en `204`. Compte inconnu : `404`. Le front lit l'état par `GET /api/v1/users`, dont le DTO expose désormais `status` (`PENDING`, `ACTIVE`, `DISABLED`) à côté de `isDisabled`, conservé.
3. **Un administrateur ne désactive ni ne supprime son propre compte** : `422 CANNOT_MANAGE_OWN_ACCOUNT` (règle métier, pas un conflit). Le handler compare l'identifiant visé à celui du jeton, passé par la commande (`actingUserId`, jamais le corps). Tant qu'un administrateur existe, il en reste donc un.
4. **Un compte désactivé ne peut plus rien**, par ce qui existait déjà et ce que le cas d'usage ajoute :
   - connexion refusée en `401 Invalid credentials.` (générique, [0008](0008-auth-session-et-jwt.md) §7) ;
   - un jeton d'accès encore valable est refusé dès la requête suivante : `UserChecker::checkPreAuth()` s'applique à chaque requête du pare-feu `api` ([0008](0008-auth-session-et-jwt.md) §8) ;
   - le cookie de rafraîchissement ne s'échange plus (`RotateRefreshTokenHandler`) ; **en plus**, `DisableUser` supprime tous les refresh tokens du compte (`RefreshTokenRepository::deleteAllOfUser()`) ;
   - **en plus**, `DisableUser` supprime ses liens de confirmation d'adresse ; le renvoi d'un lien ne fait rien pour un compte désactivé et confirmer un lien ne réactive pas ([0020](0020-confirmation-d-adresse-email.md) §1 et §8) ;
   - **toute future route publique qui s'adresse à un compte (mot de passe oublié, `repas-front#31`) doit ne rien faire pour un compte désactivé et répondre comme pour une adresse inconnue** ; son handler appelle `User::isDisabled()` comme le fait `SendEmailVerificationHandler`.
5. **Activer** supprime aussi le lien de confirmation en attente : il n'a plus d'objet.
6. **Supprimer un compte l'efface « comme s'il n'avait jamais existé »**. `DeleteUserHandler` (contexte `User`) supprime les refresh tokens, les liens de confirmation et le compte, puis annonce `Shared\Domain\Event\UserDeletedEvent(userId)`. `PurgeUserDataOnUserDeletedListener` (contexte `Repas`) répond par `PurgeUserDataCommand`, dont le handler efface en masse, dans cet ordre : les listes de courses du compte (lignes, ingrédients de liste, repas), les repas d'**autres** listes qui planifient ses recettes, puis les recettes (lignes comprises) après avoir rendu orphelines leurs copies, enfin les conversions de ses ingrédients personnalisés puis ces ingrédients.
7. **Une recette copiée par un autre reste, comme si c'était l'originale** : `RecipeRepository::makeCopiesOriginal()` met `orignal` à `NULL` (et augmente `version`, pour qu'une copie modifiée entre-temps soit vue comme périmée). La copie devient une recette originale : elle apparaît dans « recettes des autres » pour les autres utilisateurs, et son auteur reste le copieur. Les ingrédients personnalisés de l'original ont déjà été copiés au nom du copieur et n'ont pas de lien avec ceux du compte supprimé.
8. **Atomique** : la suppression, l'événement et la purge tournent dans la transaction de `command.bus` ([0006](0006-buses-messenger.md)). Si la purge échoue, le compte n'est pas supprimé et rien d'autre non plus.
9. **Suppressions en masse, sans événements par agrégat** : la purge n'appelle ni `RemoveRecipe` ni `RemoveIngredient` ni `RemoveShoppingList`. Ces cas d'usage refusent de supprimer ce qu'une liste utilise, coûtent une charge d'agrégat par objet, et leurs événements (`RecipeRemovedEvent`, `IngredientRemovedEvent`) mettraient à jour les statistiques d'un compte qui n'existe plus. Le coût est constant : `supprimer-compte` fait 15 requêtes SQL, quel que soit le volume de données. Les suppressions en masse ne mettent pas à jour l'identity map de Doctrine ni ne contrôlent la version : elles sont réservées à la fin d'une requête.
10. **Garde-fou contre l'oubli** : `UserOwnedDataCoverageTest` échoue dès qu'une entité gagne une colonne `owner|author|creator|user` qui n'est pas dans la liste des données effacées avec le compte. Ajouter une table qui désigne un utilisateur impose d'étendre la purge, puis cette liste.

## Conséquences

- Positives : l'administrateur peut débloquer un compte en attente sans attendre l'email, couper un accès sur-le-champ (jeton d'accès compris), et supprimer un compte sans laisser de ligne orpheline ; les copies d'autres utilisateurs survivent ; le front peut distinguer les trois états.
- Coûts : la suppression est **irréversible** (la seule reprise est la dernière sauvegarde `pg_dump`, prise à chaque déploiement par `deploy/backup.sh`, [0017](0017-deploiement-et-releases.md)) ; le front doit demander une confirmation explicite ; il n'y a pas de réactivation d'un compte désactivé ; trois use cases d'écriture de plus (3, 4 et 15 requêtes SQL) ; un port de plus par agrégat purgé (`deleteByOwner`, `deleteByAuthor`, `deleteByCreator`, `deleteByIngredientCreator`, `findIdsByAuthor`, `makeCopiesOriginal`, `removeMealsOfRecipes`).
- Limite connue : la planification d'une recette (`PUT /shopping-lists/active/recipes/{recipeId}`) ne vérifie pas que la recette appartient à l'utilisateur. Un utilisateur qui a planifié, par un appel direct de l'API, la recette d'un compte supprimé perd ce repas, mais les quantités des lignes de sa liste ne sont pas recalculées. Le front ne propose que ses propres recettes ; l'écart d'autorisation est noté dans la roadmap.
- Garde-fou : tests des handlers (en mémoire), des dépôts sur base réelle (`UserDataPurgeRepositoryTest`, `UserRepositoryTest`, `RefreshTokenRepositoryTest`), des controllers, du parcours complet avec les vrais bus, jetons et cookie (`AccountAdministrationFlowTest` : jeton d'accès refusé, cookie refusé, copie conservée, suppression annulée si la purge échoue), `UserOwnedDataCoverageTest`, budgets SQL, `make openapi-check`.

## Alternatives écartées

- Désactivation logique ou anonymisation à la place de la suppression : le besoin est que le compte n'ait jamais existé, y compris pour l'adresse email, qui redevient libre.
- Supprimer aussi les copies des autres : elles appartiennent à leur auteur, qui les a choisies.
- Clés étrangères `ON DELETE CASCADE` : demandent une migration sur des tables sans clé, ne gèrent pas `recipe.orignal` (remise à `NULL` et non suppression), et déplaceraient une règle métier dans le schéma, hors du domaine.
- Appeler `RemoveRecipe`, `RemoveIngredient` et `RemoveShoppingList` pour chaque objet : coût proportionnel au volume, refus quand une liste d'un autre utilisateur utilise l'objet, événements de statistiques sur un compte supprimé.
- Un seul endpoint `PATCH /users/{id}` avec un champ `status` : la grille des transitions ([0007](0007-api-rest-v1.md) §11) veut une action par commande, et la suppression est un `DELETE`.
- Autoriser un administrateur à se désactiver ou se supprimer : il se priverait de l'administration sans reprise possible par l'API.
- Réactiver un compte désactivé (`DISABLED → ACTIVE`) : non demandé ; la désactivation sert aussi de pré-suppression, et l'ajouter plus tard coûte une ligne.
