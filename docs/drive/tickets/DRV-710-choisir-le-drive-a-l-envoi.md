# DRV-710 — Choisir le drive à l'envoi (R1 à R5), passage et retour par enseigne

- **Dépôt** : front (web : `DriveSendView.vue`, `buildPlanMessage.ts`, `bridge/`, `useDriveReturn.ts`, `ShoppingListHeader.vue`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-703, DRV-707, DRV-708, DRV-709
- **Statut** : fait (PR front #257, 2026-10-30). Le parcours à un drive est **inchangé** (DOM, appels d'API, messages du pont et nombre de touches comparés à `main`). Écarts : un résultat ne dit pas son enseigne, le contrôle compare la forme de `store.id` à celle du brouillon (`isFromDraft`) ; « Envoyer ailleurs » n'apparaît que sur les écrans qui restent (erreur, « Recommencer », « Revenir à l'envoi ») quand un drive habituel ouvre l'écran tout seul, sans entrée depuis le récapitulatif : **à trancher côté produit** ; en navigateur, pas de tuiles. Relevé de touches : un drive inchangé ; deux drives sans habituel : une touche (« Ouvrir {nom} ») si la présélection convient, une de plus pour changer. Effort recommandé : `high`.
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 5, 8, 9 ; [ADR 0027](../../adr/0027-plusieurs-enseignes-de-drive.md) décisions 5, 8, 9 ; [`../principes-produit.md`](../principes-produit.md) ; skills `repas-front-dev`, `repas-po` ; DRV-404, 405, 622, 640, 641.

## Contexte

L'écran d'envoi suppose une enseigne. Avec plusieurs, il applique la règle de choix (le back la calcule : `selection {mode, retailer, candidates}`), n'ouvre que le drive d'une enseigne que l'application annonce, et le passage puis le retour portent l'enseigne.

## Portée

1. **Règle de choix** lue de `selection` : `NONE` → le bouton « Choisir mon magasin {nom} » (une seule enseigne activée : lecture directe du magasin ; plusieurs : d'abord « Quel drive utilises-tu ? » en tuiles) ; `AUTO` → le drive s'ouvre sans question, avec le lien « Envoyer ailleurs » seulement s'il y a au moins deux enseignes configurées ; `ASK` → tuiles des candidates, présélection fournie par le back, un bouton principal « Ouvrir {nom} ». Avec une seule enseigne : écran et touches **inchangés**.
2. **Plan** demandé avec `?retailer=` (enseigne choisie) ; `buildPlanMessage` utilise `plan.retailer` (déjà le cas).
3. **Dernière utilisée** : quand la personne touche « Ouvrir {nom} », `PUT /drive/last-retailer` part avant `drive.start` (une erreur n'empêche pas l'ouverture : elle ne perd qu'une présélection).
4. **Application** : l'enseigne n'est proposée que si `app.info.retailers` la contient (absent = `["leclerc"]`) ; sinon la phrase « Mets à jour l'application pour envoyer ta liste à {nom}. » avec le bouton « Mettre à jour » et le repli « Chercher à la main », et les autres enseignes restent proposées (DRV-640 étendue : `MIN_DRIVE_APP_VERSION` reste le seuil du noyau).
5. **Brouillon de passage** `{runId, listId, retailer, startedAt}` ; au retour, un résultat dont le brouillon porte une autre enseigne est ignoré (message existant d'un `runId` différent) ; les choix sont enregistrés par `PUT /drive/choices` pour **l'enseigne du brouillon** et le magasin du plan.
6. **Récapitulatif et erreurs** : « dans ton panier {nom} », « Finaliser sur {nom} » (lien du registre), « Chercher à la main sur {nom} ».
7. **Liste envoyée** : « Reprendre l'envoi » rouvre l'écran d'envoi avec la règle ; le bandeau « Envoyée au drive le … » est inchangé.

## Hors portée

Comparer les enseignes (backlog), mémoriser l'enseigne sur la liste (ADR 0027 décision 9), un envoi simultané aux deux.

## Critères d'acceptation

- Étant donné Leclerc seul, alors le parcours heureux du premier et du deuxième envoi a **le même nombre de touches** qu'avant (relevé avant/après, `audit-simplicite.md`).
- Étant donné deux enseignes configurées sans drive habituel et la dernière utilisée B, alors l'écran propose A et B avec B présélectionnée, « Ouvrir B » ouvre B en une touche, et `PUT /drive/last-retailer` part avec B.
- Étant donné un drive habituel A, alors l'envoi ouvre A sans question ; « Envoyer ailleurs » change l'enseigne pour cet envoi seulement.
- Étant donné une application qui n'annonce pas A, alors la phrase de mise à jour pour A, et B reste proposée.
- Étant donné un résultat revenu pour une autre enseigne que celle du brouillon, alors rien n'est enregistré.

## Tests exigés

Vitest (table des états R1 à R5 et du bouton principal, brouillon, retour d'une autre enseigne, annonce de l'application absente/présente) ; Playwright (Pixel 7) avec pont natif simulé et faux back à deux enseignes ; test du glossaire ; **captures lues** (mobile 360 px, clair et sombre) ; `make check`.

## Definition of Done

Celle du dépôt front ; touches avant/après et captures dans « À relire » ; types de l'API régénérés ; `CLAUDE.md` du front à jour ; roadmap du back cochée par l'assistant.

## Pièges connus

- Le front **n'implémente pas** la règle : il lit `selection`. Ne pas la recopier côté web (une seule règle, testée dans le back).
- Écrire « dernière utilisée » au moment de l'ouverture, pas au retour : un envoi interrompu compte aussi.
- Ne pas remplacer le plafond de 60 écritures par minute : `last-retailer` en est une.
- Un envoi sans magasin pour l'enseigne choisie doit mener au bouton « Choisir mon magasin {nom} » (règle 6), jamais à un message qui renvoie ailleurs.
