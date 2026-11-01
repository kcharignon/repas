# DRV-755 — Accepter le magasin que la personne a choisi (au lieu de s'arrêter)

- **Dépôt** : front (`drive-agent/`, `android/`, `src/features/drive`) ; protocole v1 si une clé est ajoutée (règle du README du protocole)
- **Jalon** : M7 Plusieurs enseignes (décision du propriétaire du 2026-11-01, « piste 1 »)
- **Taille** : M
- **Dépend de** : DRV-753 (guide du choix du magasin), DRV-754 (version mobile)
- **Statut** : fait (front PR #301, 2026-11-01, agent 0.60.0 ; la clé `chosenStore` du résultat est décrite dans `architecture.md` § 8) ; **ordre de livraison : le site d'abord, puis l'application** (avec DRV-757) ; à rejouer sur le téléphone de test
- **Lire d'abord** : `drive-agent/src/flow/orchestrator.ts` (`STORE_MISMATCH`), `src/features/drive/` (réglages du drive, `setup-result`), `docs/drive/architecture.md` § 4

## Constat

Au premier usage, le guide dit « Choisis ton magasin ». Si la personne en choisit un autre que celui retenu par Repas, l'agent s'arrête : « Ce n'est pas le bon magasin », puis « L'envoi s'est arrêté — Le magasin ouvert sur Leclerc n'est pas le tien ». Reproduit deux fois à la recette du 2026-11-01. Repas ne connaît que le **numéro** du magasin enregistré (le plan porte `store {id, host}`, sans libellé) : la personne ne peut pas savoir lequel choisir. C'est une impasse que la carte ne dit pas.

## Décision du propriétaire

**Accepter le magasin choisi** (piste 1) : quand la personne arrive, à l'étape « choisis ton magasin », sur la page d'un autre magasin, Repas le **retient** (après une confirmation claire) et l'envoi **continue** avec ce magasin.

## À faire

1. À l'étape guidée du choix du magasin, un magasin différent de celui du plan n'est plus un arrêt : la carte dit en une phrase quel magasin a été ouvert (son nom de ville si la page le montre) et propose **« Utiliser ce magasin »** (un geste) et « Choisir un autre magasin » ; jamais un bouton « Fermer ».
2. « Utiliser ce magasin » : Repas enregistre le nouveau magasin (le même enregistrement que la lecture du magasin des réglages, `setup-result`) puis **reprend l'envoi** avec un plan recalculé pour ce magasin (les produits habituels d'un autre magasin ne sont pas ceux de celui-ci : le plan se refait, rien n'est deviné). Le protocole décide comment l'agent le dit à Repas (une clé facultative ou un résultat dédié) ; toute clé nouvelle est documentée dans `protocol/README.md`, `result.schema.json`, les exemples, et le dépôt back (`architecture.md` § 8).
3. La liste de la personne est gardée à chaque étape ; « Arrêter pour l'instant » reste la seule sortie, avec sa phrase rassurante.
4. Les écrans suivent `repas-po` (tutoiement, 15 mots au plus, aucun jargon). Maquette Claude Design de la carte « Tu as ouvert <ville> ».
5. Tests : faux site Leclerc (deux magasins), e2e de l'agent, test du front ; recette réelle sur le téléphone de test.

## Ordre de livraison

Agent et application (nouvelle version de l'application), site si le front change (`vX.Y.Z` du site, approuvé après le back s'il change, **jamais deux releases du site à la fois**). Le propriétaire approuve chaque déploiement.
