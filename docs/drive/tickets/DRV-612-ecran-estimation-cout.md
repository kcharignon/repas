# DRV-612 — Écran de l'estimation du coût d'une liste

- **Dépôt** : front (`kcharignon/repas-front`)
- **Jalon** : M2b Estimation du coût
- **Taille** : M
- **Dépend de** : DRV-611 (fait), DRV-401 (fait)
- **Statut** : fait (PR [repas-front#104](https://github.com/kcharignon/repas-front/pull/104), 2026-10-18)
- **Lire d'abord** : [`DRV-611`](DRV-611-estimation-cout-liste.md) (section « Réalisation »), skill `repas-front-dev`, `docs/drive/consignes-agents.md`, `CLAUDE.md` du front.

## Contexte

Le back sait estimer le coût d'une liste d'après les prix mémorisés (D22). L'utilisateur veut voir cette estimation dans l'application, avant d'envoyer la liste au drive. C'est une estimation **datée**, jamais un prix garanti.

## Portée

1. Sur la liste de courses (et sur l'écran d'envoi), un bloc « Estimation du coût » : total estimé en euros, nombre de lignes estimées sur le nombre à acheter (couverture), date du prix le plus ancien (« d'après les prix vus entre le … et le … »), l'avertissement fixe du back (« Estimation d'après les derniers prix vus ; le prix réel est celui du drive. »), et un détail repliable par ligne (produit, nombre, prix unitaire, sous-total, statut en français : « estimée », « pas de produit choisi », « pas de prix mémorisé », « à vérifier », « déjà à la maison »).
2. Visible seulement si le drive est activé (`GET /drive/settings` → `enabled`) et configuré ; `GET /api/v1/drive/estimates/{shoppingListId}` (lecture seule, tous statuts de liste) ; états de chargement, d'erreur (`DRIVE_DISABLED`, `DRIVE_NOT_CONFIGURED`, 403, 404) et vide ; mis à jour après un changement de liste.
3. Les lignes non estimées sont listées clairement, jamais comptées ni devinées ; si la couverture est faible (moins de la moitié), le bloc l'indique en tête.
4. Contrat du back : `{shoppingListId, retailer, store, estimatedTotalCents, estimatedLines, unestimatedLines, coverage (0 à 1), oldestPriceAt, newestPriceAt, lines:[{ingredientSlug, ingredientName, productLabel|null, packs|null, unitPriceCents|null, subtotalCents|null, priceSeenAt|null, status}], disclaimer}` ; `npm run api:update` pour figer le contrat. Montants affichés en euros au format français.
5. Textes dans `src/i18n/fr.ts` (vouvoiement à l'époque, tutoiement depuis DRV-627), jetons de couleur, accessibilité (lecteur d'écran : total annoncé, détail repliable au clavier), mobile Pixel 7, tests Vitest et e2e (API simulée), test visuel avec captures lues.

## Hors portée

Historique des prix, comparaison entre magasins, promotions.

## Definition of Done

Celle du dépôt front ; PR concise ; fusion si tous les contrôles de la CI sont verts ; issue fermée.

## Réalisation (2026-10-18, PR repas-front#104)

Bloc `DriveEstimate` sur la liste de courses et l'écran d'envoi : total en euros, couverture, dates, avertissement du back repris tel quel, détail repliable (lignes non estimées d'abord), états de chargement / erreur / vide ; visible seulement si le drive est activé et le magasin enregistré ; relu à l'ouverture et après toute écriture sur les listes. Sous 50 % de couverture le total reste affiché avec l'avertissement en tête (choix à confirmer). L'estimation porte sur toute la liste, y compris en « réessayer les échecs ».
