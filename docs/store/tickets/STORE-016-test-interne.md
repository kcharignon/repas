# STORE-016 — Test interne : premier bundle et installation par-dessus l'APK installé à la main

- **Dépôt** : aucun (étape humaine, guidée par l'assistant) ; l'assistant écrit `docs/store/recette.md` au gabarit de `docs/drive/recette.md`
- **Jalon** : M4 Essais et publication
- **Taille** : M (deux heures avec un téléphone Android et un compte de test)
- **Dépend de** : STORE-005 (clé choisie), STORE-006 (AAB dans le workflow) ; STORE-007 si l'empreinte diffère
- **Faisable sans téléphone ni compte Play** : **non** (téléphone Android réel et compte Play)
- **Bloquant pour AUTH-002 / la connexion Google** : **c'est ici que la partie C d'AUTH-013 se rejoue** sur l'application installée depuis Google Play ; les inconnues I1 (APK hors Play accepté), I4 (poids) et I6 (versions d'Android) y sont levées pour le canal principal
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 9 phase D, § 4.2 et § 8 ; [AUTH-013](../../auth/tickets/AUTH-013-recette-web-et-telephone.md) parties C et D ; `android/PUBLICATION.md` étapes 5 à 8 ; skill `repas-ops`.

## Contexte

Le test interne est le palier qui sert la **famille tout de suite** : jusqu'à 100 testeurs, mise en ligne « en quelques minutes », sans la durée de 14 jours. C'est aussi la preuve des trois inconnues du § 4.2 : la console accepte-t-elle l'AAB signé de la clé d'envoi ; l'application Play s'installe-t-elle **par-dessus** l'APK installé à la main ; les liens `https://kantin.eu/…` s'ouvrent-ils dans l'application avec la clé de Google.

## Portée

Guider, une action par message, selon D.1 à D.9 de l’analyse (§ 9, phase D) :

1. **Étiquette** : l'humain pousse une étiquette `android-vX.Y.Z` plus grande que `android-v0.1.8` (par exemple `android-v0.2.0`), après le **site** si le protocole a changé (`PUBLICATION.md` étape 5) ; vérifier la release comme d'habitude (étape 6).
2. Télécharger l'AAB (`gh run download <run> -n android-bundle`), vérifier sa somme `sha256sum -c`.
3. Console : *Tests > Test interne > Créer une version*, téléverser, publier ; lister d'abord **le téléphone du propriétaire** (compte de test), puis 2 proches ; vérifier que la console affiche le **certificat de la clé d'envoi** (empreinte = `android/upload-cert.sha256`) et noter le **nombre d'empreintes** de la signature d'application.
4. Sur le téléphone du propriétaire **avec l'APK installé à la main** : ouvrir le lien d'inscription, « Devenir testeur », « Télécharger sur Google Play » : l'application doit se **mettre à jour** (« Mettre à jour », pas « Installer »), sans désinstaller, et la connexion à Repas comme au drive doit rester. Noter l'écran vu et la version Android du téléphone.
5. Contrôles : version affichée (« Menu », « Version et mode d'emploi ») = l'étiquette ; **App Links** (`adb shell pm get-app-links eu.kantin.repas.drive` : `kantin.eu: verified`, ou ouvrir un lien reçu par e-mail) ; si la connexion Google est livrée, partie C d'AUTH-013 ; envoi au drive (essai à vide) ; aucun avertissement Play Protect.
6. Sur un téléphone **sans** APK installé : installer depuis le lien, se connecter, parcours court (liste → recette).
7. Si l'étape 4 échoue (l'application Play refuse de s'installer par-dessus, ou Play propose « Installer » sans mise à jour) : noter le message exact, appliquer le repli (désinstaller, installer depuis Play, refaire la connexion au drive) et **ouvrir un ticket** ; si la signature est hybride et le téléphone sous Android 17, noter aussi.
8. Écrire `docs/store/recette.md` (étapes, attendu, case de résultat, signature) et lever les « supposé » 1 à 3 de l'analyse.

## Hors portée

Le test fermé (STORE-018) ; la fiche complète (STORE-017) ; toute modification de code (un défaut = un ticket) ; le retrait de l'APK GitHub (STORE-020).

## Critères d'acceptation

- Étant donné le test interne publié, quand un proche ouvre le lien et touche « Devenir testeur » puis « Télécharger sur Google Play », alors l'application s'installe et s'ouvre sur Repas : **trois touches** après le lien.
- Étant donné un téléphone avec l'APK installé à la main, alors la mise à jour par Google Play réussit sans désinstaller (ou le repli est consigné).
- Étant donné les App Links, alors un lien `https://kantin.eu/…` s'ouvre dans l'application.
- Étant donné la console, alors le certificat d'envoi et le nombre d'empreintes de signature sont notés.

## Tests exigés

Aucun automatique : la recette **est** le test. Journal du back lu après la recette : aucune rafale de `401`, aucune erreur `5xx`.

## Definition of Done

Recette signée dans `docs/store/recette.md` ; `publication-play-store.md` § 14 : les « supposé » 1 à 3 passent en « vérifié (recette du … ) » ; AUTH-013 partie C cochée si la connexion Google est livrée ; roadmap cochée.

## Pièges connus

- **Jamais les comptes de la famille** pour les essais destructifs : un compte d'essai.
- Les testeurs doivent ouvrir le lien avec **le compte Google du téléphone** ; sinon la page dit que l'application n'est pas disponible.
- À la première mise en ligne, le nom provisoire et la fiche peuvent s'afficher jusqu'à 48 h : ne pas s'en alarmer.
- Une étiquette ne se réutilise jamais : si l'envoi échoue, une étiquette plus grande.
- Ne pas publier en test fermé ni ouvert avant d'avoir noté le nombre d'empreintes (la clé est figée après une publication ouverte).
- Une capture de la console peut contenir des adresses de testeurs : ne pas la committer.
