# DRV-745 — Les lignes cochées ne partent pas, les articles mis au panier sont cochés

- **Dépôt** : back (`src/Drive/`, `src/Repas/`, `docs/api/openapi.json`)
- **Jalon** : M7 Plusieurs enseignes (retour du propriétaire, hors ordre)
- **Taille** : M
- **Dépend de** : DRV-203, DRV-611 (plan et estimation), ADR 0023 (verrou `SENT`), ADR 0035 (Open Host Service)
- **Statut** : fait (PR #390, 2026-10-31). Pendant agent : DRV-746 (clé `notInCart` du résultat, agent 0.54.0) ; pendant site : DRV-747 (appel de la nouvelle route après le passage, texte avec `repas-po`). Effort recommandé : `high`.
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 2 (invariant 6), § 4.3, § 7 (la route), § 8 (`notInCart`) ; [ADR 0022](../../adr/0022-remplissage-du-panier-drive.md) et [ADR 0023](../../adr/0023-liste-envoyee-au-drive.md) (amendements du 2026-10-31) ; skill `repas-drive-back`.

## Contexte

Décision du propriétaire (2026-10-31) :

1. Les lignes **déjà cochées** d'une liste ne partent pas au drive (ni dans le plan, ni dans l'estimation du coût).
2. Un article **mis au panier par l'agent** coche sa ligne dans la liste. Un produit introuvable, « Pas cette fois » ou « J'en ai toujours » : la ligne reste **non cochée**.
3. À la **réouverture** d'une liste envoyée, les lignes cochées avant l'envoi et celles mises au panier sont cochées : les lignes non cochées sont les articles manquants au drive.

Le back ne sait rien de ce que fait l'agent (`PUT /drive/choices` n'a ni identifiant de liste ni issue de ligne) : il faut une écriture nouvelle du front vers le back. La réouverture ne touche pas aux lignes : le point 3 est acquis, il est seulement prouvé par un test.

## Portée

1. **Les lignes cochées ne partent pas** : `ShoppingListContentPostgreSQLLoader::loadLines` ne lit que `r.checked = false` (source unique du plan et de l'estimation, via `ShoppingListContentQueryOHS`). `ShoppingList::send()` est refusé (`422 SHOPPING_LIST_EMPTY`, code inchangé) quand il ne reste aucune ligne non cochée (`countUncheckedRows()`).
2. **Écriture « ces articles sont au panier »** : `POST /api/v1/drive/plans/{shoppingListId}/basket-lines` `{ingredientSlugs: string[]}` (1 à 1 000 slugs distincts, `^[a-z0-9-]{1,255}$`), `204`. Chemin : controller Drive (`#[RequiresDrive]`, `#[RateLimitedPerUser(DriveRateLimit::WRITE)]`, voter `SHOPPING_LIST_OWNER`) → commande Drive `MarkLinesInBasket` (traduit les slugs par `IngredientAliasCommandPort::resolve`) → port `BasketLineCommandPort` + adapter `BasketLineCommandAdapter` → `BasketLineCheckCommandOHS` de `Repas` → commande `CheckRowsAddedToDriveBasket` (propriétaire par `ShoppingListRepository::ownerIdOf`, statut par `isSent`) → `ShoppingListRowRepository::checkByIngredientSlugs` (**un seul `UPDATE`**). Idempotente ; un slug absent de la liste est ignoré ; **jamais un décochage** ; **aucun `LineTickedEvent`** (la dernière ligne cochée ne termine pas la liste) ; **aucun changement de version** de la liste (le front rouvre avec le `If-Match` pris sur `plan.listVersion`). Erreurs : `404 DRIVE_DISABLED`, `403` (autre utilisateur, administrateur compris), `404` liste inconnue, `409 SHOPPING_LIST_NOT_SENT`, `422` corps invalide, `429 DRIVE_RATE_LIMITED`.
3. **Documentation** : ADR 0022 règle 9 et ADR 0023 décision 3 amendées ; `architecture.md` (invariant 6, § 4.3, § 7, § 8) ; `README.md`, `note-vie-privee.md`, `recette.md` ; skill `repas-drive-back` ; `CLAUDE.md` ; `docs/api/openapi.json` (`make openapi`).

## Hors portée

- L'**agent** : la clé facultative `notInCart: true` d'une ligne `CHOSEN` du résultat (le panier n'a rien pris du produit choisi), `AGENT_VERSION` 0.54.0, une nouvelle publication de l'application : DRV-746 (dépôt du front).
- Le **site** : appeler la route après le passage (jamais bloquant), invalider le cache des listes, compter les lignes non cochées pour « Envoyer au drive », la barre « N sur M articles dans le panier » : DRV-747.
- Aucune migration, aucun changement des réponses existantes hors le filtre des lignes cochées.

## Décisions

- **Borne de 1 000 slugs** (`MarkLinesInBasketCommand::MAX_SLUGS`) : une liste n'a pas de nombre de lignes borné dans le modèle ; 1 000 est bien au-dessus d'une vraie liste et garde le corps (255 caractères par slug au plus) sous la limite de 1 Mo de la bordure et l'instruction SQL raisonnable. Un corps sans slug est refusé (`422`) : le front n'appelle pas s'il n'y a rien à cocher.
- **Propriétaire jugé par la commande de `Repas`**, pas seulement par le voter : le voter `SHOPPING_LIST_OWNER` laisse passer un administrateur, qui ne doit pas cocher la liste d'un autre (`403 SHOPPING_LIST_FORBIDDEN`, `ShoppingListException::forbidden`). Trois requêtes avant l'écriture : propriétaire, statut, alias.
- **Alias** : `IngredientAliasCommandPort::resolve` (pas `resolveOwned`) : la commande ne pose aucun choix sur un ingrédient, elle désigne des lignes de la liste de l'utilisateur ; un slug inconnu est ignoré. La classe est déclarée dans `IngredientSlugWritesAreResolvedTest`.
- **Pas de décision par enseigne** : la route ne reçoit pas d'enseigne, seule la liste compte (`DriveRetailerSwitchApiFlowTest`, `ROUTES_WITHOUT_A_RETAILER`).

## Critères d'acceptation

- Une liste dont une ligne est cochée : le plan et l'estimation n'ont pas cette ligne, leurs comptes suivent ; `send` refuse (`422 SHOPPING_LIST_EMPTY`) une liste dont toutes les lignes sont cochées et accepte celle qui en garde une.
- `basket-lines` coche exactement les lignes désignées de la liste `SENT` du propriétaire ; idempotente ; slug absent ignoré ; ancien slug traduit ; liste active, rouverte ou terminée : `409 SHOPPING_LIST_NOT_SENT` sans rien écrire ; liste d'un autre (administrateur compris) : `403` sans rien écrire ; liste inconnue : `404`.
- Cocher la dernière ligne ne termine pas la liste (statut `SENT`, version inchangée) ; rouvrir garde toutes les coches.
- Budget SQL `cocher-lignes-du-panier-drive` : 4 requêtes (propriétaire, statut, alias, `UPDATE`), quel que soit le nombre de lignes.
- `make test`, `make quality` verts ; `docs/api/openapi.json` à jour.
