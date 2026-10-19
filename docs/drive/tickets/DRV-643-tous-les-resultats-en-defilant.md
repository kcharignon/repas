# DRV-643 — Tous les résultats de la recherche, affichés en défilant

- **Dépôt** : front (`drive-agent/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-633 (photos)
- **Statut** : en cours (demande de l'utilisateur du 2026-10-19 : « 5 produits, c'est pas assez, je dois pouvoir scroller (lazy loading) pour que tu m'affiches autant de produits que tu en as dans le résultat »). Effort : `high`.

## Contexte

L'écran de choix de l'agent (phase B) plafonnait la liste à 8 candidats (`MAX_CANDIDATES`) et les photos à 12, alors que la page de recherche du site en contient davantage.

## Portée

1. Plus de plafond de 8 : tous les produits de la page de recherche, dans l'ordre du site (plafond technique de sécurité).
2. Affichage **progressif** : un premier lot, la suite quand la personne défile ; les photos se chargent à la demande, lot par lot.
3. Aucune requête réseau de plus : la suite d'une éventuelle pagination du site est une suite (à mesurer, DataDome).
4. Protocole inchangé ; l'agent passe en 0.28.0.

## Hors portée

Pagination du site au-delà de la première réponse, classement par rayon (DRV-642).

## Critères d'acceptation

- Étant donné une recherche de 40 produits, tous sont atteignables en défilant, sans geste autre que le défilement.
- Le résultat envoyé à Repas est identique octet pour octet (hors ligne choisie).

## Tests exigés

Vitest (lots, sentinelle), faux site de 40 produits, `make agent-e2e`, Playwright `agent-ui` (défilement, clair et sombre, axe), captures lues.

## Definition of Done

Celle du dépôt front. Part avec une application.
