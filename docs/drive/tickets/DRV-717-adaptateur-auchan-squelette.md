# DRV-717 — Faux site d'Auchan, lecture du magasin, de la session et de l'entrée, diagnostic

- **Dépôt** : front (`drive-agent/src/retailers/auchan/`, `drive-agent/fake-auchan/`, `drive-agent/fixtures/auchan/`, `android/emulator/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : L
- **Dépend de** : DRV-727 (l'entrée de registre et le protocole), DRV-722 (`offerId`)
- **Statut** : fait (PR front #256, 2026-10-30, agent 0.49.0 ; 144 tests e2e de l'agent dont 13 contre le faux site). Le **volet émulateur n'est pas fait** (DRV-729 pas encore fusionné) : voir [DRV-731](DRV-731-scenario-emulateur-auchan.md). Écarts : code dans `src/auchan/` (pas `src/retailers/auchan/`) ; cause `STORE_NOT_CHOSEN` ; `readStore` peut être asynchrone ; `Pacer` partagé dans `retailers/pacer.ts` ; un visiteur arrête l'envoi (`NOT_LOGGED_IN`, `SESSION_VISITOR`) comme chez Leclerc : à trancher à la recette (DRV-726) ; la livraison à domicile (canal sans `PICK_UP`) n'est que rapportée dans le diagnostic. Effort recommandé : `high`. **Décision prise** : [`../spikes/DRV-715-decision-auchan.md`](../spikes/DRV-715-decision-auchan.md).
- **Lire d'abord** : la décision ; [`../hypotheses-site-reel-auchan.md`](../hypotheses-site-reel-auchan.md) (ce qui est mesuré, supposé, inconnu : **ne jamais citer un « S » ou un « I » comme un fait**) ; les trois rapports `../spikes/DRV-712…714-rapport.md` ; `drive-agent/src/leclerc/` (modèle, **à lire sans le copier**) ; skill `repas-agent-dev` ; DRV-303 et DRV-309 (adaptateur et faux site de Leclerc).

## Contexte

DRV-727 a posé l'entrée d'Auchan avec un adaptateur vide. Ce ticket écrit la **première moitié** de l'adaptateur (tout sauf la recherche et le panier) et le **faux site** qui permet de jouer l'agent sans jamais contacter Auchan. La recherche et la lecture des produits sont l'objet de DRV-724, le panier de DRV-725.

## Portée

1. **Faux site** `drive-agent/fake-auchan/` (serveur Node sans dépendance, accès local seulement, mêmes garde-fous que `fake-leclerc/`) qui rejoue les **formes mesurées** : `GET /journey`, `GET /cart/config`, `GET /cart`, `POST /cart/update`, `GET /recherche?text=`, page d'accueil des courses (barre « Choisir vos courses » / « Retrait : … »), `404` réel, cookies de session (`lark-cart`, `lark-consentId`), `sessionStorage.tracking_authenticated` ; **données entièrement inventées** (UUID de test, libellés `Drive Ville-Test`). Fixtures `drive-agent/fixtures/auchan/` synthétiques ; jamais de HTML réel dans le dépôt.
2. **Adaptateur** (`drive-agent/src/retailers/auchan/`) : `readStore` (par `GET /journey`, contexte `GROCERY` : `seller.id` = identifiant, `pointOfService.name` = libellé ; barre « Choisir vos courses » ou contexte absent = **aucun magasin choisi**, cause existante), `readSession` (connecté ou visiteur : `tracking_authenticated`), `readArrival`, `readEntry` / `followEntry` (page d'accueil, panier vide : « Commencer mes achats »), détection d'une page « introuvable » (`404` réel), `stats`, sondes de diagnostic (présence du cookie `datadome` en oui/non : exception bornée de `consignes-agents.md`, jamais un nom ni une valeur), causes propres (`auchan/causes.ts`). Les méthodes de recherche et de panier **restent** `CONTRACT_CHANGED` (DRV-724, 725).
3. **Sélection** : l'agent choisit l'adaptateur d'Auchan sur une page de `www.auchan.fr` pour un plan d'Auchan ; `compte.auchan.fr` : aucun agent (jamais injecté, DRV-728) ; page d'une autre enseigne : `STORE_MISMATCH` sans requête.
4. **Tests** : unitaires de l'adaptateur sur fixtures, e2e (`make agent-e2e` étendu) contre le faux site, scénario de l'émulateur (`android/emulator/`) pour la lecture du magasin seulement.
5. Documentation : `drive-agent/README.md`, `fake-auchan/README.md` ; `hypotheses-site-reel-auchan.md` mis à jour pour chaque ligne touchée.

## Hors portée

Recherche et produits (DRV-724), panier (DRV-725), allumer Auchan en production, publier l'application, l'avis juridique.

## Critères d'acceptation

- Étant donné le faux site, alors `readStore` rend l'identifiant et le libellé inventés, `readSession` distingue visiteur et connecté, et un parcours sans magasin s'arrête sur la cause « choisis ton magasin » **sans aucune écriture**.
- Étant donné une page qui n'est ni celle d'Auchan ni celle d'un magasin d'Auchan, alors `UNSUPPORTED_PAGE` / `STORE_MISMATCH` sans requête.
- Étant donné `DRIVE_RETAILERS=leclerc`, alors Auchan est invisible partout et le comportement de Leclerc est **inchangé** (tests e2e de Leclerc sans modification d'attente).

## Tests exigés

`make agent-verify`, `make agent-e2e`, `make android-test`, `make android-e2e` (sous le verrou), `make check` ; test d'architecture : « auchan » n'apparaît dans `drive-agent/src/` que sous `retailers/auchan/` et dans l'entrée du registre.

## Definition of Done

Celle du dépôt front et du skill `repas-agent-dev` ; empreinte de l'agent mise à jour ; **ordre de publication** dit dans le rapport (front avant application) ; aucune étiquette ; rapport court des hypothèses non mesurées.

## Pièges connus

- Chez Leclerc, plusieurs hypothèses (adresse sans ville, formes de la page de défi, libellés de visiteur) se sont révélées fausses à la recette : marquer tout ce qui n'est pas mesuré comme tel, dans le code et dans `hypotheses-site-reel-auchan.md`.
- **Ne jamais ouvrir le vrai site d'Auchan** depuis un agent, un test ou l'émulateur.
- Ne pas faire passer l'adaptateur par des noms propres à Leclerc (`zcwb`, `iIdProduit`…).
- Le magasin d'Auchan n'est pas dans l'adresse : ne **jamais** le chercher dans l'adresse de la page.
