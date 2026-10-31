# DRV-746 — La ligne `CHOSEN` du résultat dit quand le produit n'est pas au panier

- **Dépôt** : front (`drive-agent/`, protocole v1) ; la doc du protocole est dans le back (`docs/drive/architecture.md` § 8)
- **Jalon** : M7 Plusieurs enseignes (retour du propriétaire, hors ordre)
- **Taille** : S
- **Dépend de** : DRV-745 (le back coche les articles mis au panier), DRV-644 (le panier qui refuse un produit choisi)
- **Statut** : fait (front PR #290, 2026-10-31 ; commentaires renommés par la PR #291). Agent 0.54.0, application Android à publier. Effort recommandé : `high`.
- **Lire d'abord** : [`../architecture.md`](../architecture.md) § 8 ; `drive-agent/protocol/README.md` (front) ; [DRV-745](DRV-745-lignes-cochees-et-articles-au-panier.md).

## Contexte

Le site coche dans la liste les articles réellement mis au panier (DRV-747). Une ligne `ADDED` l'est toujours ; une ligne `CHOSEN` ne le prouvait pas : quand le panier de l'enseigne n'a rien pris du produit choisi (`obtained < 1`, DRV-644), l'agent enregistrait quand même le choix, et le fait « pas au panier » ne vivait que dans l'écran de fin.

## Livré

- Clé facultative `notInCart?: true` sur la ligne `CHOSEN`, émise seulement dans ce cas ; absente = au panier (agents d'avant, compatibles). Valeur `true` seulement, jamais `false`, jamais sur une autre issue.
- Protocole dans le même commit : `types.ts`, `result.ts`, `result.schema.json`, exemples valides et invalides, README du protocole. `AGENT_VERSION` 0.54.0 et empreinte `android/agent.js.sha384`.
- Aucun code Kotlin : l'application relaie le résultat telle quelle.

## Ordre de livraison

Un récepteur qui ne connaît pas la clé rejette le résultat : le **site d'abord**, l'**application Android** (qui embarque l'agent 0.54.0) ensuite. Tant que l'application n'est pas à jour, un `CHOSEN` sans clé se lit « au panier » : un produit choisi que le drive refuse est coché à tort.
