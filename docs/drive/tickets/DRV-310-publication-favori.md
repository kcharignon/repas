# DRV-310 — Publication du favori (version, empreinte, journal)

- **Dépôt** : front
- **Jalon** : M3 Agent
- **Taille** : S
- **Dépend de** : DRV-306
- **Statut** : remplacé (le favori est abandonné, voir `../pivot-application-android.md`) : la publication de l'agent se fait avec celle de l'application, DRV-315 ; la version minimale de l'agent n'existe pas (`../runbook.md` § 7.5)
- **Lire d'abord** : DRV-301 (build), DRV-403 (installation côté front), [`../architecture.md`](../architecture.md) § 10.

## Contexte

Une mise à jour de l'agent oblige à réinstaller le favori. Il faut donc des versions claires, un journal des changements, une empreinte vérifiable et une version minimale exigée par Repas.

## Portée

1. `drive-agent/CHANGELOG.md` (une entrée par version, en français, avec « réinstaller le favori : oui/non »).
2. Le gabarit construit est servi par le front comme ressource statique (`/drive/agent.template.js`, avec `manifest.json`), sans domaine dedans ; le front le télécharge à l'installation (DRV-403) et fabrique la chaîne du favori.
3. `drive-agent/README.md` : comment reconstruire et vérifier le `sha256` du gabarit (commande Docker), comment auditer le code, ce que l'agent fait et ne fait pas (extrait des invariants).
4. Version minimale : constante `MIN_AGENT_VERSION` côté front (DRV-403) comparée à l'`agentVersion` des résultats ; la procédure pour la relever est écrite ici.
5. Étape de publication : à chaque version, la CI affiche la taille et l'empreinte dans le résumé du job.

## Critères d'acceptation

- Le gabarit servi par le front est identique (octet pour octet) à `drive-agent/dist/agent.template.js` de la construction et son hachage correspond au manifeste.
- Le README permet à un tiers de reconstruire et de comparer l'empreinte.

## Tests exigés

Test de cohérence manifeste/gabarit ; contrôle CI de reproductibilité (DRV-301).

## Definition of Done

Celle du front.

## Mise à jour G1 (2026-10-17)

Le favori n'est plus le véhicule principal (Chrome Android refuse les favoris JavaScript). Ce ticket devient « **Publication de l'agent** » : version, journal des changements et empreinte de `agent.js` (consommés par DRV-313 et DRV-315). La chaîne de favori pour Chrome de bureau est **facultative** (gros favori ou chargeur depuis un hébergement figé par empreinte) et peut être faite en dernier.
