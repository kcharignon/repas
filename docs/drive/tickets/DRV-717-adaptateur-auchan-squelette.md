# DRV-717 — Adaptateur Auchan (squelette) et faux site

- **Dépôt** : front (`drive-agent/src/retailers/auchan/`, `drive-agent/fake-auchan/`, `android/emulator/`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : L
- **Dépend de** : DRV-715, DRV-716
- **Statut** : à faire (**après les spikes**, le contenu exact sera précisé par DRV-715). Effort recommandé : `high` (`xhigh` pour la partie qui touche l'anti-robot si les spikes en trouvent un).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 4.3, 11 ; les rapports DRV-712 à 714 et la décision DRV-715 ; `drive-agent/src/leclerc/` (modèle) ; skill `repas-agent-dev` ; DRV-303, 309 (adaptateur et faux site de Leclerc) ; `hypotheses-site-reel-auchan.md` (écrit par DRV-715).

## Contexte

L'interface `RetailerAdapter` est la même pour toutes les enseignes. Un squelette d'Auchan implémente chaque méthode **d'après les rapports des spikes** ; faute de preuve, une méthode lève `CONTRACT_CHANGED` avec une cause explicite plutôt que d'inventer un comportement. Un faux site d'Auchan (données **entièrement inventées**) sert à jouer l'agent sans jamais contacter l'enseigne, comme `fake-leclerc/`.

## Portée

1. **Adaptateur** `drive-agent/src/retailers/auchan/` : `readStore`, `searchProducts`, `readProductFromSearch`, `setCartQuantity` (quantité **absolue** ; plancher), `cartQuantity`, `pageCartTotal`, `readSession`, `readArrival`, `readEntry`/`followEntry`, `stats`, sondes de diagnostic ; causes propres (`auchan/causes.ts`) ; cadence de départ du registre (non mesurée : à marquer comme telle, comme celle de Leclerc l'était) ; détection d'un visiteur, d'un défi, d'une page « introuvable » selon les spikes.
2. **Faux site** `drive-agent/fake-auchan/` (serveur Node sans dépendance, accès local seulement, mêmes garde-fous que `fake-leclerc/`) et fixtures `drive-agent/fixtures/auchan/` **synthétiques** ; jamais de HTML réel dans le dépôt.
3. **Tests** : unitaires de l'adaptateur sur fixtures, e2e (`make agent-e2e` étendu) contre le faux site, scénarios de l'émulateur (`android/emulator/`) pour la lecture du magasin et un passage complet.
4. **Application** : l'annonce `auchan` dans `app.info.retailers` reste derrière le drapeau de construction ; une pré-version de débogage `android-dev-AAAA-MM-JJ` peut être demandée par l'humain pour la recette (jamais d'étiquette de publication).
5. **Recette réelle** : gabarit de recette d'une enseigne (DRV-711) à dérouler sur le téléphone par l'humain, `DRIVE_RETAILERS` allumé en environnement de test seulement ; **c'est un ticket humain distinct**, écrit par DRV-715.

## Hors portée

Allumer Auchan en production, publier l'application, une enseigne de plus, l'avis juridique.

## Critères d'acceptation

- Étant donné le faux site d'Auchan, alors un passage complet (phases A et B) donne les mêmes types de résultats que contre le faux Leclerc (`ADDED`, `CHOSEN`, `UNAVAILABLE`, `FAILED`), avec les garde-fous des invariants (quantité jamais réduite, aucune validation).
- Étant donné une page qui n'est ni celle d'Auchan ni celle d'un magasin d'Auchan, alors `UNSUPPORTED_PAGE`/`STORE_MISMATCH` sans requête.
- Étant donné `DRIVE_RETAILERS=leclerc`, alors Auchan est invisible partout et le comportement de Leclerc est inchangé.

## Tests exigés

`make agent-verify`, `make agent-e2e`, `make android-test`, `make android-e2e` (sous le verrou de l'émulateur), `make check` ; test d'architecture : « auchan » n'apparaît dans `drive-agent/src/` que sous `retailers/auchan/` et dans l'entrée du registre.

## Definition of Done

Celle du dépôt front et du skill `repas-agent-dev` ; `drive-agent/README.md` et `fake-auchan/README.md` ; empreinte de l'agent mise à jour ; **ordre de publication** dit dans le rapport (front avant application) ; aucune étiquette ; rapport court des hypothèses non mesurées.

## Pièges connus

- Chez Leclerc, plusieurs hypothèses (adresse sans ville, formes de la page de défi, libellés de visiteur) se sont révélées fausses à la recette : marquer tout ce qui n'est pas mesuré comme tel dans le code et dans `hypotheses-site-reel-auchan.md`.
- Ne jamais ouvrir le vrai site d'Auchan depuis un agent, un test ou l'émulateur.
- Ne pas faire passer l'adaptateur par des variables propres à Leclerc (`zcwb`, `iIdProduit`…) : ces noms sont ceux de Leclerc.
