# DRV-307 — Mode configuration (lecture du magasin)

- **Dépôt** : front, `drive-agent/src/flow/`
- **Jalon** : M3 Agent
- **Taille** : S
- **Dépend de** : DRV-302, DRV-303
- **Statut** : fait (PR [repas-front#82](https://github.com/kcharignon/repas-front/pull/82), 2026-10-18)
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 8 (type `setup`) ; DRV-203 (réglages du drive).

## Contexte

Le magasin n'est pas saisi à la main : l'utilisateur ouvre son drive Leclerc (magasin choisi), lance le favori en mode configuration, et l'agent renvoie à Repas les informations du magasin.

## Portée

1. Quand le message est de type `setup` : valider (aucune ligne), lire le magasin courant (`readStore`), afficher « Enregistrer ce magasin dans Repas ? » avec le libellé lu (ou l'identifiant), puis, sur confirmation, naviguer vers `REPAS_ORIGIN + '/drive/retour#result=…'` avec un message `setup-result` : `{v:1, type:'setup-result', runId, agentVersion, store:{id, host, baseUrl, label|null}}` où `baseUrl = https://<host>/magasin-<id>-<id>`.
2. Aucune requête réseau n'est nécessaire (lecture de l'URL et de la page). Page hors Leclerc ou sans magasin : `UNSUPPORTED_PAGE`.
3. Annulation : résultat `aborted`.

## Critères d'acceptation

- Sur une page `https://fd9-courses.leclercdrive.fr/magasin-053701-053701/…` (fictive en test), le résultat contient l'identifiant, l'hôte et l'URL de base exacts.
- Aucune donnée de compte n'est lue ni renvoyée.

## Tests exigés

Vitest (adaptateur et `location` simulés) ; cas de page invalide ; e2e DRV-309.

## Definition of Done

Celle du front.

## Point laissé par DRV-303 (2026-10-17)

Le `setup-result` se construit avec `adapter.readStore()` (`StoreInfo { id, host, baseUrl, label }`, `label` null sauf si `readLabel` est fourni).

## Réalisation (2026-10-18, PR repas-front#82)

`setupHandler` (`drive-agent/src/flow/setup.ts`) lit le magasin de la page sans requête, affiche l'écran `store`, renvoie `setup-result`. Annuler envoie un `result` `aborted` sans ligne. Le contrôle « retour possible vers Repas » passe avant le `setup`, comme pour un plan.
