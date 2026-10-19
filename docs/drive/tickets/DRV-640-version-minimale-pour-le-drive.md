# DRV-640 — Une application trop ancienne ne lance pas le drive

- **Dépôt** : front (web seulement)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : —
- **Statut** : fait (PR front #160, site v0.5.7, 2026-10-19) ; demande de l'utilisateur : le téléphone de sa compagne ne peut pas être mis à jour à tout moment ; « il faut que l'application sache gérer si elle n'est pas dans la bonne version et se bloquer si besoin »). Effort : `high`.
- **Lire d'abord** : `docs/drive/principes-produit.md` (règles 3 et 10), [DRV-637](DRV-637-version-de-l-application-connue-du-serveur.md) (version connue du serveur : suite), skills `repas-po`, `repas-front-dev`.

## Contexte

Le site est chargé en direct dans la WebView : il change sans rien installer. L'application, elle, ne change qu'à l'installation. Une ancienne application fait donc échouer en silence des fonctions récentes (« Finaliser » page introuvable, libellés refusés par le back). Comme le contrôle vit dans le web, il atteint aussi les téléphones qu'on ne peut pas mettre à jour.

## Portée

1. Constante unique `MIN_DRIVE_APP_VERSION` (0.1.10 au départ) et comparateur numérique (0.1.9 < 0.1.10).
2. Dans l'application, version inférieure au minimum **ou inconnue** : « Envoyer au drive » n'ouvre ni Leclerc ni l'agent ; une phrase (« Mets à jour l'application pour envoyer ta liste. »), le bouton « Mettre à jour » (page `/drive/aide`, DRV-638) et le repli « Chercher à la main » toujours accessible.
3. Version suffisante, ou navigateur : rien ne change. Recettes, listes et le reste de Repas ne sont jamais bloqués.

## Hors portée

Minimum fourni par le serveur (`appUpdate.minimum` de DRV-637), blocage forcé de tout le produit, iPhone.

## Critères d'acceptation

- Étant donné une application 0.1.8, « Envoyer au drive » montre la phrase et le bouton, sans ouvrir Leclerc.
- Étant donné une application sans version connue, même résultat.
- Étant donné 0.1.10 ou plus, ou un navigateur, aucun changement.

## Tests exigés

Table du comparateur ; Vitest de la vue (ancienne, inconnue, suffisante, navigateur) ; e2e avec un pont natif simulé ; capture à 360 px lue.

## Definition of Done

Celle du dépôt front. Part avec une release du site.

## Pièges connus

- La version vient de l'application (source non fiable) : le contrôle ne fait que **refuser** une fonction, jamais accorder un droit.
- Relever `MIN_DRIVE_APP_VERSION` demande une release du site ; tant que le serveur ne fournit pas le minimum (DRV-637), ne pas le relever avant que l'application correspondante soit publiée.

## Réalisation (PR front #160, fusionnée le 2026-10-19)

- `MIN_DRIVE_APP_VERSION = '0.1.10'` (un seul endroit, `appVersion.ts`) et un comparateur numérique (0.1.9 < 0.1.10).
- Écran « Envoyer au drive » dans l'application, version inférieure ou inconnue : ni Leclerc ni l'agent ; une phrase (« Mets à jour l’application pour envoyer ta liste. »), un bouton « Mettre à jour » vers `/drive/aide`, et les liens de recherche par article (repli existant).
- Version suffisante ou navigateur : rien ne change ; passage déjà commencé, « Finaliser » et « Retour » non concernés. Web seulement, aucun changement Kotlin ni agent.
- Écarts : version inconnue (le pont ne répond pas) tenue pour trop ancienne ; les constructions de débogage (`0.0.0-dev`) sont tenues pour suffisantes ; `make android-e2e` non joué. À relever : la constante, quand une version de l'application devient nécessaire.
