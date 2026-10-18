# DRV-614 — Le retour de l'agent ne retient que ce que le plan demandait

- **Dépôt** : front (écran de retour, `src/`)
- **Jalon** : M2c Durcissement (revue de sécurité du front, constat C1)
- **Taille** : S
- **Dépend de** : DRV-405, DRV-613
- **Statut** : à faire
- **Lire d'abord** : `docs/revue-securite-drive.md` du dépôt front (C1), [`DRV-405`](DRV-405-retour-et-recapitulatif.md), [`DRV-613`](DRV-613-durcissement-fiches-produits.md), skill `repas-front-dev`, `docs/drive/consignes-agents.md`.

## Contexte

L'agent s'exécute dans le même monde JavaScript que la page de Leclerc. Un script de cette page (Leclerc, un service tiers ou une faille chez eux) peut lire le plan et poster un faux `result` portant le bon `runId`. L'application ne contrôle que le type et le `runId`, et l'écran de retour enregistre aussitôt les choix (`persist()` au montage de `DriveReturnView`). Décision de l'assistant (2026-10-18), option C de la revue : **ne retenir que les lignes présentes dans le plan**, sans demande de confirmation supplémentaire (l'option B « Enregistrer ces choix ? » reste possible plus tard). Les choix sont privés à l'utilisateur et le back protège désormais les fiches produits (DRV-613 partie 1) : le risque résiduel est faible.

## Portée

1. À la réception d'un résultat, le front **recoupe chaque ligne du résultat avec le plan du passage** (le brouillon de passage garde le plan ou ses lignes, sinon le relire) : une ligne dont l'`ingredientSlug` n'est pas dans le plan est **ignorée** (jamais enregistrée) et comptée dans un message discret du récapitulatif (« N lignes inattendues ignorées »). Un produit de résultat d'une ligne `KNOWN` dont l'identifiant ne correspond ni au produit du plan ni à un remplacement annoncé est traité comme `CHOSEN` seulement si la ligne du plan était `NEW` ou avait un `replaces`.
2. Plafonds du back : `PUT /drive/choices` et `PUT /drive/choices/{slug}` peuvent répondre `422` `DRIVE_LIMIT_REACHED` ; le front l'affiche comme une erreur lisible distincte de `DRIVE_CHOICE_INVALID` (texte dans `src/i18n/fr.ts` : trop de produits mémorisés ou trop d'écritures aujourd'hui, réessayer demain / oublier des produits dans « Mes produits »).
3. `POST /shopping-lists/{id}/reopen` répond `204` même si `GET /drive/settings` renvoie `enabled: false` : le bouton « Rouvrir » d'une liste `SENT` reste proposé dans ce cas (le bouton « Envoyer » reste masqué).
4. Tests unitaires (lignes inattendues ignorées, plan sans la ligne, résultat sans plan retrouvé), composant (message `DRIVE_LIMIT_REACHED`, « Rouvrir » interrupteur coupé), e2e existants verts, `npm run check`, test visuel Chromium des écrans modifiés (captures lues).

## Hors portée

Demande de confirmation avant enregistrement (option B), changement du protocole ou de l'agent.

## Critères d'acceptation

- Un résultat contenant une ligne absente du plan n'enregistre rien pour cette ligne et le signale.
- Un `422 DRIVE_LIMIT_REACHED` affiche son propre message.
- Interrupteur coupé, une liste `SENT` se rouvre depuis l'écran de la liste.

## Definition of Done

Celle du dépôt front ; PR concise ; fusion seulement si tous les contrôles sont verts ; section « Réalisation » ajoutée à ce ticket (dans le dépôt back, par l'assistant) — l'agent la décrit dans sa PR.
