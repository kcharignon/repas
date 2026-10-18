# DRV-309 — Faux site Leclerc et tests e2e de l'agent

- **Dépôt** : front, `drive-agent/fake-leclerc/` et `drive-agent/e2e/`
- **Jalon** : M3 Agent
- **Taille** : L
- **Dépend de** : DRV-303 (puis complété au fil de DRV-305 à 308)
- **Statut** : fait (PR [repas-front#92](https://github.com/kcharignon/repas-front/pull/92), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 8, 11 ; [`../recherche.md`](../recherche.md) § 2 ; configuration Playwright du front (`playwright.config.ts`, `e2e/`).

## Contexte

Leclerc ne peut pas être contacté depuis la CI (DataDome) et son HTML ne doit pas être committé. Pour tester l'agent de bout en bout, il faut un faux site qui imite la **structure** décrite dans `recherche.md` avec des données inventées.

## Portée

1. Petit serveur Node (sans dépendance lourde) dans `drive-agent/fake-leclerc/`, lancé par `make agent-fake` et par les tests : hôte local, chemins `/magasin-000001-000001/` (accueil), `recherche.aspx?TexteRecherche=` (page HTML contenant un JSON d'initialisation avec les champs `iIdProduit`, `sLibelleLigne1/2`, `nrPVUnitaireTTC`, `nrPVParUniteDeMesureTTC`, `iQteDisponible`, `iQuantitePanier`… sur ~30 produits inventés), `panier.aspx?op=1` (POST `d`, quantité absolue, état du panier en mémoire, évènement `Panier` en réponse), page de connexion simulée.
2. Comportements activables par paramètre ou en-tête de test : latence ; 403 après N requêtes ; 429 ; « contrat changé » (champs renommés) ; produit indisponible ; non connecté ; magasin différent ; `X-Frame-Options: DENY` ; page de défi.
3. Un faux « Repas » minimal (page statique à l'origine de retour de test) qui expose le fragment reçu, pour vérifier la navigation de retour.
4. Tests Playwright (`drive-agent/e2e/`, Chromium mobile type Pixel 7) qui chargent le faux site, injectent l'agent construit (gabarit avec marqueur remplacé par l'origine du faux Repas) avec un plan dans le fragment, pilotent l'interface et vérifient : état final du panier du faux site, message de résultat reçu, cadence (temps minimal entre requêtes), arrêt sur 403, reprise après rechargement, cadre natif accepté/refusé, choix d'un produit (variante retenue), mode `setup`, diagnostic.
5. `make agent-e2e` (dans Docker) et job CI dédié ; données et HTML **inventés**.

## Critères d'acceptation

- Les scénarios ci-dessus passent en CI.
- Le faux site ne contient aucun contenu copié de Leclerc ; son README dit qu'il est synthétique.
- Un test échoue si l'agent envoie deux requêtes en parallèle ou plus vite que la cadence minimale.

## Tests exigés

Les tests e2e eux-mêmes ; revue que les fixtures n'ont pas de HTML réel.

## Definition of Done

Celle du front. Ne prouve pas DataDome : la recette réelle est DRV-505.

## Réalisation (2026-10-18, PR repas-front#92)

Faux site dans `drive-agent/fake-leclerc/` (serveur Node sans dépendance, 30 produits inventés, écoute 127.0.0.1 seulement, forme exacte du site mesurée : `zcwb`, adresse du panier sans suffixe de ville, identifiant en texte, contexte à l'ajout seulement, `lstProduitsLight`), pilotable par HTTP (`POST /__fake/behavior`, `POST /__fake/cart`, `GET /__fake/state`, hôtes via `FAKE_LECLERC_HOSTS`, port via `FAKE_LECLERC_PORT`). Tests Playwright Pixel 7 dans `drive-agent/e2e/` (28 tests, 15 s) par fragment et par le pont (vrai `injection.js` + faux canal natif). Cibles `make agent-e2e` (alias `make e2e-agent`) et `make agent-fake` (Node de la machine). Le cadre natif n'est pas testé (P1 abandonné). Constats : l'agent ne peut pas poser son interface si le plan arrive avant la fin du chargement (envoyer le plan après `hello` et chargement terminé) ; après un rechargement la cadence repart de zéro. Reste pour DRV-319 partie 2 : HTTPS avec l'autorité de test et accès depuis l'émulateur.
