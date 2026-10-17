# Analyse produit : envoyer la liste de courses au drive

Analyse faite le **2026-10-17** (grille Product Owner appliquée à la main : aucun skill dédié n'est installé). Faits : [`recherche.md`](recherche.md). Solution technique : [`architecture.md`](architecture.md). Tickets : [`roadmap.md`](roadmap.md).

## 1. Besoin

**Quand** ma liste de la semaine est prête, **je veux** que mon panier drive se remplisse avec mes produits habituels, **pour** ne pas ressaisir trente lignes. Sur Android, avec un geste de plus au maximum.

Flux voulu par l'utilisateur : bouton sur la liste → éventuelle connexion à Leclerc par lui-même (nous ne stockons rien) → pour un nouvel ingrédient, la page Leclerc ouverte sur son nom et il choisit → le choix est mémorisé (`Produit`) → pour un produit connu, mise au panier avec la bonne quantité → Repas affiche le résultat et l'utilisateur finalise sa commande sur le site. Plus il a de produits liés, plus c'est rapide. Une liste envoyée n'est plus modifiable.

**Indicateurs** : lignes acceptées sans changement ≥ 70 % à la première liste et ≥ 90 % à la troisième ; de « liste prête » à « panier rempli » < 3 min une fois les produits mémorisés ; échecs de remplissage < 5 % ; listes envoyées par semaine.

## 2. Décisions de l'utilisateur (2026-10-17)

1. Aucun identifiant, cookie ni jeton Leclerc ne passe par notre serveur ; un script agit dans la session de l'utilisateur, qui se connecte lui-même.
2. Le téléphone est indispensable dès le départ : Android, commande par le site leclercdrive.fr dans le navigateur (pas l'appli). Un geste de plus (toucher un favori) est accepté.
3. Public : lui et ses proches.
4. Une liste envoyée au drive n'est plus modifiable.
5. Tout est documenté dans le dépôt ; d'autres agents implémentent, l'assistant ne code pas.

## 3. Réponses aux questions de départ

| Question | Réponse |
|---|---|
| API publique ? | Non. |
| Appeler les routes internes depuis notre serveur ? | Écarté : DataDome bloque un serveur, il faudrait les identifiants, et le back n'a ni worker ni tâche planifiée. |
| L'utilisateur se connecte via notre site ? | Non. Il se connecte sur leclercdrive.fr ; le script travaille dans cette session. |
| On sauvegarde ses identifiants ? | Non, par conception (voir § 6). |

## 4. Options étudiées

**Retenue : un « agent de page »**, favori JavaScript autonome lancé sur leclercdrive.fr dans Chrome (Firefox en repli), qui reçoit un plan de Repas par le fragment d'URL et renvoie un résultat de la même façon. Pourquoi : seul un vrai navigateur dans la session de l'utilisateur passe DataDome ; sur Android, le favori est la seule voie sans installer autre chose que ce qu'ils ont déjà ; aucun identifiant n'est stocké.

**Écartées, avec la raison**
- *Serveur + identifiants stockés (navigateur sans tête côté serveur)* : DataDome bloque un serveur, 2FA et captcha ne s'automatisent pas, une fuite engage notre responsabilité (RGPD, CNIL sous 72 h), mots de passe réutilisés, un seul serveur sans chiffrement au repos, partage d'identifiants probablement contraire aux CGU (à vérifier).
- *Cookie de session envoyé au serveur* : même blocage DataDome, et un cookie de session vaut un identifiant.
- *Application Android dédiée (WebView)* : second projet à construire, signer et distribuer ; l'utilisateur taperait ses identifiants dans notre appli.
- *Navigateur distant diffusé* : nous verrions la session et les saisies, sur un serveur de 1 Go.
- *Leclerc affiché dans Repas (cadre intégré)* : refus possible de Leclerc, CSP, cookies de connexion. On quitte Repas pour Leclerc, puis on revient.
- *Sélecteur de produit dans Repas* : un aller-retour par recherche, images bloquées par notre CSP, interface Leclerc dupliquée.
- *Extension Chrome* : n'existe pas sur Chrome Android. *Extension ou gestionnaire de scripts Firefox, extension Safari* : repoussés (autre navigateur, publication publique ou compte Apple payant), gardés pour le backlog.
- *Code distant chargé par le favori* : notre serveur deviendrait une porte vers les comptes Leclerc de tous les utilisateurs.
- *Synchronisation complète du catalogue* : extraction massive, risque CGU, droit des bases de données et DataDome.
- *Scrapers payants (Apify, Piloterr)* : lisent le catalogue mais ne remplissent pas le panier de l'utilisateur ; coût récurrent ; même risque CGU.
- *Open Food Facts / Open Prices* : pas d'identifiants Leclerc ni de panier ; utile plus tard pour l'EAN.
- *Cocher les lignes après envoi* : « coché » = « dans le caddie », la dernière coche termine la liste.
- *Partenariat façon Jow* : accord commercial, hors de portée en usage familial.
- *Verrou à la fin du passage plutôt qu'au toucher du bouton* : ouvre une fenêtre où la liste change pendant l'envoi ; le verrou au toucher rend le plan stable, et la réouverture explicite couvre l'erreur.

**Gardé en repli permanent : le mode assisté** (un lien de recherche Leclerc par ligne, sans automatisation) quand le favori est bloqué ou cassé. Seul, il vaut peu : l'utilisateur ajoute tout à la main et rien n'est mémorisé.

## 5. Le vrai sujet : ingrédient → produit

Ce n'est pas un problème d'API mais de données.
- **Un choix par (utilisateur, enseigne, magasin, ingrédient)** : catalogues et identifiants sont par magasin ; sans EAN, la clé reste le magasin. On garde libellé, contenu et date de vue.
- **Choisir sur la page Leclerc native** (proposition de l'utilisateur) : photos, prix, promotions, filtres gratuits, aucun sélecteur à écrire, très peu de requêtes automatiques (donc moins de risque DataDome), aucune image à autoriser dans notre CSP. Problème : un favori ne vit que le temps d'une page, Leclerc recharge la page à chaque recherche. Parade à tester : un `iframe` de même origine dans une fenêtre de notre script (P1). Repli : notre script affiche lui-même les résultats (P2).
- **Quantités** : la liste dit « 300 g de farine » ou « 2 paquets », le drive vend « farine 1 kg ». Le back calcule les conditionnements (`PackCalculator`, règles dans `architecture.md` § 6) avec une `dimension` ajoutée aux unités. Les conversions entre types (3 oignons → un filet de 1 kg) viennent plus tard, par ingrédient.
- **Garde-manger** : « Passer cette fois » et « J'en ai toujours » (sel, huile, épices ne sont plus proposés), dès la première version, sinon la phase guidée les redemande chaque semaine.
- **Suggestion automatique** (backlog) : choix précédent d'abord, sinon règles déterministes ; la recherche Jaro-Winkler de l'ADR 0019 est réutilisable (seuil 0,85).
- **Pas de moissonnage du catalogue** : recherches à la demande, par utilisateur, sur les seuls ingrédients sans choix.

## 6. Sécurité : identifiants

Décidé : on n'en stocke aucun. Si l'on devait (non recommandé, aucun plan) : chiffrement par enveloppe (libsodium, `ext-sodium` est présent) avec clé hors base ET hors sauvegardes, donc un coffre externe (la machine unique actuelle livre clé et données si elle est compromise ; aujourd'hui aucun chiffrement au repos, secrets en variables d'environnement, `pg_dump` complets sur l'hôte) ; écriture seule, jamais renvoyé au front ni journalisé ; rotation ; audit ; limitation de débit ; suppression à la demande ; notification CNIL sous 72 h (RGPD art. 33-34) ; test d'intrusion. Et cela ne règle ni DataDome, ni la 2FA, ni les CGU. Contrôles retenus à la place : `architecture.md` § 10.

## 7. Décisions par défaut (modifiables avant l'implémentation)

- D1 Le verrou se pose au toucher d'« Envoyer au drive » ; la réouverture est explicite.
- D2 Sur `SENT` : lire, terminer, rouvrir, supprimer ; tout le reste `409`.
- D3 Les listes `SENT` restent jusqu'à `complete` ou suppression.
- D4 L'envoi n'est possible que depuis une liste `ACTIVE` non vide.
- D5 Réessayer un envoi se fait sur la liste `SENT` (quantités absolues, rejouable).
- D6 Repas ne vide ni ne réduit le panier Leclerc.
- D7 Un choix est propre à (utilisateur, enseigne, magasin, ingrédient).
- D8 Les conditionnements se calculent dans le back (contexte `Drive`).
- D9 L'agent vit dans le dépôt front ; le protocole est partagé.
- D10 `DRIVE_ENABLED` est coupé par défaut.
- D11 Pas de vignettes dans Repas en v1.
- D12 `sentAt` est stocké.
- D13 Un produit connu devenu indisponible repasse en phase B.
- D14 « J'en ai toujours » = un `Choice` de type `HAVE_AT_HOME`.
- D15 Libellés : statut API `SENT`, texte « Envoyée au drive ».
- D16 Un ingrédient peut avoir jusqu'à 5 produits acceptés classés par priorité (le premier est préféré, les suivants sont des produits de secours) et jusqu'à 20 produits refusés (jamais proposés ni mis au panier). Demande de l'utilisateur du 2026-10-17 : « je prends la farine Marque Repère si dispo, sinon une autre marque équivalente me va, mais pas cette farine-là ».
- D17 Si le produit préféré est indisponible, l'agent prend le suivant de la liste sans rien demander ; si aucun n'est disponible, il demande à l'utilisateur de choisir un autre produit sur Leclerc (phase B) et lui demande son rôle : nouveau préféré, produit de secours, ou seulement cette fois.
- D18 L'agent ne choisit jamais seul un produit « équivalent » absent de la liste ; la suggestion automatique (backlog DRV-601) devra toujours exclure les produits refusés.
- D19 Un produit refusé se désigne depuis la phase B (case « Ne plus jamais prendre » pour un produit indisponible) ou depuis l'écran « Mes produits » (DRV-410) ; il se ré-accepte depuis ce même écran.
- D20 (**ouverte**, constat du 2026-10-17) Le favori du banc d'essai (49,6 Ko) ne s'installe pas sur le téléphone de l'utilisateur : créé sur l'ordinateur il ne se synchronise pas, créé sur le téléphone la chaîne est coupée au collage. L'agent final, plus gros, ne peut donc pas être un favori « complet ». Pistes, à trancher à G1 après DRV-004 (CSP de Leclerc) et DRV-006 (limite de taille réelle) : (a) **favori chargeur** de moins de 1 Ko qui charge le code de l'agent depuis l'origine de Repas, avec une empreinte (SRI) écrite dans le favori, ce qui garde « pas de code modifiable à distance » mais impose de réinstaller le favori à chaque version ; (b) même chargeur mais code **signé** (clé publique dans le favori, vérification par WebCrypto) : mises à jour sans réinstaller, sans faire confiance au serveur ; (c) Firefox Android + gestionnaire de scripts (aucune limite de taille, mise à jour par l'extension), mais l'utilisateur quitte Chrome ; (d) mode assisté seul. Les pistes (a) et (b) supposent que la CSP de Leclerc laisse charger un script de notre origine : **oui sur la page d'accueil** (aucune politique de sécurité, DRV-004 première passe), à confirmer sur la page de recherche. **Taille de favori installable (DRV-006) : au moins 8 Ko, moins de 16 Ko** : le favori du banc (49,6 Ko) et l'agent final ne peuvent pas être des favoris complets, un chargeur court est obligatoire (le plan doit donc choisir entre (a) et (b), ou (c) et (d)). Le chargeur sera d'abord éprouvé sur le banc d'essai (DRV-009). L'invariant « pas de code distant » de l'ADR 0022 est à reformuler en « pas de code distant non vérifié » si (a) ou (b) est retenue.
- D21 (**ouverte**, constat du 2026-10-17) Le plan ne peut peut-être pas voyager dans le fragment de l'adresse vers Leclerc : une navigation vers la page de recherche du magasin avec un fragment de 6 Ko aboutit à la page d'erreur de Leclerc (`PageDir/pgeWCSD002_Erreur.aspx?…&fromJs…=1`, ajoutée par le JavaScript de la page, qui efface le chemin du magasin et le fragment). On ne sait pas si c'est la taille ou tout fragment (test A2 : `#repas=essai`, 1 000 et 3 000 caractères). Si les fragments courts échouent aussi : (a) **message entre onglets** : Repas ouvre Leclerc avec `window.open` (sans `noopener`), l'agent écrit à `window.opener` avec `postMessage` vers l'origine de Repas, et Repas répond avec le plan ; sans COOP sur Leclerc (DRV-004) le lien entre onglets subsiste, à valider sur téléphone ; le retour utiliserait le même canal en plus du fragment vers Repas (qui passe jusqu'à 64 Ko) ; (b) **presse-papiers** : Repas copie le plan, l'agent le lit au toucher du favori (permission de lecture à vérifier). Le retour vers Repas par fragment fonctionne (DRV-002, lignes 9 et 10, côté Repas).

## 8. Lacunes comblées

Règle de verrou (statuts, opérations, codes, versions, déploiement) · contexte `Drive` (modèle, tables, ports, API, droits) · protocole de l'agent (messages, validation, erreurs, cadence) · calcul des quantités et `dimension` des unités · lecture de la liste par `Drive` sans importer `Repas` (port `Shared`) · brouillon de passage face au rechargement de la PWA · ouverture dans Chrome et non dans l'onglet intégré · réglage du magasin (lu par l'agent, pas saisi) · comportement après un envoi (place de liste active libérée, `/liste`) · indisponibilité d'un produit connu · envoi partiel rejouable, panier jamais vidé · surcharges par ligne avant l'envoi · compatibilité de déploiement et interrupteur · purge et vie privée · tests sans accès à Leclerc (faux site) et recette réelle · maintenance quand Leclerc change · tickets autonomes et suivi.

## 9. Risques

1. DataDome bloque le favori sur téléphone (fort, inconnu) → DRV-002.
2. Les CGU interdisent (fort, inconnu) → DRV-003.
3. CSP ou refus de cadre de Leclerc (moyen) → DRV-004 et DRV-002 ; repli P2, sinon Firefox, sinon mode assisté.
4. Le lancement du favori dans Chrome Android est pénible ou cassé par une mise à jour de Chrome (moyen) → DRV-002.
5. Le site change et casse l'agent (moyen, certain à terme) → interrupteur, runbook, détection `CONTRACT_CHANGED`.
6. Mauvaise qualité de correspondance (fort sur la valeur) → DRV-002, choix mémorisés, indice de recherche.
7. Onglet suspendu pendant un passage (moyen) → Wake Lock, reprise.
8. Support des proches (installation du favori) (moyen) → page d'aide, installation faite une fois pour eux.
9. Maintenance pour une personne (fort) → rester mince, contexte supprimable d'un bloc.
10. Dérive vers plusieurs enseignes trop tôt (fort) → un port, un adaptateur.
11. Le favori est trop gros pour être installé sur le téléphone (fort, **constaté** le 2026-10-17 avec 49,6 Ko) → DRV-006 (limite réelle), DRV-004 (CSP), décision D20.

## 10. Découpage

Voir [`roadmap.md`](roadmap.md) : 43 tickets en 6 jalons (M0 découverte et décision G1, M1 back `Repas`, M2 back `Drive`, M3 agent, M4 front, M5 intégration et livraison) plus un backlog. Principe : rien de produit n'est codé avant la décision G1, qui dépend des spikes sur le téléphone, des CGU et des en-têtes de sécurité de Leclerc.
