# DRV-757 — La carte du guide : plus légère, plus juste, jamais devant ce qu'on doit toucher

- **Dépôt** : front (`drive-agent/`, `android/`)
- **Jalon** : M7 Plusieurs enseignes (constat de la recette réelle du 2026-11-01, regard d'une personne qui ne connaît rien)
- **Taille** : M
- **Dépend de** : DRV-751, DRV-753, DRV-754, DRV-755
- **Statut** : à faire (agent lancé le 2026-11-01)
- **Lire d'abord** : `drive-agent/src/ui/` (`screens.ts`, `styles.ts`, `pointer.ts`, `keep-clear.ts`), `drive-agent/src/flow/guide.ts` et `orchestrator.ts`, `drive-agent/src/leclerc/` (`adapter.ts`, `guide` de l'étape `store` et `login`), `docs/drive/principes-produit.md`, skill `repas-po`

## Contexte

Recette réelle du 2026-11-01 (application 1.5.0, téléphone de test, compte de démo, Leclerc par sa version mobile). Le propriétaire a demandé de lire tout le parcours comme **une personne qui ne connaît rien** : est-elle bien guidée, voit-elle tout ce qu'elle doit voir, lui cache-t-on quelque chose qui complique le parcours ? Il a aussi dit que la carte « Étape 1 sur 2 » **prend beaucoup de place** et qu'il faudrait pouvoir **la masquer un peu, temporairement**.

Constats mesurés (positions lues dans l'application, écran de 2400 pixels de haut) :

1. **La carte cache les résultats.** Après le choix de la ville, la carte monte au milieu de l'écran (de 566 à 1136) et couvre le haut de la liste des magasins (qui commence vers 973) : la première carte de magasin est à moitié cachée, l'assistant a dû toucher sa partie basse encore visible. Avec le clavier ouvert, la carte monte aussi au milieu de l'écran.
2. **Le repère « Touche ici » reste collé sur le champ de recherche** et recouvre la suggestion de ville qui s'affiche juste dessous (le repère à 398–506, la suggestion à 382–490).
3. **Le texte de la carte ne suit pas la sous-étape** : « Écris ton code postal, touche ta ville, puis choisis ton magasin. » reste le même avant et après la ville choisie ; personne n'est guidé pas à pas pour « touche un magasin de la liste ».
4. **Sur la page de connexion** (`fd<N>-espace-client`, autre origine, la note de session de l'agent ne suit pas), la carte redit **« Étape 1 sur 2 · Choisis ton magasin »** alors que le magasin est déjà choisi : faux et déroutant.
5. Le menu « Connexion / Inscription » de la version mobile a **deux zones à toucher** (« Connexion » mène à la connexion, « / Inscription » à la création de compte) ; la personne peut se tromper de côté.
6. Le mur de cookies de Leclerc réapparaît à chaque nouveau départ (sans connexion ni données gardées) avant même la carte.

## À faire

1. **Carte repliable** : un contrôle clair (pas « Fermer » ; un bouton = une conséquence claire, qui rassure) réduit la carte à une **barre fine** (« Étape 1 sur 2 · Choisis ton magasin · Afficher ») et la rouvre d'un toucher ; « Arrêter pour l'instant — Ta liste est gardée » reste atteignable. La carte se **réduit toute seule** quand la page a besoin de la place (clavier ouvert, suggestions ou résultats affichés) et revient quand c'est fini ; la personne peut la rouvrir à tout moment.
2. **Le repère** ne couvre jamais ce qu'il a fait apparaître : il se retire ou se déplace dès que la cible est touchée, ou dès qu'une liste s'ouvre sous elle.
3. **Le texte suit la sous-étape réelle** de l'enseigne (lu dans la page : champ vide, ville à toucher, magasin à toucher, magasin ouvert, connexion, code reçu par e-mail, question de l'ancien panier) avec des mots à chaque fois différents et courts (`repas-po`).
4. **Sur la page de connexion**, la carte dit l'étape de connexion, jamais l'étape du magasin : l'état du parcours doit suivre d'une origine à l'autre (par l'application, qui sait où on est, ou par l'adresse de la page : la connexion se reconnaît à `authentification.aspx`).
5. **Le menu « Connexion / Inscription »** : le repère désigne le mot « Connexion » (pas toute la ligne) et la carte le dit.
6. **Preuve par la géométrie** : un test (faux site mobile, 411 × 761 pixels, puis la hauteur du téléphone de test) vérifie que la carte, réduite ou non, ne recouvre jamais le champ actif, la suggestion ni les trois premières cartes de magasin ; un rapport avec **captures de chaque étape** (magasin, ville, résultats, présentation, connexion, code, ancien panier, ajout) lues « en novice » est joint à la PR. Maquette Claude Design de la barre réduite et de la carte pleine (clair et sombre).
7. Mettre à jour la ligne périmée du `drive-agent/README.md` (« l'agent fait 260 Ko » : le bundle fait plus de 268 Ko, marge de 3,3 Ko sur 272 Ko : ce ticket doit économiser du budget ou le relever, et le dire).

## Ordre de livraison

L'agent et l'application changent : une nouvelle version de l'**application Android** (`android-v1.6.0`), après le déploiement du **site** de DRV-755 (le site accepte la clé `chosenStore` avant l'application). Le propriétaire approuve chaque déploiement, puis `ecrire-app-version.sh latest 1.6.0`.

## Hors périmètre

Cacher ou fermer le mur de cookies à la place de la personne ; changer le parcours de connexion de Leclerc (hCaptcha et code e-mail : gestes humains).
