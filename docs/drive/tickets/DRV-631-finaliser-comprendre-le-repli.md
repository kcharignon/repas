# DRV-631 — « Finaliser sur Leclerc » : voir pourquoi il retombe sur l'accueil, puis le corriger

- **Dépôt** : front (`android/`, `drive-agent/src/leclerc/diagnostic*`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S (diagnostic) puis S (correctif)
- **Dépend de** : DRV-629
- **Statut** : à faire (constat réel du 2026-10-19, [`hypotheses-site-reel.md`](../hypotheses-site-reel.md) § 11)
- **Lire d'abord** : [`DRV-629`](DRV-629-finaliser-et-entrer-par-les-boutons.md) (§ Réalisation), `hypotheses-site-reel.md` §§ 10 et 11, skills `repas-android-dev`, `repas-agent-dev`, `repas-po`.

## Contexte

Sur le vrai site (Pixel 6, `android-v0.1.8`), « Finaliser sur Leclerc » ouvre l'accueil du drive au lieu de `…/detail-panier.aspx` : l'application est retombée sur le repli. La page du panier existe bel et bien (`/magasin-N-N-V/detail-panier.aspx`, ouverte à la main par l'icône du panier). L'application ne journalise rien d'exploitable (release) : on ne sait pas si le chemin avec ville n'était pas mémorisé, si la validation a refusé l'adresse, si le site a répondu 4xx ou **redirigé** vers l'accueil (un accès direct à la page, sans passer par le site, est peut-être refusé).

## Portée

1. **Voir la cause** : l'application garde en mémoire, sans adresse ni numéro, la **raison du dernier repli** de « Finaliser » (`CHEMIN_INCONNU`, `ADRESSE_REFUSEE`, `ERREUR_HTTP_4xx`, `ERREUR_HTTP_5xx`, `REDIRIGEE_VERS_ACCUEIL`, `OUVERTE`) et la montre dans le **diagnostic** (ligne « Dernier « Finaliser » : … »), via le pont existant (aucun champ nouveau de protocole : le diagnostic de l'agent lit déjà des formes ; sinon une ligne dans le menu « Version et mode d'emploi »). Détecter aussi la **redirection** : si l'adresse finale n'est pas celle de la page du panier, c'est un repli, pas une ouverture.
2. **Le corriger** selon la cause relevée à la recette suivante : par exemple, si le site refuse l'accès direct, mener au panier **par le site lui-même** (toucher l'icône du panier : une aide « Touche l'icône du panier en haut à droite » dans le bandeau d'arrivée sur l'accueil) plutôt que par une adresse.
3. Texte du bandeau d'arrivée sur l'accueil quand le repli a eu lieu : « Ton panier est dans l'icône en haut à droite. » (tutoiement, 15 mots, aucun avertissement).

## Critères d'acceptation

- Étant donné chaque cause (faux site : 404, 500, redirection vers l'accueil, chemin non mémorisé), quand l'utilisateur touche « Finaliser », alors le diagnostic donne la raison correspondante et l'application ne boucle pas.
- Une redirection vers l'accueil est comptée comme un repli.

## Tests exigés

Faux site (`fake-leclerc`) : `basketStatus` 404, 500, redirection ; tests Kotlin du motif de chemin et de la raison ; `make android-e2e` (scénarios 15 à 18 complétés) ; recette réelle à rejouer.

## Definition of Done

Celle du dépôt front ; agent incrémenté seulement si `strings.ts` ou le diagnostic JS changent ; à publier après le front et l'application selon la règle habituelle.
