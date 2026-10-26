# VOX-309 — Envoyer le modèle d'interaction au skill sans la console

- **Dépôt** : back (`docs/voice/skill/`, node)
- **Jalon** : M3
- **Taille** : S
- **Dépend de** : VOX-307 (la CLI officielle `ask`, connectée par le propriétaire)
- **Statut** : fait (2026-10-26, demande du propriétaire : ne plus « changer à la main à chaque fois », pouvoir tout remettre après un reset)
- **Lire d'abord** : [`../skill/README.md`](../skill/README.md) (« Envoyer le modèle sans la console ») ; [`../roadmap.md`](../roadmap.md) (l'exception « Amazon » du fil principal)

## Contexte

Jusqu'ici, chaque modèle passait par la console (*JSON Editor*, presse-papiers PowerShell, *Save*, *Build skill*, puis revérifier l'endpoint). La CLI officielle sait envoyer le modèle (`ask smapi set-interaction-model`) et lire l'état du build (`ask smapi get-skill-status`) ; le fil principal l'a fait à la main pendant le réglage du modèle (2026-10-26) avant que le script existe.

## Décisions

1. `docs/voice/skill/push-model.mjs` (node pur, aucune dépendance) : vérifie que le modèle du dépôt est celui que `generate-model.mjs` produit, l'envoie à l'étape `development` uniquement, sonde le build (40 fois, 6 s) et sort 0 (construit), 1 (build échoué ou trop long) ou 2 (modèle périmé, pas d'identifiant, CLI absente ou refusée). `make voice-model-push` (`PUSH_ARGS="--dry-run"` liste les commandes sans les lancer).
2. Jamais de publication, de certification, de suppression ni de lecture de `~/.ask` ; l'identifiant du skill vient de l'environnement, jamais d'un fichier. Seul le fil principal le lance, avec l'accord du propriétaire (exception de la feuille de route).
3. Le modèle envoyé est celui du dépôt, commité : en cas de doute, renvoyer celui de `main`.
4. Auto-test avec un faux `ask` : `make voice-push-selftest` (envoi puis build réussi, build échoué, envoi refusé, pas d'identifiant). Pas en CI (node sur la machine ; `.github/workflows/` n'est pas modifiable par l'assistant).

## Critères d'acceptation

- Étant donné un modèle à jour et la CLI connectée, quand on lance `make voice-model-push`, alors le skill en développement porte ce modèle et la commande le confirme.
- Étant donné un modèle périmé, alors rien n'est envoyé et la commande dit de lancer `make voice-model`.
