# DRV-711 — Runbook, recette, skills et documentation « par enseigne »

- **Dépôt** : back (`docs/drive/`, `docs/production.md`, `.claude/skills/`) puis front (`CLAUDE.md`, `.claude/skills/`, `android/*.md`, `drive-agent/*.md`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-701 à DRV-710 (au fil de l'eau : chaque ticket met à jour ce qu'il change, ce ticket vérifie l'ensemble)
- **Statut** : à faire. Effort recommandé : `medium` (documentation).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) § 3.6 ; [`../runbook.md`](../runbook.md) ; [`../recette.md`](../recette.md) ; [`../hypotheses-site-reel.md`](../hypotheses-site-reel.md) ; skills `repas-ops`, `repas-drive-back`, `repas-agent-dev`, `repas-android-dev`, `repas-front-dev`, `repas-po`.

## Contexte

La documentation du drive décrit **un** drive. Elle n'est pas fausse, mais elle ne doit pas être recopiée telle quelle pour Auchan, et les consignes des agents doivent dire où ajouter une enseigne.

## Portée

1. **`architecture.md`** : § 1 (glossaire : enseigne, registre, drive habituel), § 5.1/5.2/5.3 (profil, nouvelle table, nouveaux cas d'usage), § 7 (routes, `selection`, `?retailer=`), § 8 (énumération `retailer`, `app.info.retailers`), § 12 (ordre de publication par enseigne).
2. **Runbook** (`runbook.md`) : « <Enseigne> a changé » devient un modèle par enseigne ; couper **une seule** enseigne (`DRIVE_RETAILERS`) ; la section Leclerc reste telle quelle.
3. **Recette** : `recette.md` garde la recette de Leclerc ; un gabarit « recette d'une nouvelle enseigne » (parties 3 à 12 reformulées sans nom) à copier pour Auchan.
4. **Hypothèses** : `hypotheses-site-reel.md` est marqué « Leclerc » ; un fichier jumeau est prévu pour Auchan (écrit par DRV-715 d'après les spikes).
5. **Skills** : une section « Ajouter une enseigne » dans chacun des cinq skills techniques (où est le registre de la couche, ce qu'il faut ajouter, quels tests), et la règle « ne pas durcir Leclerc » ; `repas-po` : le glossaire gagne « Mon drive habituel » et la règle d'élision.
6. **`CLAUDE.md`** des deux dépôts : la ligne du chantier et la règle d'architecture.
7. **ADR 0027** : passer à `Accepted` quand l'utilisateur a validé la règle de choix (D-M1) ; mettre à jour la liste des tickets réalisés.
8. **Vérifier les liens relatifs** de toute la documentation touchée.

## Hors portée

Du code ; des textes d'interface ; un contenu propre à Auchan avant DRV-715.

## Critères d'acceptation

- Un agent qui ouvre `consignes-agents.md`, `multi-enseignes.md` et le skill de sa couche sait **où** ajouter une enseigne et **ce qu'il ne doit pas faire**.
- Aucun lien relatif ne mène à un fichier absent (contrôle par `grep` des liens).
- Le runbook explique comment couper Auchan sans toucher à Leclerc.

## Tests exigés

Relecture des liens relatifs ; `make quality` du back si des fichiers de l'API sont touchés (aucun attendu).

## Definition of Done

Celle d'un ticket de documentation : commits courts en français, PR « Quoi / Vérifié / À relire », roadmap cochée.

## Pièges connus

- Ne pas écrire de fait sur Auchan non confirmé par un spike (`multi-enseignes.md` § 11 classe chaque fait).
- Une convention changée = ADR, `CLAUDE.md`, skill et roadmap dans le même commit (règle du dépôt).
