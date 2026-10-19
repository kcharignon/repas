# AUTH-013 — Recette de la connexion avec Google : ordinateur, navigateur du téléphone, application

- **Dépôt** : aucun (étape humaine, guidée par l'assistant) ; l'assistant écrit `docs/auth/recette.md` au gabarit de `docs/drive/recette.md`
- **Jalon** : M4 Recette et mise en service
- **Taille** : M (une heure, avec un compte Google d'essai et un téléphone)
- **Dépend de** : AUTH-002, AUTH-008, AUTH-009, AUTH-011, AUTH-012 ; AUTH-010 si D4 = oui
- **Faisable sans téléphone** : **non** pour les parties C et D (feuille native, installation hors Google Play) ; la partie A se fait sur un ordinateur
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 4, 8, 10 (les inconnues à lever) ; `docs/drive/recette.md` (forme d'une recette signée) ; `docs/production.md` (ordre de mise en production) ; skill `repas-ops` ; `docs/drive/principes-produit.md` § 7 (essai d'un proche).

## Contexte

Tout le code est prouvé par des tests qui n'appellent jamais Google. Ce que Google fait réellement, ce que le téléphone affiche, et si l'APK signé hors Google Play est accepté, ne se voit que sur un vrai site et un vrai téléphone. Les comptes de la famille servent à la dernière étape seulement ; les essais qui créent ou suppriment des comptes se font avec un **compte Google d'essai** créé pour l'occasion.

## Portée

L'assistant rédige `docs/auth/recette.md` (une étape à la fois, attendu, case de résultat) et guide l'utilisateur :

**Partie 0 — mise en service coupée.** Back déployé avec `GOOGLE_CLIENT_ID` vide : le site et l'application se comportent comme avant (aucun bouton Google). Site (front) déployé, puis application (`android-vX.Y.Z`, publiée par l'humain) installée sur le téléphone.

**Partie A — ordinateur.** Allumer (script d'AUTH-008, ID client de la console). A1 le bouton apparaît au-dessus du formulaire ; A2 compte d'essai neuf : création, arrivée sur la liste ; A3 déconnexion puis reconnexion en 2 touches ; A4 fermeture du navigateur : reconnexion sans geste (cookie) ; A5 compte Repas existant à l'adresse Gmail du compte d'essai (mot de passe) : lié, les deux connexions marchent ; A6 adresse non Gmail déjà inscrite (second compte d'essai) : message « Cette adresse a déjà un compte Repas… » ; A7 compte désactivé par l'administrateur : refus, même message qu'un échec ordinaire ; A8 fenêtre Google fermée : aucun message ; A9 écran laissé ouvert plus de six minutes puis bouton : réussit ; A10 console du navigateur : aucune violation de CSP ; A11 suppression du compte par l'administrateur puis nouvelle connexion : compte neuf.

**Partie B — navigateur du téléphone (Chrome).** B1 le bouton s'affiche, la fenêtre de Google s'ouvre, la connexion réussit ; B2 essai avec et sans protection contre le suivi du navigateur.

**Partie C — application.** C1 le bouton natif apparaît (au-dessus du formulaire, sans défiler) ; C2 toucher : la **feuille native** propose les comptes du téléphone ; C3 choisir le compte : la liste s'affiche, **2 touches** ; C4 annuler la feuille : aucun message ; C5 mode avion : message d'erreur en trois phrases, formulaire utilisable ; C6 fermer et rouvrir l'application : session conservée sans geste ; C7 `app.info` : l'application annonce `google`.

**Partie D — inconnues à lever** (à consigner dans `connexion-google.md` § 10 et l'ADR) : I1 l'APK signé hors Google Play est-il accepté (sinon : erreur de configuration de la console, vérifier paquet et SHA-1) ; I2 valeur de `aud` et présence de `azp` et `nbf` dans le jeton de la feuille native (noms des revendications journalisés, pas leurs valeurs) ; I3 ce que Google affiche à un utilisateur pour l'application non vérifiée (nom ? site ?) ; I4 poids de l'APK ; I5 un téléphone sans services Google Play (s'il en existe un dans la famille) ; I6 Android 14 et plus, et avant.

**Partie E — un proche, seul.** Un proche installe l'application et se connecte sans aide (règle du produit : « un proche réussit sans aide ») ; consigner où il hésite : chaque hésitation est un ticket.

**Partie F — retour arrière.** Couper (`couper`) : le bouton disparaît au rechargement (web) et au prochain `app.info` (application) ; les comptes avec mot de passe fonctionnent ; les comptes créés par Google n'ont plus d'accès (attendu, voir réversibilité) ; rallumer.

**Partie G — signature.** Tableau de signature de l'utilisateur ; `DRIVE_ENABLED` n'est pas touché.

## Hors portée

iPhone/Safari (D5) ; toute modification de code (un défaut = un nouveau ticket) ; la vérification de marque.

## Critères d'acceptation

- Étant donné la recette signée, alors les parties A à C sont réussies, les inconnues I1 à I4 sont documentées, le proche de la partie E s'est connecté seul, la fonction reste allumée (ou l'utilisateur la coupe).
- Étant donné un échec, alors la fonction est coupée par le script, un ticket est ouvert avec la capture et la cause probable, et rien n'est « contourné ».

## Tests exigés

Aucun automatique : la recette **est** le test. Journal du back lu après chaque partie : aucune rafale de `401`, aucune erreur `5xx`.

## Definition of Done

Recette signée et archivée dans `docs/auth/recette.md` ; `connexion-google.md` § 10 : les « supposé » levés passent en « vérifié (recette du …) » ; ADR 0024 mis à jour si une décision change ; roadmap cochée. L'assistant n'allume ni ne déploie rien : l'utilisateur lance les scripts et approuve les déploiements.

## Pièges connus

- **Jamais les comptes de la famille pour les essais destructifs** (création, suppression, désactivation) : un compte d'essai.
- Une erreur « Developer console is not set up correctly » ou un refus de la feuille native : vérifier d'abord paquet, SHA-1 et que le client Android est **dans le même projet** que le client Web.
- Une erreur `origin_mismatch` sur le web : l'origine `https://kantin.eu` exacte dans le client Web ; les changements mettent de 5 minutes à quelques heures.
- Le cache du serveur est vidé à chaque déploiement : un nonce émis avant ne vaut plus ; recommencer.
- Ne pas confondre le bouton de Google (web) et le bouton dessiné (application) pour juger la charte.
- Les captures de l'écran de Google peuvent contenir l'adresse du compte d'essai : ne pas les committer.
- Le compte protégé (`User::PROTECTED_EMAIL`) est celui du propriétaire : s'il se lie à Google, valider d'abord que la validation en deux étapes est active sur ce compte Google.
