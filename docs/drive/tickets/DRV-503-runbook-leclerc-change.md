# DRV-503 — Runbook : « Leclerc a changé »

- **Dépôt** : back (`docs/` et skill `repas-ops`)
- **Jalon** : M5 Intégration et livraison
- **Taille** : S
- **Dépend de** : DRV-501
- **Statut** : à faire
- **Lire d'abord** : [`../architecture.md`](../architecture.md) §§ 8, 10 ; DRV-308 (diagnostic).

## Portée

Écrire `docs/drive/runbook.md` : symptômes (messages `CONTRACT_CHANGED`, `BLOCKED`, résultats vides), contrôle par le diagnostic, désactivation immédiate (`DRIVE_ENABLED=false` : les écrans disparaissent, les listes `SENT` restent lisibles et se terminent), repli en mode assisté, procédure pour corriger l'adaptateur (DRV-303) à partir d'un nouvel échantillon synthétique, reconstruire l'agent, relever la version, faire réinstaller le favori, vérifier l'empreinte, relever `MIN_AGENT_VERSION`, qui prévenir. Ajouter le renvoi dans le skill `repas-ops`.

## Critères d'acceptation

Une personne qui n'a pas écrit le code peut suivre le runbook pour couper la fonction puis publier une nouvelle version du favori.
