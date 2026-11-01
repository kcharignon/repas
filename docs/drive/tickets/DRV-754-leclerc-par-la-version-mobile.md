# DRV-754 — Leclerc : passer par la version mobile du site

- **Dépôt** : front (`drive-agent/`, `android/`) ; la doc de l'agent est dans le back (`docs/drive/architecture.md`, `docs/drive/hypotheses-site-reel.md`)
- **Jalon** : M7 Plusieurs enseignes (décision du propriétaire du 2026-11-01, hors ordre)
- **Taille** : L
- **Dépend de** : DRV-751 et DRV-753 (parcours guidé), DRV-741 (adresse du site mobile), `docs/drive/spikes/DRV-754-rapport.md` (**toutes les mesures du vrai site mobile**)
- **Statut** : à faire (agent lancé le 2026-11-01)
- **Lire d'abord** : le rapport `spikes/DRV-754-rapport.md` en entier, `drive-agent/src/leclerc/` (`adapter.ts`, `search-request.ts`, `cart.ts`, `store.ts`, `entry.ts`), `android/…/NavigationPolicy.kt`, `OpeningWatch.kt`, `DrivePass.kt`, `StoreBanner`, la WebView de `DriveActivity` (mode ordinateur)

## Décision du propriétaire (impérative, pas un choix)

Le propriétaire impose que Leclerc passe **par sa version mobile**, comme Auchan : « le drive ne fonctionne que sur mobile ; si je n'arrive pas à cliquer sur certaines choses, l'utilisateur non plus, car l'interface de la version ordinateur n'est pas adaptée à un téléphone ». **Peu importe les résultats d'essais précédents** (H11, DRV-741, ticket J : « l'agent refuse le site mobile ») : on les refait pour la version mobile, et tout le parcours Leclerc se fait par elle. Plus de mode ordinateur pour Leclerc.

## Constat

Aujourd'hui l'application force le mode ordinateur de la WebView pour que Leclerc serve `fd<N>-courses` ; l'agent et `NavigationPolicy` refusent `m-courses` (`PAGE_MOBILE`, bandeau « Leclerc affiche son site mobile »). Sur la version mobile, mesurée le 2026-11-01 : les pages sont servies par `www.leclercdrive.fr` (accueil et choix du magasin) puis `m-courses.leclercdrive.fr/magasin-N-N-ville` (application Angular), la connexion par `fd<N>-espace-client.leclercdrive.fr/drive/magasin-N-N-Ville/authentification.aspx` (avec un hCaptcha pour la personne), et **les appels AJAX vont à `fd<N>-courses`** (`recherche-ajax.aspx` en JSON, `panier.aspx?op=1&ng-ajax=1`). La requête de recherche actuelle de l'agent (`GET recherche.aspx`) est refusée entre hôtes depuis `m-courses`.

## À faire

1. **Android** : la WebView de Leclerc ne force plus le mode ordinateur (Auchan inchangé). `NavigationPolicy`, `OpeningWatch` et `DrivePlan` acceptent les hôtes de la version mobile : `www` (accueil et choix du magasin, fragment `#_divMoc…` compris), `m-courses` (pages du magasin, `/magasin-N-N[-ville]` sans `.aspx`), `fd<N>-espace-client` (connexion et compte), `fd<N>-courses` (AJAX), toujours en `*.leclercdrive.fr` en minuscules, jamais un autre domaine. Plus de refus `PAGE_MOBILE` ni de bandeau « site mobile » pour Leclerc ; la ligne « mode ordinateur appliqué » de « Version et mode d'emploi » disparaît pour Leclerc. Le plan part vers l'agent sur l'accueil, sur la page du magasin et sur la page de connexion, comme DRV-753.
2. **Agent** (adaptateur Leclerc, rien hors du registre) : lire le magasin dans l'adresse `m-courses` ; trouver l'hôte `fd<N>-courses` (liens du pied de page, jamais deviné) ; **chercher par `POST recherche-ajax.aspx?TexteRecherche=…&idsupport=10&ng-ajax=1`** (JSON, entités HTML décodées) et en tirer les mêmes champs de produit qu'aujourd'hui (nom, contenance, prix, prix au kilo, disponibilité, quantité au panier) ; **écrire le panier par `POST panier.aspx?op=1&ng-ajax=1`** (même champ `d` que `cart.ts`) ; lire l'état connecté de l'en-tête `m-courses` et du compte ; entrer par « Commencer mes courses » (présentation du magasin sur `www`) ; guider le choix du magasin avec les mots de la version mobile (champ « Où souhaitez-vous récupérer vos courses ? », suggestion à toucher). Les sondes de forme du diagnostic (DRV-752) couvrent la version mobile : adresse du site, appel de recherche, lecture du panier, sans valeur personnelle.
3. **Faux site Leclerc** (`drive-agent/fake-leclerc/`) : une variante mobile qui reproduit les faits du rapport (accueil et choix du magasin, présentation, `m-courses` qui parle à un hôte `fd<N>` voisin en AJAX entre hôtes, connexion en deux temps sur `fd<N>-espace-client` avec une case de vérification que l'agent ne touche jamais, mur de cookies modal).
4. **Ce qui n'est pas mesuré** (lecture du panier, état connecté, adresse de retour après connexion, quantité absolue d'un ajout, produit refusé) : l'agent l'écrit comme **hypothèses numérotées** dans le diagnostic et la PR ; aucune devinette cachée, et un défaut de forme se dit (`CONTRACT_CHANGED` avec la forme), jamais « Leclerc a changé » sans mesure.
5. Textes : voix de `repas-po` ; aucun mot « site mobile » ni « version ordinateur » à l'écran. Docs et tests dans le même PR ; `AGENT_VERSION` suivante, `agent.js.sha384` régénérée.

## Ordre de livraison

L'agent et l'application changent : une nouvelle version de l'**application Android** (`android-v1.5.0`), approuvée par le propriétaire, puis `ecrire-app-version.sh latest 1.5.0` par lui. Ni le back ni le site ne changent. **Recette** : l'assistant la rejoue sur le téléphone de test (compte de démo, identifiants de test) jusqu'au hCaptcha de la connexion, que le propriétaire passe ; le reste (choix du magasin, entrée, recherche, ajout au panier, coches) se rejoue sans lui.

## Hors périmètre

Contourner ou résoudre le hCaptcha ; saisir les identifiants à la place de la personne dans le code de l'agent ; durcir Leclerc au-delà de ce besoin ; toucher à Auchan.
