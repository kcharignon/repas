# DRV-403 — Réglages du drive (magasin, favori, produits mémorisés)

- **Dépôt** : front, `src/features/drive/`
- **Jalon** : M4 Front
- **Taille** : L
- **Dépend de** : DRV-203, DRV-204, DRV-307, DRV-402
- **Statut** : fait (PR [repas-front#100](https://github.com/kcharignon/repas-front/pull/100), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 5, 7, 9 ; `docs/adr/0003-design-system-basilic-et-safran.md` du front ; composants `src/design/Ui*` ; `RecipeEditorView.vue` comme modèle d'écran.

## Contexte

Avant d'envoyer une liste, l'utilisateur a besoin de trois choses : connaître son magasin, installer le favori, et pouvoir gérer ses produits mémorisés.

## Portée

Route `/drive/reglages` (lien depuis les réglages du compte) :

1. **Magasin** : affiche le magasin enregistré (`GET /drive/settings`) ou « aucun » ; bouton « Enregistrer mon magasin Leclerc » qui construit un message `setup` (DRV-402), ouvre le site Leclerc et, au retour (`/drive/retour`, DRV-405), enregistre via `PUT /drive/settings`. Si `enabled` est faux : message « Cette fonction n'est pas encore activée » et rien d'autre.
2. **Favori** : étapes illustrées pour installer le favori sur Chrome Android ; bouton « Copier le code du favori » : télécharge `/drive/agent.template.js` (même origine), remplace `__REPAS_ORIGIN__` par `location.origin`, produit la chaîne `javascript:` (`buildBookmarklet`, DRV-301) et la copie dans le presse-papiers ; affiche la version du gabarit et son empreinte (manifeste) ; lien vers la page d'aide (DRV-408). Ne jamais rendre la chaîne `javascript:` comme `href` cliquable (CSP et sécurité) : texte copiable seulement.
3. **Produits mémorisés** : un résumé (nombre d'ingrédients avec produits, avec produits refusés, « j'en ai toujours ») et un lien vers l'écran de gestion `/drive/produits` (DRV-410). Pas de logique métier.
4. Hors ligne : lecture du dernier état en cache TanStack si disponible, actions désactivées.
5. Textes dans `fr.ts`, composants du design (`Ui*`), tutoiement, cibles ≥ 44 px.

## Hors portée

L'écran de gestion des produits, de leurs priorités et des produits refusés (DRV-410).

## Critères d'acceptation

- Un utilisateur sans magasin voit « aucun magasin » et peut lancer l'enregistrement ; avec un magasin, il voit son libellé ou son identifiant.
- « Copier le code du favori » place dans le presse-papiers une chaîne `javascript:` qui contient l'origine courante (et non le marqueur) ; le marqueur non remplacé n'est jamais copié.
- Le résumé des produits mémorisés correspond à `GET /drive/choices` et mène à l'écran de gestion.
- L'écran est utilisable sur 360 px de large, sans défilement horizontal.

## Tests exigés

Vitest (`stubApi`, `appContext`) pour chaque section et chaque état (vide, erreur, interrupteur coupé) ; e2e Pixel 7 ; accessibilité ; test visuel mobile et bureau.

## Definition of Done

Celle du front.

## Pièges connus

- Le presse-papiers demande une action de l'utilisateur et un contexte sécurisé : prévoir un repli « sélectionner le texte ».
- Le service worker précache les ressources : le gabarit de l'agent est servi hors précache ou avec une stratégie qui reflète toujours la dernière version.

## Mise à jour G1 (2026-10-17)

La partie « installation du favori et version minimale de l'agent » est remplacée par « **application Android** » : lien vers l'aide et la dernière version (DRV-316), version minimale de l'application attendue (affichée si l'application est trop ancienne). Le choix du magasin reste fait par le mode `setup` de l'agent dans l'application (DRV-307).

## RÉVISION du 2026-10-18 (D28)

Le choix du magasin se fait par le mode `setup` de l'agent via le pont natif (`drive.setup` à ajouter avec DRV-314 si besoin) : le front demande à l'application d'ouvrir l'écran drive en mode réglage et reçoit le magasin lu.

## Constat de l'essai réel (2026-10-18)

L'agent lit l'identifiant et l'hôte du magasin mais **pas son libellé** (`label` absent) : l'écran de réglages doit donc afficher « Magasin …123 » (derniers chiffres) et permettre à l'utilisateur de donner un nom à son magasin (champ facultatif, `label` de l'API `PUT /drive/settings`).

## Contrat des choix et des réglages (2026-10-18)

`GET /api/v1/drive/choices?retailer=leclerc` (`retailer` obligatoire) renvoie `{member, totalItems}` trié par slug ; chaque membre : `{ingredientSlug, ingredientName, haveAtHome, accepted:[{rank, product}], rejected:[product], updatedAt}` avec `product = {productId, retailerProductId, ean, label, content:{value, unit}|null}` (le prix n'est pas renvoyé). `DELETE /api/v1/drive/choices/{ingredientSlug}?retailer=&storeId=` répond 204 (404 `DRIVE_CHOICE_NOT_FOUND`). Réglages : `GET|PUT /api/v1/drive/settings` (voir DRV-203).

## Réalisation (2026-10-18, PR repas-front#100) et reste à brancher

`/drive/reglages` : drive coupé → message seul ; magasin « Magasin …456 » ou nom donné par l'utilisateur (`PUT /drive/settings` avec `label`) ; adresse saisie validée comme le back (hôte `*.leclercdrive.fr`, chemin `/magasin-<id>-<id>`) ; section application (`driveBridge.info()`) ; résumé des produits mémorisés. `src/features/drive/storeReader.ts` : `readStoreFromApp()` et `canReadStoreFromApp()` sont des points d'accroche (le bouton « Lire mon magasin dans l'application » n'apparaît pas encore) : **à brancher avec le message `drive.setup` du pont** (DRV-314 côté application, intégration DRV-501). Pas de « version minimale de l'application » (le drapeau `drive` de `app.info` suffit).
