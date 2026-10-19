# DRV-631 — « Finaliser sur Leclerc » : voir pourquoi il retombe sur l'accueil, puis le corriger

- **Dépôt** : front (`android/`, `drive-agent/src/leclerc/diagnostic*`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S (diagnostic) puis S (correctif)
- **Dépend de** : DRV-629
- **Statut** : fait (PR front #157, fusionnée le 2026-10-19) ; origine : (constat réel du 2026-10-19, [`hypotheses-site-reel.md`](../hypotheses-site-reel.md) § 11)
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

## Constat du 2026-10-19 après-midi : « Finaliser » tombe sur « page introuvable » (application `0.1.8`, téléphone d'une utilisatrice)

Sur le téléphone d'une utilisatrice, `android-v0.1.8`, « Finaliser sur Leclerc » ouvre la **page « introuvable » de Leclerc** : l'adresse directe `…/detail-panier.aspx` n'est pas fiable (Leclerc répond « introuvable » sans passer par son site, avec un statut que le repli de `DriveEntry` ne reconnaît pas comme une erreur ; sur le Pixel 6 de test la même version retombe sur l'accueil). **Décision du PO (urgente, réversible)** : ne plus naviguer vers `detail-panier.aspx`.

### Portée urgente (remplace les points 1 à 3 pour la première livraison)

1. « Finaliser sur Leclerc » ouvre, dans le drive de l'application, **la page d'accueil du magasin avec la ville** (`https://<hôte>/magasin-N-N-Ville`, le chemin mémorisé par `StorePathMemory`, validé comme aujourd'hui : hôte et numéros du lien du front), qui charge toujours ; à défaut de chemin mémorisé, l'accueil du drive (`NavigationPolicy.START_URL`, comportement actuel).
2. Bandeau court à l'arrivée sur cette page : « Ton panier est dans l'icône en haut à droite. » (tutoiement, règle 10, sans bouton, disparaît à la première navigation).
3. Une page « introuvable » de Leclerc (titre ou statut) n'est **jamais** affichée à l'utilisateur après « Finaliser » : si, malgré tout, la page chargée est un 404 déguisé (statut 200 mais titre « introuvable »), repli sur l'accueil du drive. Si ce n'est pas détectable proprement, ne rien lire du contenu de la page.
4. `BASKET_PATH` du front vaut `''` à nouveau côté lien envoyé à l'application (l'adresse du panier n'est plus utilisée) ; le lien sert seulement à valider hôte et numéros. Aucun changement de protocole.
5. Garder la raison du repli pour le diagnostic (point 1 d'origine : `CHEMIN_INCONNU`, `ADRESSE_REFUSEE`, `ERREUR_HTTP_4xx`, `ERREUR_HTTP_5xx`, `OUVERTE`), formes seulement.

Critère d'acceptation : étant donné un chemin mémorisé, quand l'utilisateur touche « Finaliser », alors la page d'accueil du magasin s'ouvre avec le bandeau ; jamais une page « introuvable ». Tests Kotlin et faux site (accueil du magasin 200, `detail-panier.aspx` 404 déguisé en 200 : non demandé), `make android-e2e` (scénarios 15 à 17 adaptés).

## Réalisation (PR front #157, fusionnée le 2026-10-19)

- « Finaliser sur Leclerc » ouvre dans le drive de l'application l'accueil du magasin avec la ville (chemin mémorisé par `StorePathMemory`, validé comme avant), ou l'accueil du drive sans chemin mémorisé ; plus aucune navigation vers `detail-panier.aspx` (un test garde qu'aucun fichier Kotlin ne le contient). `BASKET_PATH` est supprimé côté front, `basketUrl` devient `finalizeLink`.
- Bandeau « Ton panier est dans l'icône en haut à droite. » à l'arrivée, qui disparaît à la première navigation.
- Page « introuvable » déguisée (statut 200) : repli sur l'accueil d'après le **titre** de la page seulement (`onReceivedTitle`) ; les erreurs 4xx/5xx (hors 403 et 429) se replient comme avant.
- Raison du repli gardée pour le diagnostic (`OUVERTE`, `CHEMIN_INCONNU`, `ADRESSE_REFUSEE`, `ERREUR_HTTP_4XX`, `ERREUR_HTTP_5XX`, `TITRE_INTROUVABLE`), un mot, jamais d'adresse ni de numéro ni de ville.
- Aucun changement de protocole ; les deux ordres de publication (site, application) sont sans dégât.
- Écart : le titre de la vraie page « introuvable » n'est pas mesuré ; le repli cherche « introuvable », « pas pu être trouvée », « non trouvée », « not found » ou « 404 », et ne se déclenche pas si le vrai titre est autre.
