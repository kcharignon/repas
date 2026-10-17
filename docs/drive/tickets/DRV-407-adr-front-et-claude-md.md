# DRV-407 — ADR front 0008 et `CLAUDE.md` du front

- **Dépôt** : front, `docs/adr/`
- **Jalon** : M4 Front
- **Taille** : S
- **Dépend de** : DRV-404
- **Statut** : à faire
- **Lire d'abord** : `docs/adr/README.md` du front ; ADR 0003 et 0006 du front (usage du téléphone) ; [ADR 0022](../../adr/0022-remplissage-du-panier-drive.md) du back.

## Portée

1. ADR front `0008-envoi-de-la-liste-au-drive.md` (gabarit du front) : le pont `driveBridge`, le fragment d'URL, l'agent dans `drive-agent/`, le gabarit servi sans domaine et substitué à l'installation, le brouillon de passage, l'ouverture dans Chrome complet, l'absence d'image externe en v1, la version minimale de l'agent.
2. Amender les ADR 0003 et 0006 du front : le téléphone sert aussi à préparer et envoyer la liste au drive (et plus seulement à consulter et cocher en magasin).
3. `CLAUDE.md` du front : section « Drive » (où est l'agent, `make agent-build agent-test agent-e2e`, règle « jamais de code distant », « jamais de HTML brut Leclerc dans le dépôt », renvoi vers `docs/drive/README.md` du dépôt back) ; `PARITY.md` si besoin.
4. Index des ADR du front mis à jour.

## Critères d'acceptation

ADR et `CLAUDE.md` relus, cohérents avec l'ADR 0022 du back (qui gagne en cas d'écart).
