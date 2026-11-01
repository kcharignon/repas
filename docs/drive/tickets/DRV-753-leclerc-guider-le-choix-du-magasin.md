# DRV-753 — Leclerc : guider le choix du magasin au premier usage

- **Dépôt** : front (`drive-agent/`, `android/`) ; la doc de l'agent est dans le back (`docs/drive/architecture.md` § 4 étape 5)
- **Jalon** : M7 Plusieurs enseignes (constat de la recette du 2026-11-01, hors ordre)
- **Taille** : M
- **Dépend de** : DRV-751 (parcours guidé, agent 0.56.0), DRV-625, DRV-629 et DRV-748 (entrer dans le magasin)
- **Statut** : fait (front PR #298, 2026-11-01, agent 0.58.0), application `android-v1.4.1` à approuver ; **à rejouer sur le vrai Leclerc** (installation neuve : déconnexion, magasin, connexion)
- **Lire d'abord** : `drive-agent/src/flow/guide.ts` (`guideStepOf`), `drive-agent/src/flow/orchestrator.ts` (`enterStore`), `drive-agent/src/leclerc/adapter.ts` (`storeChosenOnPage` absent, `guideTarget`, `onLoginPage`), `android/README.md` (`StoreBanner`), `docs/drive/hypotheses-site-reel.md`

## Contexte

Recette réelle du 2026-11-01 sur Leclerc (téléphone de test, application 1.4.0, agent 0.57.0, même empreinte que la release). Le propriétaire se déconnecte de Leclerc (menu de l'application, « Se déconnecter de Leclerc », qui efface cookies, données du site et chemin du magasin) puis lance un envoi. Aucun magasin n'est choisi.

L'application ouvre `https://www.leclercdrive.fr/` : le mur de cookies, puis l'accueil **national** de Leclerc (recherche par code postal, « Me géolocaliser », « Se connecter »). Cette page n'a **pas** de « Commencer mes courses » : ce bouton n'existe qu'une fois un magasin choisi. Le propriétaire ne voit **aucune carte du guide**, seulement une ligne « Touche « Commencer mes courses » » qui désigne un bouton absent. C'est le défaut qu'il a reproché à Auchan le 2026-10-31 (« hyper contre-intuitif »), resté entier chez Leclerc.

Cause lue dans le code (non prouvée sur l'appareil : le WebView est protégé, aucune capture) : le guide « Choisis ton magasin » ne vaut que pour une enseigne qui sait lire sur la page qu'un magasin est choisi (`storeChosenOnPage`, Auchan seulement) ; Leclerc ne guide que la connexion. Sur l'accueil sans magasin, `enterStore` n'a ni bouton ni lien à suivre et finit par le message « Touche « Commencer mes courses » » ; le bandeau natif `StoreBanner.OPEN` dit la même chose.

## À faire

1. Sur l'accueil de l'enseigne sans « Commencer mes courses » (après l'attente d'entrée), l'agent pose la carte du guide « Étape 1 sur 2 · Choisis ton magasin », avec un texte propre à Leclerc (le code postal), entoure le champ de recherche s'il le désigne sûrement, attend et continue tout seul quand « Commencer mes courses » apparaît (entrée de DRV-625, DRV-629, DRV-748), puis l'étape de connexion de DRV-751.
2. Le message « Touche « Commencer mes courses » » (agent et bandeau natif) ne s'affiche plus quand ce bouton n'est pas dans la page.
3. La carte reste visible et touchable devant le mur de cookies de Leclerc, sans empêcher d'en toucher les boutons.
4. Textes : voix de `repas-po` (tutoiement, 15 mots au plus, aucun jargon), un seul bouton secondaire « Arrêter pour l'instant » qui dit que la liste est gardée, jamais « Fermer ». Carte et maquette inchangées (maquette Claude Design du parcours guidé).
5. Tests : faux site Leclerc avec un accueil national sans magasin et un mur de cookies plein écran, e2e de l'agent, test Android du bandeau. `AGENT_VERSION` 0.58.0, empreinte `agent.js.sha384` régénérée.

## Ordre de livraison

L'agent change : une nouvelle version de l'**application Android** (`android-v1.4.1`), approuvée par le propriétaire, puis `ecrire-app-version.sh latest 1.4.1` par lui. Ni le back ni le site ne changent.

## Hors périmètre

Cliquer ou saisir à la place de la personne (l'agent n'écrit jamais le code postal) ; durcir Leclerc au-delà de ce besoin ; deviner les sélecteurs du vrai site (ce qui n'est pas prouvé sur le vrai site est dit dans la PR).
