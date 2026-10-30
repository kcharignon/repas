# DRV-720 — Magasin à identifiant explicite et plus long (Auchan)

- **Dépôt** : back (`src/Drive/Domain/Model/`, `src/Drive/Infrastructure/Entity/`, `migrations/`, `docs/api/openapi.json`, `docs/drive/samples/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-701 (le registre et son contrat)
- **Statut** : à faire. Effort recommandé : `xhigh` (migration de colonnes de données d'utilisateur).
- **Lire d'abord** : [`../spikes/DRV-715-decision-auchan.md`](../spikes/DRV-715-decision-auchan.md) § 2 points 2 et 4 ; [`../spikes/DRV-712-rapport.md`](../spikes/DRV-712-rapport.md) § Compléments ; [`../multi-enseignes.md`](../multi-enseignes.md) §§ 3.2, 4 ; skills `repas-dev` et `repas-drive-back` (migrations compatibles avec la version précédente du code).

## Contexte

Chez Leclerc, le magasin est une **adresse** (`https://<sous-domaine>.leclercdrive.fr/magasin-N-N`) et son identifiant s'en déduit : `Store::create` vérifie `$retailer->storeIdOf($baseUrl) === $id`. Chez Auchan, le magasin n'est **pas dans l'adresse** : l'agent le lit par `GET /journey` et obtient un **UUID** (36 caractères) et un libellé ; l'adresse d'enseigne est une constante (`https://www.auchan.fr/`). Or `Store::ID_MAX_LENGTH` vaut 20 et trois colonnes `store_id` sont des `VARCHAR(20)` : `drive_settings`, `drive_product`, `drive_choice`.

## Portée

1. **Profil** (`RetailerProfile`) : ajouter `storeIdSource` (`StoreIdSource::URL` pour Leclerc : valeur actuelle ; `StoreIdSource::EXPLICIT`) et `storeIdPattern` (Leclerc : le motif numérique actuel de l'identifiant extrait). `Retailer::isValidStoreId()`.
2. **`Store::create`** : si la source est `URL`, comportement **inchangé** (l'identifiant se déduit de l'adresse). Si elle est `EXPLICIT`, l'identifiant doit respecter `storeIdPattern` et l'adresse la règle d'adresse de l'enseigne (constante d'enseigne) ; aucune déduction. Même exception `storeInvalid()` dans tous les cas de refus.
3. **Longueur** : `Store::ID_MAX_LENGTH` passe à **40** ; `DriveStorePayload` (contrainte et OpenAPI `maxLength`), entités `DriveSettings`, `Product`, `Choice` (`length: 40`).
4. **Migration** : `ALTER TABLE … ALTER COLUMN store_id TYPE VARCHAR(40)` sur les trois tables (élargissement : sans réécriture, **compatible avec la version précédente du code**, qui n'écrit jamais plus de 20 caractères tant qu'Auchan est éteint). Les contraintes d'unicité (`uniq_drive_product_key`, `uniq_drive_choice_user_store_ingredient`) sont conservées. Vérifier qu'aucune autre table ne porte `store_id`.
5. **Contrat** : `retailers.sample.json` gagne **deux champs additifs** `storeIdSource` (`"URL"` / `"EXPLICIT"`) et `storeIdPattern` pour chaque enseigne ; le front tolère les champs inconnus (DRV-704, 708). `docs/drive/architecture.md` (section du magasin) et le skill `repas-drive-back` mis à jour.
6. **Aucun nom d'Auchan** ici : un profil **fictif de test** à source `EXPLICIT` suffit (Auchan est déclaré par DRV-716).

## Hors portée

Déclarer Auchan (DRV-716), l'identifiant d'offre (DRV-721), toute interface.

## Critères d'acceptation

- Étant donné Leclerc, alors `Store::create` accepte et refuse exactement les mêmes valeurs qu'avant (tests existants **sans modification d'attente**) et les exemples de contrat ne diffèrent que par les deux champs ajoutés.
- Étant donné un profil de test `EXPLICIT`, alors un magasin à identifiant de 36 caractères et à adresse constante se crée, se lit (`GET /drive/settings`), se range (`PUT /drive/settings`) et accueille des produits et des choix ; un identifiant hors motif ou trop long est refusé (`422`).
- Étant donné la base d'avant, alors la migration s'applique et se défait sans perte, et le schéma reste celui que l'ORM attend.

## Tests exigés

`StoreTest` (deux sources), `RetailerTest`, loaders et dépôts sur base réelle avec un identifiant de 36 caractères (réglage, produit, choix), test de migration (`make test`), contrat, `make quality`, `make openapi`.

## Definition of Done

Celle de `repas-dev` et `repas-drive-back` ; aucun nouveau scénario `WriteBudget` (prouver que `budgets.php` ne bouge pas) ; roadmap cochée, statut du ticket.

## Pièges connus

- Un identifiant de magasin n'est **pas** un identifiant d'utilisateur : il reste un fait de l'enseigne, jamais chiffré ni haché.
- Ne pas toucher à `Store::BASE_URL_MAX_LENGTH` ni au libellé.
- Ne rien retirer de `GET /drive/settings` : les anciens clients lisent `store.id` comme une chaîne (elle est simplement plus longue).
