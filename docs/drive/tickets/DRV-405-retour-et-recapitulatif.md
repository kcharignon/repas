# DRV-405 — Retour de l'agent et récapitulatif

- **Dépôt** : front, `src/features/drive/`
- **Jalon** : M4 Front
- **Taille** : L
- **Dépend de** : DRV-204, DRV-402
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 7, 8, 9 ; DRV-402.

## Contexte

L'agent renvoie l'utilisateur sur `/drive/retour#result=…`. Repas doit valider le résultat, mémoriser les nouveaux choix et montrer clairement ce qui s'est passé.

## Portée

Route `/drive/retour` (accessible sans passer par le routeur « liste » ; conserve le fragment jusqu'à lecture, puis l'efface) :

1. Lit le fragment (`readResult`, DRV-402). Invalide, absent ou agent trop ancien : message explicatif et lien vers l'aide. `runId` différent du brouillon : « Ce résultat ne correspond pas à ton dernier envoi » (ignoré).
2. **`setup-result`** : enregistre le magasin (`PUT /drive/settings`) puis revient aux réglages avec confirmation.
3. **`result`** : construit le lot d'intentions à enregistrer et appelle `PUT /drive/choices` (un seul appel, `retailerProductId` = `id` du protocole) : ligne `CHOSEN` avec `placement` `PRIMARY` ou `FALLBACK` → `ADD_PRODUCT` ; `placement` `ONCE` → rien ; ligne `HAVE_AT_HOME` → `HAVE_AT_HOME` ; chaque produit de `rejected` (toute ligne) → `REJECT_PRODUCT` ; `ADDED`, `SKIPPED`, `UNAVAILABLE`, `FAILED` et `unavailableIds` → rien à enregistrer ; en cas d'échec, conserver le résultat en mémoire et proposer « Réessayer l'enregistrement » (le brouillon n'est supprimé qu'après succès).
4. **Récapitulatif** : compteurs (ajoutés, choisis et mémorisés, tu en as déjà, passés, indisponibles, échecs), liste par ligne avec le produit et la quantité, message selon `outcome` (`completed` / `partial` / `aborted` / `error` avec le code traduit en phrase claire : connexion, magasin différent, blocage, site changé, réseau).
5. **Actions** :
   - « Finaliser sur Leclerc » : ouvre la page panier du magasin (adresse trouvée par le spike DRV-004/002 ; à défaut la page d'accueil du magasin) ;
   - « Réessayer les échecs » (si `partial`/échecs) : retourne à l'écran d'envoi (DRV-404) avec les lignes en échec seulement ;
   - « Terminer la liste » : `POST /shopping-lists/{id}/complete` (confirmation) ;
   - « Rouvrir la liste » (confirmation) ;
   - mention permanente : « Le panier Leclerc n'est pas vidé par Repas. »
6. Supprime le brouillon après enregistrement réussi.

## Critères d'acceptation

- Un résultat `completed` avec 8 `ADDED` (dont 1 avec `usedRank: 2`), 3 `CHOSEN` (2 `PRIMARY`, 1 `ONCE`), 1 `HAVE_AT_HOME` et 1 produit `rejected` enregistre 4 intentions en un appel (2 `ADD_PRODUCT`, 1 `HAVE_AT_HOME`, 1 `REJECT_PRODUCT`) et affiche les compteurs.
- Le récapitulatif signale chaque produit de secours utilisé (« Farine : produit de secours utilisé, le préféré n'était pas disponible »).
- Un résultat `partial` avec `BLOCKED` propose « Réessayer les échecs » et n'efface pas ce qui a été fait.
- Un fragment invalide ou d'un autre `runId` n'enregistre rien.
- Si l'enregistrement échoue, rien n'est perdu et l'utilisateur peut réessayer.

## Tests exigés

Vitest : chaque issue de ligne, chaque `outcome`, échec d'enregistrement ; e2e Pixel 7 avec un faux résultat en fragment ; accessibilité ; test visuel.

## Definition of Done

Celle du front.

## Pièges connus

- Ne rien enregistrer sur la foi d'un message non validé ; ne jamais afficher de HTML issu du message (libellés en texte).
- Le service worker peut recharger la page : lire et effacer le fragment dès le premier rendu.

## Précisions du back (2026-10-18, DRV-204)

- Enregistrement des choix par `PUT /api/v1/drive/choices` (lot ≤ 200). Actions : `ADD_PRODUCT` (placement `PRIMARY|FALLBACK`, `product` avec `label`), `REJECT_PRODUCT`, `HAVE_AT_HOME` et **`REFRESH_PRICE`** (`{ingredientSlug, action:"REFRESH_PRICE", product:{retailerProductId, price:{cents, perMeasure?:{cents, unit}}}}`, sans libellé ni placement). **Une ligne `ADDED` du résultat de l'agent qui porte un `price` doit envoyer un `REFRESH_PRICE`** (le ticket disait « ADDED : rien à enregistrer » : c'est remplacé) ; le back ignore sans erreur un produit inconnu ou non accepté pour cet ingrédient. Le prix d'une ligne `CHOSEN` part avec le produit de l'`ADD_PRODUCT`.
- `retailerProductId` est une **chaîne** de 1 à 12 chiffres (`"555111"`). Prix en centimes entiers (0 à 1 000 000), contenu de 0,001 à 100 000, libellé de 1 à 255 caractères, code-barres de 8 à 14 chiffres.
- **Ordre des intentions pour un même ingrédient** : un `ADD_PRODUCT` ou un `REJECT_PRODUCT` sur un ingrédient « j'en ai toujours » le fait redevenir une préférence de produits ; si une ligne « j'en ai toujours » porte aussi des produits refusés, envoyer `HAVE_AT_HOME` **après** les `REJECT_PRODUCT` du même ingrédient. Un produit à la fois ajouté et refusé pour un même ingrédient dans un lot donne `422 DRIVE_CHOICE_INVALID`.
- Erreurs : `404 DRIVE_DISABLED`, `409 DRIVE_NOT_CONFIGURED`, `409 DRIVE_STORE_MISMATCH` (le `storeId` n'est pas le magasin courant), `422 DRIVE_CHOICE_INVALID`.
