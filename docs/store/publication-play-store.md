# Publication de l'application Android « Repas » sur Google Play : analyse de cadrage

Cadrage du 2026-10-19 (agent de cadrage : recherche, architecture, tickets ; **aucun code produit**). Décision d'architecture associée : [ADR 0025](../adr/0025-publication-sur-google-play.md) (`Proposed`). Tickets et ordre : [`roadmap.md`](roadmap.md), [`tickets/`](tickets/).

Toutes les sources ont été consultées le **2026-10-19**. Les pages d'aide de Play Console n'affichent **aucune date de mise à jour** (seulement « ©2026 Google ») : elles sont citées « consultée le 2026-10-19 » et comptées comme actuelles ; quand une page affiche une date (documentation Android, API), elle est donnée. Les citations de pages en anglais sont **traduites**. Ce qui est **vérifié** (page officielle lue, lecture du code) est séparé de ce qui est **supposé** (§ 14). Les outils de lecture résument les pages : les citations entre guillemets viennent de cette lecture et doivent être relues dans la console au moment de l'action.

## 0. En bref

**Objectif.** Les proches installent et mettent à jour « Repas » depuis Google Play, sans « sources inconnues », sans avertissement Play Protect et sans télécharger un `.apk` sur GitHub ; et, un jour, tout le monde peut l'installer.

**Recommandation en dix lignes.**

1. **Mode** : trois paliers. **Test interne** tout de suite pour la famille (100 testeurs au plus, aucune exigence de durée), **test fermé** (12 testeurs, 14 jours) pour ouvrir la voie de la production, **production en France** ensuite. L'application « privée » (Managed Google Play) est exclue : réservée aux organisations et jamais convertible en publique.
2. **Compte** : développeur **personnel**, 25 USD une fois, pièce d'identité, vérification d'un vrai téléphone Android ; adresse d'assistance **dédiée** (elle est publique) ; second propriétaire.
3. **Signature** : **Play App Signing en important la clé actuelle** (« fournir une copie de ma clé », outil PEPK), à faire **avant toute publication en test ouvert ou en production** : les APK déjà installés à la main restent compatibles (supposé, à prouver sur téléphone) et `assetlinks.json` comme l'empreinte du client OAuth Android ne changent pas.
4. **Clé d'envoi** nouvelle, distincte, pour la CI : si elle est perdue, Google la réinitialise ; la clé de signature n'est plus le seul secret qui compte.
5. **Build** : la même étiquette `android-vX.Y.Z` produit l'APK (inchangé) et un **AAB** signé de la clé d'envoi, contrôlé comme l'APK. Envoi à la main au début ; automatisation éventuelle limitée à la piste interne.
6. **Conformité, à faire avant le test fermé** (très probablement exigée dès cette piste : seule la piste interne est dispensée de la fiche « sécurité des données ») : suppression de son propre compte dans l'application **et** page web publique (aujourd'hui seul l'administrateur supprime), page publique de confidentialité, compte de relecture pour Google, formulaire « sécurité des données », public 18 ans et plus, aucune publicité.
7. **Drive** : décrit franchement dans la fiche, jamais caché ; aucune marque dans le titre, l'icône ni les captures. C'est la **principale inconnue de refus** (§ 6.b) ; l'interrupteur `DRIVE_ENABLED` existe, et une variante sans drive est un ticket conditionnel.
8. **Contenu des utilisateurs** : les recettes des autres sont visibles ; la règle de Google demande conditions acceptées et signalement. Un bouton « Signaler » est proposé (décision D5).
9. **Ordre avec la connexion Google** : les étapes 11 à 13 d'AUTH-002 (client Android) attendent STORE-005 ; tout le reste d'AUTH n'est pas bloqué.
10. **Délais incompressibles** : validation du compte (jours, non documentée), **14 jours** de test fermé, examen (jusqu'à 7 jours). Environ **5 à 6 semaines** jusqu'au public, **1 à 2 semaines** jusqu'à la famille sur Play. Coût : 25 USD.

**Décisions que le propriétaire doit prendre** (§ 12) : D1 les paliers ; D2 le compte et l'adresse d'assistance ; D3 la clé importée et le sort de l'APK GitHub ; D4 le nom de l'enseigne dans la fiche et le drive dans la version du store ; D5 le contenu des utilisateurs.

**Ce qui contredit ou nuance les hypothèses du cadrage** (tous vérifiés, § 14) :

- *« On peut importer la clé actuelle »* : oui, c'est documenté (PEPK), mais **le défaut d'une nouvelle application est une clé générée par Google en signature « hybride » à trois clés** (Android 17 et plus verront d'autres empreintes). On doit **changer ce défaut** avant tout test ouvert ou production. Ce que la console affiche pour une clé importée (une empreinte ou trois) n'est pas documenté : c'est la première inconnue à lever (STORE-005).
- *« Application privée : exige Workspace ? »* : la page ne parle pas de Workspace mais d'un **identifiant d'organisation** (console de gestion d'appareils) ; elle dit qu'une application privée ne devient jamais publique (« il faudra publier une nouvelle application avec un autre nom de paquet »). Exclue.
- *« Test interne : sans revue ? »* : Google écrit que les tests internes « peuvent ne pas être soumis aux revues standard de règles et de sécurité » : ce n'est pas une garantie.
- *« Vérification des développeurs en France en 2027 »* : **aucune page de Google ne cite la France** ; elles disent « quatre pays au 2026-09-30 (Brésil, Indonésie, Singapour, Thaïlande), puis dans le monde en 2027 », sans date. La note du dépôt (`pivot-application-android.md`, inconnue 6) s'appuyait sur de la presse. Et publier sur Play règle d'avance la question pour la voie hors Play (§ 3).
- *« Mise à jour par-dessus l'APK »* : Android compare les certificats (documenté), mais **que Google Play propose la mise à jour d'une application installée à la main n'est pas documenté** par les pages lues : à prouver sur téléphone (STORE-016).
- Non prévu au cadrage et **vérifié** : la règle sur les **contenus d'utilisateurs**, la règle « suppression de compte = pas seulement geler », l'obligation d'un **compte de relecture**, et la distinction entre **clé de signature et clé d’envoi** (Google recommande deux clés distinctes).
- `version.json` (publié à chaque release) **n'est lu par rien** dans l'application (le README du dépôt front le dit : « la vérification automatique n'existe pas encore »).

## 1. Ce qui existe aujourd'hui (lu dans le code, `origin/main` du 2026-10-19)

| Fait | Source (dépôt front `kcharignon/repas-front`) |
|---|---|
| Paquet `eu.kantin.repas.drive`, `minSdk 26`, `targetSdk 36`, `compileSdk 36`, AGP 8.13.2, Kotlin 2.2.21, JDK 17, Gradle 8.14.6 en image Docker | `android/app/build.gradle.kts`, `android/build.gradle.kts`, `android/Dockerfile` |
| Version : nom et code tirés de l'étiquette `android-vX.Y.Z` ; code = `X × 1 000 000 + Y × 1 000 + Z`, ne baisse jamais, plafond Android 2 100 000 000 ; dernière étiquette publiée : `android-v0.1.8` (code 1 008) | `buildSrc/.../AppVersion.kt`, workflow, `git tag` |
| Une seule permission (`INTERNET`), non débogable, `usesCleartextTraffic=false`, `allowBackup=false`, aucune bibliothèque native, aucun `addJavascriptInterface` (`addWebMessageListener` seulement) | `AndroidManifest.xml`, `NativeBridge.kt`, `AgentHost.kt`, `build.sh verify-release` |
| Une seule dépendance d'exécution : `androidx.webkit` (le futur AUTH-011 ajoute `androidx.credentials`, `googleid`) | `app/build.gradle.kts` |
| Clé de publication : PKCS12, RSA 4096, alias `repas`, validité 10 000 jours, un seul mot de passe ; 4 secrets GitHub ; sauvegardes en deux copies hors du PC (procédure du propriétaire) | `android/PUBLICATION.md` étapes 1 à 3 |
| `assetlinks.json` publié (`delegate_permission/common.handle_all_urls`, paquet, une empreinte SHA-256) ; le workflow en tire `ANDROID_EXPECTED_CERT_SHA256` et refuse un APK signé d'une autre clé | `public/.well-known/assetlinks.json`, `android-release.yml` |
| Workflow : déclenché **seulement** par l'étiquette ; job `build` (lecture seule, voit les secrets dans deux étapes) puis job `publish` (écriture, aucun secret) ; release GitHub avec `repas-drive.apk`, `.sha256`, `version.json` | `.github/workflows/android-release.yml` |
| `version.json` est publié mais **aucun code ne le lit** ; la page d'aide « Mettre à jour l'application » renvoie à `https://github.com/kcharignon/repas-front/releases` | `android/README.md`, `src/features/drive/appInstall.ts`, `AppHelpLink.vue`, `src/i18n/fr.ts` (`driveHelp.*`) |
| Le site (Caddy du front) sert un fichier de `public/` s'il existe, sinon `index.html` ; CSP : aucun site externe | `docker/Caddyfile` |
| Routes publiques du front : `/connexion`, `/inscription`, `/verification-email`, `/confirmation-email` ; la page des conditions (`/drive/conditions`) est derrière la connexion (AUTH-010 la rend publique) ; aucune page de confidentialité, aucune suppression de compte par l'utilisateur | `src/router/index.ts`, `docs/auth/tickets/AUTH-010-…` |
| Suppression de compte : seulement `DELETE /api/v1/users/{id}` réservé à `ROLE_ADMIN` ; un administrateur ne supprime pas son propre compte ; le compte `User::PROTECTED_EMAIL` est intouchable ; purge `Repas` + `Drive` dans la transaction | [ADR 0021](../adr/0021-gestion-des-comptes-par-l-administrateur.md), `CLAUDE.md` |
| Les noms des recettes des autres utilisateurs sont listés à tout utilisateur connecté (`listOfOtherPeople`) | `src/Repas/Infrastructure/Loader/RecipePostgreSQLLoader.php` |
| Inscription ouverte à toute adresse (statut `PENDING` jusqu'au lien de confirmation), e-mails par l'API de Brevo ; hébergement DigitalOcean (région non documentée dans le dépôt), sauvegarde nocturne, 14 conservées | [ADR 0020](../adr/0020-confirmation-d-adresse-email.md), `docs/production.md`, `docs/drive/note-vie-privee.md` |

## 2. Modes de distribution (question 1)

| Mode | Qui peut installer | Limite | Revue et délai | Exigence propre | Verdict |
|---|---|---|---|---|---|
| **Test interne** | testeurs listés par e-mail, via un lien | 100 par application | « peut ne pas être soumis aux revues standard » ; une nouvelle version arrive « en quelques minutes » ; à la première mise en ligne, nom provisoire et fiche peuvent s'afficher jusqu'à 48 h | aucune durée ni nombre minimal ; fiche non obligatoire pour démarrer ; **formulaire « sécurité des données » non exigé** pour une application seulement en test interne | **Maintenant, pour la famille** |
| **Test fermé** | listes d'e-mails (200 listes de 2 000 au plus) ou groupes Google | — | revue non documentée ; après la première mise en ligne, le lien peut mettre « plusieurs heures » | **compte personnel : 12 testeurs inscrits en continu depuis 14 jours** avant « Demander l'accès à la production » | **Passage obligé** vers le public |
| **Test ouvert** | tous, par la fiche ou un lien | illimité (limite éventuelle ≥ 1 000) | non documentée | — | Écarté (trouvable par tous, sans intérêt) |
| **Production** | tous les utilisateurs des pays choisis | — | examen « jusqu'à 7 jours, plus dans des cas exceptionnels » ; pas de pourcentage de déploiement à la première version | demande d'accès après le test fermé (compte personnel) | **Plus tard** |
| **Privée (Managed Google Play)** | les organisations dont on donne l'identifiant (jusqu'à 1 000) | — | — | identifiant d'organisation de la console de gestion d'appareils ; **jamais publique ensuite** | **Écartée** |

Sources : [Tester une application](https://support.google.com/googleplay/android-developer/answer/9845334), [Exigences de test des nouveaux comptes personnels](https://support.google.com/googleplay/android-developer/answer/14151465), [Publier des applications privées](https://support.google.com/googleplay/android-developer/answer/9874937), [Publier votre application](https://support.google.com/googleplay/android-developer/answer/9859751), [Data safety](https://support.google.com/googleplay/android-developer/answer/10787469) (toutes consultées le 2026-10-19, non datées).

**Le plus adapté à « moi et mes proches maintenant, public peut-être plus tard »** : le **test interne** pour la famille dès que le compte est validé (une à deux semaines), et en parallèle le **test fermé** qui est de toute façon le prix d'entrée de la production. Rien n'oblige à publier en production : un test interne peut rester le canal de la famille. *(Supposé : un test interne reste utilisable sans limite de durée ; la page ne le garantit pas.)*

## 3. Compte développeur (question 2)

| | Personnel (recommandé) | Organisation |
|---|---|---|
| Coût | 25 USD une fois, carte Visa/Mastercard/Amex (pas de prépayée) | idem |
| Identité | pièce d'identité officielle, profil de paiement Google (nom légal et adresse), âge 18 ans, **vérification d'un vrai téléphone Android** (10 ou plus, non rooté) par l'application Play Console, moins d'une minute | pièce d'identité, document de l'organisation, **numéro D-U-N-S** (gratuit, jusqu'à 30 jours, aucune dérogation) |
| Public sur Google Play | nom de développeur (modifiable), **nom légal, pays**, e-mail du développeur ; adresse complète seulement si l'on vend | nom légal, adresse légale, e-mail et téléphone du développeur |
| Test avant production | **oui : 12 testeurs, 14 jours** | non (la page ne vise que les comptes personnels créés après le 2023-11-13) |
| Délai de validation | paiement : jusqu'à 5 jours ; identité : **non documenté** | D-U-N-S : jusqu'à 30 jours, puis paiement |

Sources : [Démarrer avec Play Console](https://support.google.com/googleplay/android-developer/answer/6112435), [Informations requises](https://support.google.com/googleplay/android-developer/answer/13628312), [Coordonnées](https://support.google.com/googleplay/android-developer/answer/10840893), [Vérifier l'identité](https://support.google.com/googleplay/android-developer/answer/10841920), [Vérification de l'appareil](https://support.google.com/googleplay/android-developer/answer/14316361) (consultées le 2026-10-19).

Deux pages d'aide se contredisent sur la visibilité : l'une dit que le **nom légal et le pays** du compte personnel sont affichés, l'autre ne cite que l'e-mail du développeur. **Présenter au propriétaire la plus prudente** : son nom légal et son pays peuvent apparaître sur la fiche. C'est une raison d'avoir un nom de développeur choisi et une adresse d'assistance dédiée, et de le lui dire avant de payer. *(Déclaration « commerçant / non-commerçant » du règlement européen sur les services numériques : je n'ai trouvé **aucune page officielle de Google Play** sur ce point ; des guides tiers disent que les coordonnées déclarées sont publiques. À lire à l'écran à l'inscription ; pour une application gratuite sans publicité ni activité professionnelle, la déclaration « non-commerçant » est vraisemblable, le propriétaire en répond seul.)*

**Vérification des développeurs d'applications (Android).** Vérifié : à partir du **2026-09-30**, la vérification s'applique aux installations faites depuis des boutiques « participantes » dans **quatre pays** (Brésil, Indonésie, Singapour, Thaïlande), sur les appareils certifiés Android 7 ou plus ; « dans le monde pour toutes les applications en 2027 », sans date, et **sans mention de la France** ([page développeurs, 2026-10-19 ; FAQ mise à jour le 2026-09-30](https://developer.android.com/developer-verification/guides/faq), [guides](https://developer.android.com/developer-verification/guides)). Le chemin pour une application « à la fois sur Play et hors Play » est : « utiliser votre compte Play Console, **où il y aura** une nouvelle fonction pour enregistrer vos applications hors Google Play et leurs clés » (futur : pas encore là). Google Play enregistre automatiquement 99 % des applications de ses développeurs ; la mise à jour de politique du 2026-07-15 dit que les applications doivent être enregistrées dans Play Console et que celles distribuées hors Play peuvent l'être aussi. Pour la voie « APK seul », il existe un **compte de distribution limitée, gratuit, sans pièce d’identité, jusqu’à 20 appareils** (annoncé pour août 2026 ; lancement non vérifié). **Conséquence** : publier sur Play, même seulement en test, règle d'avance cette question pour la famille ; et si le propriétaire renonçait à Play, un compte de distribution limitée couvrirait « lui et ses proches » (≤ 20 téléphones). *(Perdre la clé de signature empêche d'enregistrer le paquet : raison de plus pour la sauvegarde.)*

## 4. Signature (question 3)

### 4.1 Ce que dit Google (vérifié)

- Une nouvelle application est inscrite par défaut à **Play App Signing** avec une clé **générée par Google** en signature **« hybride quantique »** : une clé RSA 4096 classique + une clé post-quantique ML-DSA-65 ; les appareils Android 17 et plus vérifient la signature v3.2, **les plus anciens la signature classique** ; « votre application utilise alors **trois clés distinctes** » et il faut **enregistrer les empreintes des trois** chez les fournisseurs d'API (dont `assetlinks.json`). ([Utiliser Play App Signing](https://support.google.com/googleplay/android-developer/answer/9842756), consultée le 2026-10-19 ; [blog Google, 2026-03-25](https://blog.google/security/security-for-the-quantum-era-implementing-post-quantum-cryptography-in-android/).)
- « Les développeurs avancés qui veulent gérer leur propre clé peuvent **changer ce défaut avant qu'une version soit déployée en test ouvert ou en production** » : *Protégé avec Play > Distribution sur le Play Store > Accéder à la signature d'application Play > Changer la clé de signature*, puis « utiliser la même clé qu'une autre application du compte » ou « **fournir une copie de votre clé de signature** » en suivant l'assistant (téléchargement et exécution de l'outil **PEPK**, qui chiffre la clé, puis envoi du fichier). La page de première version redit : « une fois que vous publiez sur une piste ouverte, la clé est fixée ».
- Les clés personnalisées doivent être **RSA 2048 bits ou plus** (la nôtre : 4096) ; la clé de signature d'une application publiée doit avoir une **validité qui se termine après le 22 octobre 2033** (la nôtre : 10 000 jours, soit environ 27 ans) ([Signer votre application, page du 2026-03-06](https://developer.android.com/studio/publish/app-signing)).
- Clé d'envoi : un fichier Java Keystore, RSA 2048 bits ou plus ; « pour une sécurité maximale, la clé d'envoi et la clé de signature doivent être **différentes** » ; perdue ou compromise, elle se **réinitialise** (*Protégé avec Play > Gérer la signature d'application Play > Demander une réinitialisation de la clé d'envoi*, certificat PEM).
- Les empreintes de la clé détenue par Google se lisent dans la console (*signature d'application Play*, ou *Release > Setup > App integrity*) ; **il faut déclarer celle-là, pas celle de la clé d'envoi**, auprès des fournisseurs d'API et dans `assetlinks.json` ([page Play](https://support.google.com/googleplay/android-developer/answer/9842756), [Google Play services, 2026-10-16](https://developers.google.com/android/guides/client-auth)).
- Android accepte une mise à jour si le certificat de la nouvelle version est le même que celui de l'installée ; sinon il faut un autre nom de paquet ([Signer votre application, 2026-03-06](https://developer.android.com/studio/publish/app-signing)).

### 4.2 Réponse à « peut-on importer la clé actuelle ? »

**Oui, c'est le chemin documenté**, et c'est celui recommandé : empreintes inchangées, APK déjà installés compatibles. Le fichier du propriétaire (`repas-publication.p12`, RSA 4096, PKCS12, alias `repas`) remplit les conditions de taille et de validité. **Ce qui n'est pas prouvé** et se lève à STORE-005, avant toute publication :

1. L'outil PEPK **accepte-t-il un fichier PKCS12** (le dépôt n'a que celui-là) ? *(Supposé oui. Repli : `keytool -importkeystore` vers un `.jks`, sans changer la clé.)*
2. **Une clé importée est-elle quand même mise en signature hybride** ? La page dit « par défaut, quand vous envoyez votre bundle, l'application est inscrite à la signature hybride » et décrit le choix d'une clé personnelle comme une option du défaut ; elle **ne dit pas** si l'hybride s'applique alors. Si oui, la console affichera trois empreintes et les appareils Android 17 verront une autre clé classique : une mise à jour par-dessus un APK installé à la main pourrait y échouer. À lire à l'écran (nombre d'empreintes) et, si possible, à essayer sur un téléphone Android 17.
3. **Google Play propose-t-il la mise à jour d'une application installée à la main** (même paquet, même certificat, code plus grand) ? *(Supposé oui ; non documenté. Repli : désinstaller puis réinstaller depuis Play, ce qui efface la connexion au drive, à refaire une fois.)*

### 4.3 Les deux cas

| | **A. Clé importée (recommandé)** | **B. Clé générée par Google (repli)** |
|---|---|---|
| Empreinte SHA-256 de `assetlinks.json` | inchangée (si une seule empreinte affichée) | **ajouter** celle(s) de la console ; garder l'ancienne tant que l'APK GitHub existe |
| Client OAuth Android (AUTH-002 étapes 11–13) | SHA-1 de la clé actuelle = celui de la console | SHA-1 de la console (et un second client si l'APK GitHub reste) |
| APK déjà installés à la main | se mettent à jour par Play (supposé) | **réinstallation** (certificat différent) |
| APK de la release GitHub | continue à s'installer par-dessus Play | **ne s'installe plus par-dessus** : retirer ce canal |
| Perte de la clé de publication | Google en garde une copie | Google la détient seul |
| Hybride | à observer (4.2, point 2) | oui |

### 4.4 La clé d'envoi

La CI signe l'AAB avec une **clé d'envoi nouvelle** (RSA 4096, PKCS12, créée par le propriétaire dans son terminal comme la première), distincte de la clé de publication. Elle ajoute **quatre secrets GitHub** (`ANDROID_UPLOAD_KEYSTORE_BASE64`, `…_PASSWORD`, `ANDROID_UPLOAD_KEY_ALIAS`, `…_KEY_PASSWORD`) ; son empreinte SHA-256 (publique) est écrite dans `android/upload-cert.sha256`, que le contrôle de l'AAB compare. Tant que l'APK GitHub existe, les quatre secrets de la clé de publication restent aussi.

### 4.5 Séquence (avec la connexion Google)

```
STORE-004  sauvegarder la clé actuelle (2 copies, restauration essayée) + créer la clé d'envoi
   │
STORE-003  compte développeur (25 USD, identité, téléphone)  ──►  validé (jours, non documenté)
   │
STORE-005  créer l'application, « Changer la clé de signature » AVANT tout envoi
   │         ├─ PEPK sur la clé actuelle ─► empreintes affichées par la console
   │         └─ comparer à assetlinks.json ; compter 1 ou 3 empreintes
   │
   ├──► STORE-007  assetlinks.json / documentation (seulement si l'empreinte diffère)
   ├──► AUTH-002 étapes 11 à 13  client OAuth Android = SHA-1 lu dans la console      ◄── BLOQUANT
   └──► STORE-016  premier AAB en test interne ─► installation par-dessus, App Links,
                    connexion Google (AUTH-013 partie C rejouée sur l'application Play)
```

Rien d'autre n'est bloqué : le projet Google Cloud, l'écran de consentement et le client Web (AUTH-002 étapes 1 à 10), le code d'AUTH-003 à AUTH-012 et AUTH-015 ne dépendent pas de la clé. Créer le client Android **maintenant** avec la clé actuelle est possible (si l'empreinte change, il faut modifier ou recréer le client, et l'aide Google Cloud dit que ces changements peuvent mettre de 5 minutes à quelques heures à s'appliquer) mais on risque de refaire l'étape ; **recommandation : attendre STORE-005**, qui peut être fait dès que le compte est validé.

## 5. Format et build (question 4)

**Vérifié.**

- L'**Android App Bundle est obligatoire** pour toute nouvelle application depuis août 2021 ([guide, page du 2026-06-24](https://developer.android.com/guide/app-bundle)). Google Play génère et signe les APK de chaque appareil à partir du bundle.
- **Niveau d'API cible** : les nouvelles applications et mises à jour doivent cibler **Android 16 (API 36) ou plus depuis le 2026-08-31** ; l'application cible 36 : conforme. La page n'annonce rien pour 2027 mais le cycle est annuel : prévoir un relevé chaque été ([page du 2026-10-16](https://developer.android.com/google/play/requirements/target-sdk)).
- **Pages de 16 Ko** : exigées pour les applications ciblant Android 15 ou plus ; « si votre application n'utilise que du Java ou du Kotlin, bibliothèques comprises, elle prend déjà en charge les appareils 16 Ko » ; AGP 8.5.1 ou plus pour le cas du code natif (nous : 8.13.2) ([page du 2026-10-16](https://developer.android.com/guide/practices/page-sizes)). L'application n'a **aucun code natif** : le contrôle consiste à vérifier l'absence de dossier `lib/` dans l'AAB.
- **64 bits** : concerne les applications avec du code natif ; sans objet ici (même contrôle).
- **Code de version** : entier positif, plafond 2 100 000 000, **jamais réutilisé** sur Play ([Versionner votre application, 2025-08-20](https://developer.android.com/studio/publish/versioning)) : la formule du dépôt (étiquette → `X·10⁶ + Y·10³ + Z`) convient, et un même numéro d'étiquette donne le même code à l'APK et à l'AAB.
- **Envoi** : la console accepte un `.aab` à la main (« Test interne > Créer une version »). L'API d'envoi (`edits.bundles.upload`) existe, avec un **compte de service** invité dans *Utilisateurs et autorisations* ([prise en main, 2025-12-18](https://developers.google.com/android-publisher/getting_started), [référence, 2025-05-21](https://developers.google.com/android-publisher/api-ref/rest/v3/edits.bundles/upload)).

**Supposé** (à prouver au ticket STORE-006) :

- La tâche Gradle `:app:bundleRelease` signe l'AAB avec la configuration `signingConfig` de la variante (comportement standard d'AGP) ; pour signer l'AAB de la clé d'envoi et l'APK de la clé de publication, le script choisit le jeu de variables d'environnement.
- Le premier envoi d'une nouvelle application doit se faire **à la main** : l'API ne crée pas l'application (des guides d'outils tiers le disent ; la documentation officielle lue n'en parle pas).
- Désactiver le découpage par langue (`bundle { language { enableSplit = false } }`) : l'application n'a qu'une langue.

**Comment l'étiquette produit APK et AAB.** Le job `build` de `android-release.yml` garde son ordre (étiquette, empreinte attendue, agent, tests Kotlin, APK signé, contrôle) puis ajoute : « AAB signé de la clé d'envoi » (`make android-bundle`, secrets d'envoi dans l'environnement de cette seule étape) et « contrôle de l'AAB » (`make android-bundle-verify`) : `bundletool validate`, manifeste (paquet, code et nom de version de l'étiquette, `minSdk 26`, `targetSdk 36`, une seule permission, non débogable), certificat de signature = `android/upload-cert.sha256`, agent embarqué = `agent.js.sha384`, ni plan d'essai ni `lib/`. `bundletool` est téléchargé dans l'image Docker avec une empreinte épinglée, comme Gradle. L'AAB est publié comme **artefact du workflow** (pas dans la release publique : la page d'aide dit aux proches de prendre le `.apk`) ; le propriétaire le télécharge avec `gh run download` et le téléverse dans la console. Le job `publish` ne change pas.

**Automatiser l'envoi plus tard (facultatif, STORE-021).** Un compte de service (secret dans un **environnement GitHub** à approbation humaine), avec la seule permission de publier sur les pistes de test (la page de première version cite la permission « Publier des versions sur des pistes de test » ; qu'elle exclue la production est **supposé**), et une action pinnée par empreinte de commit ; la promotion en production reste dans la console. À ne faire qu'après trois envois manuels réussis.

## 6. Conformité aux règles de Google Play (question 5)

Aucun avis juridique sur les conditions d'utilisation de Leclerc (sujet clos par le propriétaire) : on s'en tient aux **règles du Play Store**. Tableau d'abord, détail ensuite.

| Règle (source) | Cette application | Risque de refus | Action |
|---|---|---|---|
| Webview d'un site sans l'accord de son propriétaire ([Spam](https://support.google.com/googleplay/android-developer/answer/9899034)) | le propriétaire administre `kantin.eu` | faible | décrire les vraies fonctions |
| « Fonctionnalité limitée » ([Fonctionnalité, contenu](https://support.google.com/googleplay/android-developer/answer/9898783)) | tout Repas vit sur le site ; le natif (pont, drive, App Links, connexion Google) est modeste | faible à moyen | compte de relecture garni, captures réelles |
| « Accéder à un service ou une API d'une manière qui viole ses conditions » ([Appareil et réseau](https://support.google.com/googleplay/android-developer/answer/9888379)) | l'écran du drive agit dans la session de l'utilisateur sur le site d'un tiers | **inconnu (moyen ?)** | § 6.b |
| Webview avec `addJavascriptInterface` | aucun (`addWebMessageListener`) | nul | rien |
| Fonctions cachées, dormantes ou non documentées ; comportement identique pour le relecteur ([Comportement trompeur](https://support.google.com/googleplay/android-developer/answer/9888077)) | le drive est une vraie fonction | moyen si on le cache | le décrire, ne pas le masquer au relecteur |
| Marques et logos d'un tiers ([Propriété intellectuelle](https://support.google.com/googleplay/android-developer/answer/9888072), [Métadonnées](https://support.google.com/googleplay/android-developer/answer/9898842), [Visuels](https://support.google.com/googleplay/android-developer/answer/9866151)) | l'enseigne apparaît dans le drive | faible si bien traité | § 6.c |
| Suppression de compte dans l'application **et** sur le web ([Exigences](https://support.google.com/googleplay/android-developer/answer/13327111), [Données des utilisateurs](https://support.google.com/googleplay/android-developer/answer/10144311)) | **absente pour l'utilisateur** | **certain si omise** | STORE-008, STORE-009 |
| Politique de confidentialité en ligne et dans l'application | **absente** | **certain si omise** | STORE-010 |
| Contenus d'utilisateurs : conditions, modération, **signaler et bloquer** ([UGC](https://support.google.com/googleplay/android-developer/answer/9876937)) | recettes des autres visibles | moyen | STORE-011, STORE-012 (D5) |
| Compte de relecture si l'application demande une connexion ([Préparer l'examen](https://support.google.com/googleplay/android-developer/answer/9859455)) | connexion obligatoire | élevé si omis | STORE-013 |
| Sécurité des données, classification, public cible, publicités | à remplir | moyen (incohérences) | annexe A, STORE-017 |

### 6.a Application « enveloppe de site »

**Vérifié** : « Nous n'autorisons pas les applications dont le but principal est de fournir une *webview* d'un site **sans l'autorisation de son propriétaire ou administrateur** » (exemple : une application qui affiche Google Shopping sous un autre nom). Le propriétaire possède et administre `kantin.eu` : la condition est remplie. La règle voisine « on n'autorise pas les applications qui ont une fonctionnalité et un contenu limités » (exemples : applications statiques, texte seul) ne vise pas une application de recettes et de listes complète. **Supposé** : Google ne dit pas comment un relecteur distingue ; une coquille pure peut être jugée légère. Parades : ce que fait réellement le natif (pont, App Links vérifiés, connexion Google par Credential Manager, écran du drive, écran allumé pendant un envoi) est décrit dans la fiche sans emphase ; le compte de relecture est garni (recettes, une liste).

### 6.b L'écran du drive (agent JavaScript dans la session de l'utilisateur)

**Vérifié, texte de la politique « Appareil et réseau »** : « Nous n'autorisons pas les applications qui interfèrent avec, perturbent, endommagent ou **accèdent de manière non autorisée** à l'appareil de l'utilisateur, à d'autres appareils, serveurs, réseaux, **API ou services** » ; parmi les exemples de violations : « Applications qui accèdent à un service ou une API **d'une manière qui viole ses conditions d'utilisation** » et « Applications ou code tiers contenant une *webview* avec **une interface JavaScript ajoutée** ». La page **ne donne aucun exemple d'automatisation ou de collecte** d'un site tiers. Le code dynamique est interdit sauf « le code qui s'exécute dans une machine virtuelle ou un interpréteur qui n'offre qu'un accès indirect aux API d'Android », avec **« JavaScript dans une webview »** comme exemple.

Lecture pour cette application :

- Pas d'interface JavaScript ajoutée (vérifié dans le code) ; l'agent est **embarqué** dans l'APK, vérifié par empreinte SHA-384 au build et au démarrage : ce n'est pas du code téléchargé.
- La règle sur les conditions d'utilisation **renvoie aux conditions du service tiers**. Leur contenu n'est pas analysé ici (sujet clos). Ce qui compte pour la décision : **Google peut refuser ou, plus tard, suspendre** si un relecteur ou un signalement y voit une violation. C'est l'**inconnue principale** ; elle ne se lève qu'en soumettant l'application. Le risque est limité par : l'usage à la demande de l'utilisateur, avec sa connexion, un produit à la fois, avec des pauses, sans jamais valider de commande (`principes-produit.md`, ADR 0022).
- **Présenter honnêtement** (règle « fonctions cachées », « comportement identique pour le relecteur ») : la fiche décrit la fonction en trois phrases (modèle en § 7) ; la page de confidentialité et les conditions la décrivent ; le relecteur voit le même écran que tout le monde (pas de masquage) ; la note pour le relecteur dit que cette fonction est facultative et demande un compte chez l'enseigne.
- **Parades techniques si Google refuse** : (1) `DRIVE_ENABLED` coupé côté serveur retire la fonction pour tous sans nouvelle version (déjà en place, `docs/drive/runbook.md`) ; (2) un ticket **conditionnel** (STORE-022) écrit une variante du store sans l'écran du drive.

### 6.c Le nom « Leclerc » et les marques

**Vérifié** : « N'utilisez pas les marques d'un tiers, comme des logos ou des noms de marque, sans autorisation et/ou d'une manière qui pourrait prêter à confusion » ; une application qui « fait croire à tort qu'elle est l'application officielle d'une entité établie » est interdite ; les visuels doivent éviter « les marques ou logos de tiers sans autorisation » ; le titre fait 30 caractères au plus, sans émoticône, sans majuscules abusives ; « ne pas utiliser le logo d'une marque sans autorisation ». Les pages ne traitent **pas** de la simple mention descriptive d'un nom.

Recommandation : (1) **titre, icône, image de présentation et captures sans aucun nom ni logo d'enseigne** ; (2) les captures montrent les écrans de Repas (liste, « Envoyer au drive », récapitulatif) avec des données fictives, **jamais le site de l'enseigne** ; (3) le nom de l'enseigne apparaît **une fois** dans la description longue, pour dire avec quoi l'envoi fonctionne, suivi de « Repas n'est ni affilié à E.Leclerc, ni approuvé par lui » (décision D4). La règle 10 des principes produit (« aucun avertissement dans le parcours ») vise les écrans de l'application, pas la fiche du store ; cette phrase est un fait, pas un avertissement. Le nom du développeur ne contient pas le nom de l'enseigne. *(Écarter la mention complètement est possible mais rend la description moins franche ; demander une autorisation écrite à l'enseigne est exclu par le propriétaire.)*

### 6.d Suppression de compte

**Vérifié** ([exigences](https://support.google.com/googleplay/android-developer/answer/13327111), [données des utilisateurs](https://support.google.com/googleplay/android-developer/answer/10144311)) :

- S'applique aux applications qui permettent de **créer un compte** dans l'application (ou renvoient vers un flux de création) ; même si certaines fonctions marchent sans compte. Repas : l'inscription est dans la page chargée par l'application : **dans le champ**.
- Il faut un **chemin dans l'application**, visible (réglages du compte) et un **lien web** où l'utilisateur demande la suppression du compte **et** des données associées, sans devoir réinstaller l'application. La page web doit nommer l'application ou le développeur **comme sur la fiche**, présenter le chemin de suppression de manière visible, dire quelles données sont supprimées ou conservées et pour combien de temps. Une adresse e-mail d'assistance ou un formulaire sont acceptés comme moyen de demande.
- « Geler » un compte **ne suffit pas**. Les sauvegardes et les données conservées pour sécurité ou obligation légale doivent être annoncées (nous : sauvegardes nocturnes, 14 conservées, **environ deux semaines**).
- Le formulaire « sécurité des données » demande le lien et si l'application propose la suppression.

Aujourd'hui : seul un administrateur supprime (ADR 0021) ; l'état `DISABLED` est du gel. **Tickets** : STORE-008 (back : cas d'usage `DeleteOwnAccount`, `POST /api/v1/users/me/deletion`, nouvelle règle : tout utilisateur supprime son propre compte, sauf le compte protégé et le **dernier administrateur actif** ; ADR 0021 §1 amendé ; confirmation par le mot de passe, ou par l'adresse saisie pour un compte sans mot de passe ; plafond de débit car la route teste un mot de passe) et STORE-009 (front : « Supprimer mon compte » dans « Moi », page publique `/suppression-de-compte`).

### 6.e Politique de confidentialité, fiche « sécurité des données », classification, public cible, publicités, permissions

- **Politique de confidentialité** ([Données des utilisateurs](https://support.google.com/googleplay/android-developer/answer/10144311)) : lien dans la console **et** lien ou texte **dans l'application** ; elle décrit « comment l'application accède, collecte, utilise et partage les données » et la conservation et la suppression. Page publique `/confidentialite` (STORE-010), servie sans connexion ; sa base est `docs/drive/note-vie-privee.md` + les données du compte (e-mail, mot de passe haché, identifiant Google, recettes, listes) + les prestataires (hébergeur, service d'e-mails, Google pour la connexion). **Hors cadre familial**, la qualification juridique (base légale, mentions de l'éditeur) est à revoir par le propriétaire ; je ne la tranche pas (`note-vie-privee.md` § 4 : « à qualifier par le propriétaire »). L'éditeur (nom ou pseudonyme, adresse d'assistance) est une donnée à fournir par le propriétaire.
- **Sécurité des données** ([aide](https://support.google.com/googleplay/android-developer/answer/10787469)) : obligatoire pour toute application publiée (**vérifié** : les applications seulement en test interne en sont dispensées ; **supposé** : le test fermé y est soumis, la page ne cite que le test interne). « Collecter » = transmettre hors de l'appareil, **y compris les données d'une webview dont l'application contrôle le code ou le comportement** (cas de l'écran du drive et de son agent). Les prestataires qui traitent pour notre compte ne comptent pas comme « partage ». Réponses proposées : annexe A.
- **Classification du contenu** ([aide](https://support.google.com/googleplay/android-developer/answer/9859655)) : questionnaire par application ; sans classification, l'application est « non classée » et peut être retirée (mise à jour de politique du 2026-07-15).
- **Public cible** ([aide](https://support.google.com/googleplay/android-developer/answer/9285070)) : choisir **18 ans et plus** évite la politique Familles (publicités, âge). Un style visuel enfantin dans la fiche peut faire refuser ; l'identité « Basilic & safran » n'en a pas.
- **Publicités** : « Non » (aucun SDK). **Permissions** : seulement `INTERNET`, aucune déclaration spéciale.
- **Accès à l'application** : fournir un compte de relecture (STORE-013), avec instructions ; jusqu'à cinq jeux d'identifiants possibles.

### 6.f Contenus des utilisateurs

**Vérifié** ([UGC](https://support.google.com/googleplay/android-developer/answer/9876937)) : « contenu que les utilisateurs apportent à l'application », visible d'au moins une partie des utilisateurs. Les applications qui en contiennent doivent : faire **accepter des conditions** avant de créer du contenu (qui définissent le contenu répréhensible), **modérer** de façon raisonnable et cohérente, et fournir un moyen **de signaler et de bloquer** dans l'application. La page ne distingue pas contenu principal et accessoire pour la modération. Repas : les noms et le texte des recettes des autres sont visibles par tout compte actif ; l'ouverture au public rendrait des inconnus visibles les uns aux autres. **Risque moyen**, évité par l'une de trois options (décision D5) : (A) ne pas montrer « Recettes des autres » dans la version publique ; (B) **signaler** une recette + mention des conditions à l'inscription + retrait par l'administrateur (recommandé, STORE-011 et STORE-012) ; (C) accepter le risque pour le test fermé et décider avant la production.

### 6.g Lien avec la connexion Google

- L'empreinte du client OAuth Android est celle du certificat **qui signe l'application installée** : avec Play, celle de la clé que Google détient (§ 4.1). Le projet Google Cloud de la connexion n'exige pas Google Play, mais AUTH-013 « I1 » (l'APK signé hors Google Play est-il accepté ?) devient sans objet pour le canal principal.
- La page de confidentialité et la fiche « sécurité des données » doivent mentionner l'identifiant Google (`sub`) et l'adresse retenus (AUTH-010 pour les conditions). Aucune portée sensible (`openid email profile`) : pas de vérification Google nécessaire.
- Google Play n'impose pas de connexion Google. *(Les règles « API Services User Data Policy » de Google n'ont pas été relues ici : non vérifié.)*

## 7. Fiche du Play Store (question 6)

**Contraintes vérifiées** ([Fiche](https://support.google.com/googleplay/android-developer/answer/9859152), [Visuels](https://support.google.com/googleplay/android-developer/answer/9866151), [Métadonnées](https://support.google.com/googleplay/android-developer/answer/9898842)) : titre ≤ 30 caractères ; description courte ≤ 80 ; description longue ≤ 4 000 ; pas d'émoticône ni de caractères répétés ; pas de MAJUSCULES sauf nom de marque ; pas de mots-clés répétés ; aucun terme de classement ou de promotion (« meilleur », « n° 1 », « gratuit » en accroche, « télécharge maintenant »). **Icône** : PNG 32 bits avec alpha, **512 × 512 px**, ≤ 1 024 Ko. **Image de présentation** : JPEG ou PNG 24 bits sans alpha, **1 024 × 500 px**. **Captures téléphone** : 2 au moins pour publier (8 au plus par type d'appareil), JPEG ou PNG 24 bits sans alpha, côté de 320 à 3 840 px, le plus long côté ≤ 2 fois le plus court ; **4 au moins recommandées** (9:16, 1 080 × 1 920 minimum) ; texte d'accroche ≤ 20 % de l'image ; vraie interface ; pas de cadre d'appareil ; texte alternatif de 140 caractères au plus. **Coordonnées** : e-mail d'assistance obligatoire, site recommandé. **Catégorie** : une seule (« Alimentation et boissons » / *Food & Drink* : libellé exact lu dans la console). Vidéo : facultative, sans objet.

**Brouillon de textes** (au tutoiement et au glossaire de `repas-po` ; **à relire par le propriétaire phrase par phrase** : chaque phrase doit être vraie, règle « les métadonnées ne doivent pas tromper » ; longueurs comptées le 2026-10-19).

- **Titre (27/30)** : `Repas : recettes et courses`
- **Description courte (66/80)** : `Tes recettes, ta liste de courses et l’envoi au drive, simplement.`
- **Description longue (≈ 1 100/4 000)** :

```
Repas range tes recettes, prépare ta liste de courses et, si tu le veux, remplit ton panier de drive.

Tes recettes
• Écris tes recettes, ou copie celles des autres utilisateurs de Repas.
• Ajoute-les à ta liste : Repas additionne les ingrédients pour toi.

Ta liste de courses
• Les articles sont rangés par rayon.
• Coche ce que tu as pris. Retrouve ta liste sur tous tes appareils avec ton compte.

L’envoi au drive (facultatif)
• Touche « Envoyer au drive » : l’application ouvre le site de ton drive dans sa propre fenêtre. Tu t’y connectes toi-même.
• Elle cherche les produits de ta liste et les met dans ton panier, un par un, avec ton propre compte.
• Tu termines ta commande toi-même sur le site du drive : créneau et paiement. Repas ne valide jamais de commande et ne voit jamais ton mot de passe.
• L’envoi fonctionne avec les drives E.Leclerc. Repas n’est ni affilié à E.Leclerc, ni approuvé par lui.

Ton compte
• Connecte-toi avec ton adresse e-mail et ton mot de passe, ou avec Google.
• Tu peux supprimer ton compte et toutes tes données quand tu veux, depuis « Moi ».

Repas est gratuit et sans publicité.
```

Points à vérifier avant publication : « ou avec Google » seulement si la connexion Google est livrée ; « rangés par rayon » (rayons = départements) ; « copie celles des autres » (fonction de copie) ; la ligne sur l'enseigne si D4 change.

**Captures** (STORE-015) : prises dans l'émulateur contre le faux Repas (aucune donnée réelle, aucun magasin, aucun nom d'enseigne à l'écran) : liste de recettes, une recette, la liste de courses, l'écran « Envoyer au drive » avec son récapitulatif (écran de Repas), « Moi ». Jamais le site de l'enseigne. **Icône** : la feuille de basilic à nervure de safran de `ic_launcher_foreground.xml` sur fond basilic, rendue en 512 × 512. **Image de présentation** : même identité, sans texte promotionnel. **Contact** : adresse d'assistance dédiée (D2) ; site `https://kantin.eu` ; lien de confidentialité `https://kantin.eu/confidentialite`. **Aucune donnée personnelle** (nom, adresse, téléphone du propriétaire) dans les visuels ni dans les textes.

## 8. Mises à jour et suppression de l'ancien circuit (question 7)

- **Cohabitation APK / Play.** Tant que la clé de signature est la même (cas A), l'APK de la release GitHub et l'application installée par Play ont le même certificat et le même code pour la même étiquette : Android accepte l'un par-dessus l'autre si le code monte. Règle : **un numéro d'étiquette n'est jamais réutilisé** (déjà dans le workflow) ; Google Play **refuse un code déjà utilisé** ; le premier bundle porte une étiquette plus grande que `android-v0.1.8` (par exemple `android-v0.2.0`, code 2 000).
- **Que se passe-t-il sur un téléphone avec l'APK installé à la main ?** Il reçoit la mise à jour de Play si Google la propose (supposé, § 4.2 point 3) ; sinon : désinstaller puis installer depuis Play (connexion au drive à refaire, recettes et listes intactes sur le compte).
- **`version.json`** : publié à chaque release, jamais lu. Le conserver tant que l'APK GitHub existe, puis cesser de le produire (STORE-020). **Ne pas** écrire de vérification de version dans l'application : Google Play met à jour tout seul.
- **Le menu « Mettre à jour l'application »** (« Moi » > Application Android, page d'aide, rubrique `#mise-a-jour`) renvoie aujourd'hui à GitHub (`APP_RELEASES_URL`). Il devra renvoyer à la fiche Google Play (lien d'inscription au test tant que la fiche n'est pas publique ; adresse `https://play.google.com/store/apps/details?id=eu.kantin.repas.drive` ensuite), et le texte de l'aide devient : « Google Play met l'application à jour tout seul. Pour la mettre à jour tout de suite, ouvre Google Play, cherche Repas, puis touche Mettre à jour. » La rubrique sur Play Protect disparaît ; `android/PUBLICATION.md` garde une section « canal de secours ». Le test de glossaire du front reste vert.
- **Retrait de l'APK GitHub** : quand tous les téléphones de la famille sont passés par Play et que l'APK n'a plus servi pendant deux versions, cesser de publier l'APK (l'étiquette produit alors l'AAB seul) et ne conserver la clé de publication qu'en sauvegarde. À décider par le propriétaire (D3).
- **Liens `https://kantin.eu/…` (App Links)** : `autoVerify` lit `assetlinks.json` ; avec une clé importée il ne change pas ; avec une clé de Google, il porte **l'empreinte de la console**.

## 9. Ce que le propriétaire fait dans la console Google Play (question 8)

**Une action par étape, une étape par message** (skill `repas-ops` : but en une phrase, ce qu'il doit voir si c'est bon, puis on attend). Les noms de menus viennent de l'aide de Google en anglais ; la console s'affiche dans la langue du compte : l'assistant lit le libellé sur ce que le propriétaire décrit, il ne devine pas. **Jamais de mot de passe, de clé ni de code dans le chat** ; les empreintes (SHA-1, SHA-256) sont publiques et peuvent être copiées.

### Phase A — Le compte développeur (STORE-003)

| # | Action | But | Résultat attendu |
|---|---|---|---|
| A.1 | Ouvrir `https://play.google.com/console/signup`, connecté au compte Google qui **possédera** les applications | Ce compte devient propriétaire du compte développeur | La page d'inscription s'affiche |
| A.2 | Vérifier que la validation en deux étapes est active sur ce compte Google | Protéger le compte qui publie | « Validation en deux étapes : activée » |
| A.3 | Choisir **Personnel** | Pas de numéro D-U-N-S | Le formulaire du compte personnel s'affiche |
| A.4 | Saisir le **nom de développeur public** (modifiable plus tard) | C'est le nom affiché sous l'application | Nom enregistré |
| A.5 | Saisir l'**adresse e-mail du développeur** : l'alias d'assistance dédié, pas l'adresse personnelle | Elle est affichée publiquement | Un code arrive sur cette adresse |
| A.6 | Saisir le code reçu par e-mail | Prouver que l'adresse est à toi | Adresse vérifiée |
| A.7 | Saisir le numéro de téléphone de contact (non public), puis le code reçu par SMS | Google doit pouvoir te joindre | Téléphone vérifié |
| A.8 | Remplir le profil de paiement Google (nom légal et adresse) | Google s'en sert pour vérifier l'identité | Profil enregistré |
| A.9 | Payer les **25 USD** par carte (pas de carte prépayée) | Frais uniques d'inscription | Reçu par e-mail (à garder) |
| A.10 | Envoyer la pièce d'identité officielle demandée | Vérifier l'identité | Tâche « vérifier l'identité » envoyée |
| A.11 | Installer l'application **Play Console** sur un vrai téléphone Android (10 ou plus, non rooté) et vérifier l'appareil depuis l'accueil de la console | Google vérifie qu'un vrai téléphone existe | Tâche « appareil » cochée |
| A.12 | Attendre le message de Google (délai non documenté) | La console n'ouvre qu'une fois la vérification finie | Plus aucune tâche de vérification en attente sur l'accueil |
| A.13 | *Utilisateurs et autorisations* > inviter une seconde personne en **propriétaire** (facultatif, recommandé) | Si le compte est perdu, le reste l'est aussi | Deux propriétaires listés |

### Phase B — L'application et la clé de signature (STORE-005) — **bloquant pour AUTH-002 étapes 11 à 13**

| # | Action | But | Résultat attendu |
|---|---|---|---|
| B.1 | **Créer une application** : nom « Repas », langue par défaut français (France), type « Application », « Gratuite », cocher les déclarations | Créer l'espace de l'application (le nom de paquet se fixe au premier bundle) | Le tableau de bord de l'application s'ouvre |
| B.2 | Ouvrir *Protégé avec Play > Distribution sur le Play Store > Accéder à la signature d'application Play* | C'est là que se choisit la clé | La page « signature d'application » s'affiche |
| B.3 | Accepter les conditions de la signature d'application | Obligatoire pour continuer | Conditions acceptées |
| B.4 | Toucher **Changer la clé de signature de l'application**, **avant tout envoi de bundle** | Le défaut (clé de Google, hybride) change l'empreinte | Le choix des options s'affiche |
| B.5 | Choisir **Fournir une copie de votre clé de signature** | Importer la clé actuelle | Les instructions PEPK s'affichent |
| B.6 | Télécharger l'outil **PEPK** depuis le lien de la console, dans `~/repas-cle` (hors du dépôt) | L'outil chiffre ta clé avant l'envoi | Un fichier `pepk.jar` dans ce dossier |
| B.7 | Copier la **commande affichée** dans le chat à l'assistant (elle contient la clé publique de chiffrement de Google, rien de secret) | L'assistant l'adapte à ton fichier `.p12` et à Docker | L'assistant te rend la commande prête, sans jamais te demander le fichier ni le mot de passe |
| B.8 | Lancer la commande dans ton terminal et taper le mot de passe de la clé quand il le demande | Produire le fichier chiffré | Un fichier `.zip` chiffré |
| B.9 | Téléverser ce `.zip` dans la console | Google reçoit une copie chiffrée de ta clé | La console affiche le « certificat de signature de l'application » avec ses empreintes |
| B.10 | Copier la ou les lignes **SHA-256** et **SHA-1** affichées dans le chat | Les comparer à l'empreinte de `assetlinks.json` | L'assistant dit « identique » (cas A) ou « différente » (cas B, ticket STORE-007) |
| B.11 | Dire combien d'empreintes la console affiche (une ou trois) | Trois = signature hybride, à traiter | Le nombre est noté dans le ticket |
| B.12 | Au premier envoi de bundle (STORE-016), vérifier que la console affiche le certificat de la **clé d'envoi** et que son empreinte est celle de `android/upload-cert.sha256` | La CI signe avec la clé d'envoi | Les deux empreintes sont identiques |

### Phase C — Contenu de l'application et fiche (STORE-013, STORE-017)

| # | Action | But | Résultat attendu |
|---|---|---|---|
| C.1 | Ouvrir *Politique et programmes > Contenu de l'application* | Toutes les déclarations sont ici | La liste des déclarations s'affiche |
| C.2 | *Politique de confidentialité* : coller `https://kantin.eu/confidentialite` | Obligatoire | Lien enregistré |
| C.3 | *Accès à l'application* : saisir les identifiants du compte de relecture et l'explication (annexe A) | Sans eux, le relecteur ne peut pas entrer : refus fréquent | Instructions enregistrées |
| C.4 | *Publicités* : répondre **Non** | Aucun SDK publicitaire | Déclaration enregistrée |
| C.5 | *Classification du contenu* : remplir le questionnaire avec ton e-mail | Sans classification, l'application est retirée | Classification calculée |
| C.6 | *Public cible et contenu* : choisir **18 ans et plus** | Évite la politique Familles | Déclaration enregistrée |
| C.7 | *Sécurité des données* : répondre d'après l'annexe A | Obligatoire pour tout test fermé et la production | Formulaire enregistré |
| C.8 | Dans ce formulaire, saisir le lien de suppression `https://kantin.eu/suppression-de-compte` | Obligatoire (suppression de compte) | Lien accepté |
| C.9 | Répondre **Non** aux déclarations qui ne concernent pas Repas (actualités, santé, finances, gouvernement, COVID) | Elles sont obligatoires | Toutes cochées |
| C.10 | Remplir la *fiche principale* : titre, descriptions, icône, image de présentation, captures, catégorie, e-mail d'assistance, site | La page que verront les utilisateurs | Aucun avertissement de fiche incomplète |
| C.11 | Choisir les pays : **France** | Limiter l'ouverture au départ | « France » seule est cochée |

### Phase D — Test interne : la famille installe depuis Google Play (STORE-016)

| # | Action | But | Résultat attendu |
|---|---|---|---|
| D.1 | Télécharger l'AAB du workflow avec `gh run download` (l'assistant donne la commande) | Récupérer le fichier signé de la clé d'envoi | Un fichier `.aab` sur ton ordinateur |
| D.2 | *Tests > Test interne > Créer une version* | Première mise en ligne | L'écran de création de version s'ouvre |
| D.3 | Téléverser le `.aab` | Envoyer le bundle | La console l'accepte et affiche le code de version |
| D.4 | Donner un nom à la version (le numéro de l'étiquette) et enregistrer | Pour te repérer | Version enregistrée |
| D.5 | Toucher *Publier la version sur la piste de test interne* | Rendre l'application installable | Statut « Disponible pour les testeurs » |
| D.6 | Onglet *Testeurs* : créer une liste avec les adresses Google des proches | Seuls eux pourront installer | Liste enregistrée |
| D.7 | Copier le **lien d'inscription** et l'ouvrir sur **ton** téléphone d'abord | Essayer avant les autres | La page « Devenir testeur » s'affiche |
| D.8 | Toucher *Devenir testeur*, puis *Télécharger sur Google Play* | Installer ou mettre à jour | « Installer » ou « Mettre à jour » |
| D.9 | Ouvrir l'application et lire le numéro de version (« Menu », « Version et mode d'emploi ») | Vérifier que c'est la bonne | La version de l'étiquette, sans « débogage » |

### Phase E — Test fermé et demande de production (STORE-018)

| # | Action | But | Résultat attendu |
|---|---|---|---|
| E.1 | *Tests > Test fermé* : ouvrir ou créer la piste | Le test obligatoire de 14 jours | La piste s'affiche |
| E.2 | Créer une liste avec **au moins 15 adresses Google** | 12 sont exigées ; 3 de marge | Liste enregistrée |
| E.3 | Créer une version avec le même bundle et la publier | Les testeurs peuvent installer | Statut « Disponible pour les testeurs » |
| E.4 | Envoyer le lien d'inscription à chaque testeur (chacun : ouvrir, *Devenir testeur*, installer) | Il faut 12 testeurs **inscrits** | La console affiche au moins 12 testeurs |
| E.5 | Noter la date de début ; ne retirer personne de la liste pendant 14 jours | Quitter puis revenir recommence le décompte | 14 jours continus avec ≥ 12 |
| E.6 | Au 14ᵉ jour : *Tableau de bord > Demander l'accès à la production* | Débloquer la production | Le formulaire s'ouvre |
| E.7 | Remplir ses trois parties (test fermé, application, préparation à la production) | Google l'examine | Demande envoyée |
| E.8 | Attendre la réponse de Google (délai non documenté) | La production s'ouvre après accord | Production disponible |

### Phase F — Production (STORE-019)

| # | Action | But | Résultat attendu |
|---|---|---|---|
| F.1 | *Production > Créer une version* avec le **même bundle déjà testé** | Pas de nouveau code non éprouvé | Version prête |
| F.2 | Vérifier les pays : **France** | Rester limité | « France » seule |
| F.3 | Envoyer pour examen (« Enregistrer », puis « Envoyer les modifications pour examen ») | Google examine l'application | Statut « En cours d'examen » |
| F.4 | Attendre (jusqu'à 7 jours, parfois plus) | Examen par Google | Statut « Production » |
| F.5 | Ouvrir la fiche publique depuis un téléphone qui n'est pas testeur | Contrôler ce que voit le public | La fiche s'affiche, installation possible |
| F.6 | Signer la recette (STORE-019) | Clore le chantier | Tableau signé |

## 10. Risques, coût et chemin critique (question 9)

### Risques et inconnues

| # | Risque | Probabilité / impact | Parade |
|---|---|---|---|
| R1 | Refus ou suspension liés à l'écran du drive (règle sur les conditions d'un service tiers) | inconnue / fort | description franche, `DRIVE_ENABLED`, STORE-022 (variante sans drive) |
| R2 | Contenu des utilisateurs (recettes visibles) | moyenne / moyen | STORE-011, STORE-012 |
| R3 | Jugée « simple enveloppe de site » | faible à moyenne / moyen | fonctions natives décrites ; compte de relecture garni |
| R4 | Import de la clé refusé (PEPK, PKCS12) ou signature hybride : empreintes différentes | moyenne / moyen (réinstallation, 2 empreintes à changer) | essai à blanc en STORE-005, repli décrit (§ 4.3) |
| R5 | Moins de 12 testeurs ou désinscription : le décompte repart | moyenne / retard | recruter 15 et plus, dès maintenant (STORE-002) |
| R6 | Nom légal et pays visibles sur la fiche | certaine (d'après une page) / vie privée | le dire avant de payer ; nom de développeur choisi |
| R7 | Compte de relecture inutilisable | moyenne / élevé (refus) | STORE-013 : compte actif, e-mail déjà confirmé par l'administrateur, mot de passe stable |
| R8 | Suppression de compte ou confidentialité absente | certaine si omise / refus | STORE-008, 009, 010 avant le test fermé |
| R9 | Charge et support du public (serveur de 1 Go, e-mails Brevo, modération, e-mail d'assistance public) | moyenne / moyen | France seulement ; surveiller ; inscription ouverte à décider (D1) |
| R10 | Sortie du cadre familial : posture RGPD à qualifier | — / à voir par le propriétaire | `note-vie-privee.md` § 4 ; pas d'avis ici |
| R11 | Perte du compte développeur ou de la clé | faible / fort | second propriétaire ; clé d'envoi réinitialisable ; sauvegardes testées |
| R12 | Changements de règles (`targetSdk` chaque été, vérification des développeurs en 2027) | certaine / charge récurrente | relevé annuel (ticket de maintenance), compte Play couvre la vérification |
| R13 | Google ne propose pas la mise à jour de l'APK installé à la main | moyenne / faible | réinstallation, connexion au drive à refaire |
| R14 | Marque de l'enseigne mal perçue | faible / moyen | § 6.c |
| R15 | Données du formulaire « sécurité des données » incohérentes avec l'application | moyenne / moyen (refus de mise à jour) | annexe A, relecture par l'assistant à chaque version qui change les données |

### Coût

- **Argent** : 25 USD, une fois. Domaine et alias d'assistance : à vérifier chez le fournisseur du domaine (souvent gratuit).
- **Temps du propriétaire** : environ 6 à 8 heures réparties sur 5 à 6 semaines (compte, clé, console, recrutement), dont beaucoup d'attente.
- **Développement** : 22 tickets : 10 pour l'humain (STORE-001 avec l'assistant), 12 de code (S ou M, aucun L), dont quatre conditionnels ou facultatifs (STORE-011, 012, 021, 022).

### Ce qui ne se prouve qu'avec un téléphone réel ou le compte Play

Vérification d'appareil de la console ; acceptation de l'import de la clé et nombre d'empreintes affichées ; installation par-dessus un APK installé à la main ; lien d'inscription au test ; App Links vérifiés avec la clé de Google (`adb shell pm get-app-links eu.kantin.repas.drive`) ; connexion Google dans l'application installée depuis Play ; acceptation des déclarations ; réponse de Google à la demande de production ; examen de la production. **Tout le code** (tickets 006 à 012, 014, 015, 020 à 022) se fait et se teste sans téléphone ni compte Play.

### Chemin critique et délais incompressibles

| Étape | Durée | Nature |
|---|---|---|
| Validation du compte (paiement jusqu'à 5 jours ; identité non documentée) | quelques jours | **vérifié (paiement), supposé (identité)** |
| Recruter 12 testeurs, tous inscrits | à lancer dès J0 | humain |
| Conformité (008 → 009, 010, 011/012, 013) | 1 à 2 semaines de développement, **en parallèle** du compte | agents |
| **Test fermé** | **14 jours continus**, ≥ 12 testeurs | **vérifié** |
| Réponse de Google à la demande de production | non documentée (jours) | **supposé** |
| Examen de la production | jusqu'à 7 jours, plus dans des cas exceptionnels | **vérifié** |

```
J0      STORE-001 décisions · STORE-002 recruter · STORE-003 compte · STORE-004 clés
        ‖ agents : 006 AAB · 008 → 009 suppression · 010 confidentialité · 011/012 signaler · 014/015 fiche
J+5…7   compte validé ─► STORE-005 clé de signature ─► AUTH-002 (11–13) débloqué
J+7…9   STORE-016 test interne ─► JALON « famille » : la famille installe depuis Google Play
J+9…12  STORE-013 compte de relecture · STORE-017 déclarations · STORE-018 test fermé (≥ 12 inscrits)
J+23…26 14 jours écoulés ─► demande d'accès à la production ─► réponse de Google
J+30…38 STORE-019 production (examen ≤ 7 jours) ─► JALON « public »
```

Ce que le propriétaire peut **lancer dès maintenant**, sans attendre le code : STORE-003 (le compte : c'est ce qui a le plus d'attente), STORE-002 (les testeurs), STORE-004 (la clé d'envoi et la vérification des sauvegardes), STORE-001 (les cinq décisions).

## 11. Alternatives (question 10)

- **Garder l'APK à la main seulement** : le plus simple pour l'infrastructure, mais les proches gardent « sources inconnues », l'avertissement Play Protect et les mises à jour à la main ; la vérification des développeurs d'Android pourrait l'atteindre en 2027 (compte gratuit « distribution limitée », 20 appareils, sans pièce d'identité, comme parade).
- **F-Droid** : exige un dépôt libre et des constructions reproductibles ; la dépendance aux services Google Play (connexion Google) et le service tiers non libre y seraient signalés ; ne résout pas « simple pour des proches non techniques » *(supposé, non vérifié auprès de F-Droid)*.
- **Site web installable (PWA)** : ne peut pas afficher le site du drive ni agir dans sa page (cadre refusé par l'enseigne), donc pas de remplissage du panier ; reste utile pour recettes et listes.
- **Distribution par un lien de test fermé** : c'est le palier 2 du plan ; avec un compte personnel il demande déjà 12 testeurs pour aller plus loin, mais il peut aussi rester le canal permanent d'un petit groupe.

## 12. Décisions à faire trancher par le propriétaire

Une décision par message (`repas-po` : défaut raisonnable d'abord). Les défauts ci-dessous sont les recommandations.

| # | Question | Recommandation | Si autre réponse |
|---|---|---|---|
| **D1** | Veux-tu que tes proches installent d'abord depuis Google Play en test (tout de suite), puis publier pour tout le monde en France après le test de 14 jours ? | **Oui, trois paliers**, France seulement, 18 ans et plus | « Test seulement, jamais public » : on s'arrête à STORE-016 et on retire STORE-017 à STORE-019 ; « tout de suite public » : impossible avec un compte personnel |
| **D2** | Compte **personnel** (25 USD, tu recrutes 12 testeurs) avec une **adresse d'assistance dédiée** ? Ton nom légal et ton pays pourraient s'afficher sur la fiche. | **Oui, personnel** | Organisation : supprime les 12 testeurs, exige un numéro D-U-N-S (jusqu'à 30 jours) et affiche nom, adresse, e-mail et téléphone de l'organisation |
| **D3** | **Importer ta clé actuelle** dans Google Play (les téléphones gardent leur installation) et garder l'APK GitHub le temps de la transition ? | **Oui, importer** ; APK GitHub conservé deux versions puis retiré | Clé de Google : réinstallation sur chaque téléphone, empreintes à changer, APK GitHub retiré tout de suite |
| **D4** | Citer **« E.Leclerc » une seule fois** dans la description (avec « ni affilié, ni approuvé ») et **garder l'écran du drive** dans la version du store ? | **Oui, et on ne l'enlève que si Google refuse** | Ne pas nommer l'enseigne ; ou variante sans drive dès le départ (STORE-022) |
| **D5** | **Signaler une recette** (et mentionner les conditions à l'inscription) pour les recettes des autres ? | **Oui (option B)** | (A) cacher « Recettes des autres » dans la version publique ; (C) décider avant la production |

Défauts décidés par le PO, réversibles (non demandés) : titre « Repas : recettes et courses » ; catégorie « Alimentation et boissons » ; pays France ; public 18 ans et plus ; aucune publicité ; AAB en artefact du workflow ; envoi manuel au début.

## 13. Annexe A — Réponses proposées aux déclarations de la console (à relire devant le formulaire)

**Sécurité des données** (proposition ; les libellés exacts du formulaire priment) :

| Type de donnée | Collecté | Partagé | Obligatoire | Finalité |
|---|---|---|---|---|
| Adresse e-mail | oui | non | oui | fonctionnement de l'application, gestion du compte |
| Identifiants (identifiant du compte ; identifiant Google si connexion Google) | oui | non | oui | gestion du compte |
| Date de dernière connexion (une seule date, remplacée à chaque connexion, vue de l'administrateur seul ; `docs/drive/note-vie-privee.md` § 4 bis) | oui | non | oui | gestion du compte |
| Contenu produit par l'utilisateur (recettes, listes, ingrédients personnalisés) | oui | non | oui | fonctionnement de l'application |
| Activité dans l'application (produits choisis pour le drive, prix vus, magasin) | oui | non | non (fonction facultative) | fonctionnement de l'application |
| Mot de passe | (catégorie à lire dans le formulaire ; haché côté serveur) | non | oui | gestion du compte |
| Position de l'appareil, historique de navigation, identifiants d'appareil, diagnostics, contacts, photos | **non** (aucun SDK de mesure ; le magasin choisi n'est pas la position de l'appareil : à relire devant la question) | — | — | — |

Chiffrement en transit : **oui** (HTTPS seulement, trafic en clair interdit). Suppression : **oui**, dans l'application et par `https://kantin.eu/suppression-de-compte`. Prestataires (hébergeur, service d'e-mails, Google pour la connexion) : ils traitent pour notre compte, ils ne comptent pas comme « partage » d'après l'aide.

**Accès à l'application** (une seule série d'identifiants) : « Connexion par adresse e-mail et mot de passe du compte de relecture (identifiants saisis dans la console). Le compte est déjà confirmé. La fonction facultative "Envoyer au drive" demande un compte chez l'enseigne du drive, que nous ne pouvons pas fournir : le reste de l'application fonctionne sans. »

**Classification** : application de recettes et de listes ; aucun contenu violent, sexuel ni de jeu ; les utilisateurs échangent des recettes (à répondre honnêtement à la question sur le contenu partagé). **Public cible** : 18 ans et plus. **Publicités** : non. **Catégorie** : alimentation et boissons.

## 14. Sources et niveau de preuve

**Vérifié (page officielle lue le 2026-10-19 ; date affichée entre parenthèses quand elle existe)**

| Fait | Source |
|---|---|
| Compte personnel créé après le 2023-11-13 : test fermé, 12 testeurs, 14 jours continus, « Demander l'accès à la production » ; organisation non visée | [14151465](https://support.google.com/googleplay/android-developer/answer/14151465) |
| 25 USD ; personnel/organisation ; âge, pièce d'identité, vérification d'appareil avec l'application Play Console | [6112435](https://support.google.com/googleplay/android-developer/answer/6112435), [14316361](https://support.google.com/googleplay/android-developer/answer/14316361) |
| Informations demandées et affichées ; D-U-N-S jusqu'à 30 jours ; paiement jusqu'à 5 jours | [13628312](https://support.google.com/googleplay/android-developer/answer/13628312), [10840893](https://support.google.com/googleplay/android-developer/answer/10840893), [10841920](https://support.google.com/googleplay/android-developer/answer/10841920) |
| Pistes de test : limites, délais, exigences | [9845334](https://support.google.com/googleplay/android-developer/answer/9845334) |
| Applications privées : identifiant d'organisation, jamais publiques ensuite | [9874937](https://support.google.com/googleplay/android-developer/answer/9874937) |
| Play App Signing : défaut hybride à trois clés, changer le défaut avant test ouvert/production, copie de clé par PEPK, RSA ≥ 2048, empreintes, réinitialisation de la clé d'envoi | [9842756](https://support.google.com/googleplay/android-developer/answer/9842756) |
| Validité de la clé > 2033-10-22 ; clé d'envoi et de signature distinctes ; compatibilité des mises à jour par certificat (2026-03-06) | [Signer votre application](https://developer.android.com/studio/publish/app-signing) |
| Hybride quantique dans Play App Signing (2026-03-25) | [blog Google](https://blog.google/security/security-for-the-quantum-era-implementing-post-quantum-cryptography-in-android/) |
| Empreinte à déclarer = celle de la console ; *App integrity* (2026-10-16) | [Google Play services](https://developers.google.com/android/guides/client-auth) |
| AAB obligatoire depuis août 2021 (2026-06-24) ; code de version ≤ 2 100 000 000 et jamais réutilisé (2025-08-20) | [App Bundle](https://developer.android.com/guide/app-bundle), [Versionner](https://developer.android.com/studio/publish/versioning) |
| `targetSdk` 36 depuis le 2026-08-31 (2026-10-16) ; pages de 16 Ko et Java/Kotlin seuls (2026-10-16) | [target-sdk](https://developer.android.com/google/play/requirements/target-sdk), [page-sizes](https://developer.android.com/guide/practices/page-sizes) |
| Première version : finir la signature, clé figée après publication ouverte, pas de pourcentage au premier déploiement ; examen « jusqu'à 7 jours » | [9859348](https://support.google.com/googleplay/android-developer/answer/9859348), [9859751](https://support.google.com/googleplay/android-developer/answer/9859751) |
| Webview sans permission du propriétaire ; fonctionnalité limitée ; appareil et réseau (conditions d'un service, JavaScript dans une webview) ; comportement trompeur (fonctions cachées, identique pour le relecteur) ; marques ; métadonnées ; UGC | [9899034](https://support.google.com/googleplay/android-developer/answer/9899034), [9898783](https://support.google.com/googleplay/android-developer/answer/9898783), [9888379](https://support.google.com/googleplay/android-developer/answer/9888379), [9888077](https://support.google.com/googleplay/android-developer/answer/9888077), [9888072](https://support.google.com/googleplay/android-developer/answer/9888072), [9898842](https://support.google.com/googleplay/android-developer/answer/9898842), [9876937](https://support.google.com/googleplay/android-developer/answer/9876937) |
| Suppression de compte (dans l'application + lien web, geler ne suffit pas) ; politique de confidentialité dans l'application ; HTTPS | [13327111](https://support.google.com/googleplay/android-developer/answer/13327111), [10144311](https://support.google.com/googleplay/android-developer/answer/10144311) |
| Sécurité des données (collecte via webview contrôlée, prestataires, dispense du test interne) ; déclarations de contenu ; public cible ; classification | [10787469](https://support.google.com/googleplay/android-developer/answer/10787469), [9859455](https://support.google.com/googleplay/android-developer/answer/9859455), [9285070](https://support.google.com/googleplay/android-developer/answer/9285070), [9859655](https://support.google.com/googleplay/android-developer/answer/9859655) |
| Fiche et visuels (tailles, limites, règles) | [9859152](https://support.google.com/googleplay/android-developer/answer/9859152), [9866151](https://support.google.com/googleplay/android-developer/answer/9866151) |
| Mises à jour de politique : classification obligatoire, vérification des développeurs (2026-07-15) ; aucune autre nouveauté pertinente (2026-04-15) | [17134731](https://support.google.com/googleplay/android-developer/answer/17134731), [16926792](https://support.google.com/googleplay/android-developer/answer/16926792) |
| Vérification des développeurs : 4 pays au 2026-09-30, monde en 2027, France non citée, chemin « sur Play et hors Play » via Play Console, compte de distribution limitée de 20 appareils (FAQ 2026-09-30) | [FAQ](https://developer.android.com/developer-verification/guides/faq), [guides](https://developer.android.com/developer-verification/guides), [aide ADC](https://support.google.com/android-developer-console/answer/16604405), [blog 2026-03-19](https://android-developers.googleblog.com/2026/03/android-developer-verification.html) |
| API d'envoi : compte de service invité dans *Utilisateurs et autorisations* (2025-12-18) ; `edits.bundles.upload` (2025-05-21) | [prise en main](https://developers.google.com/android-publisher/getting_started), [référence](https://developers.google.com/android-publisher/api-ref/rest/v3/edits.bundles/upload) |
| Code du dépôt (§ 1) | lecture de `origin/main` des deux dépôts le 2026-10-19 |

**Lu par résultat de recherche seulement (page non relue en entier)** : la page [Usurpation d'identité](https://support.google.com/googleplay/android-developer/answer/9888374) et sa FAQ ; la page [Politique du programme développeur](https://support.google.com/googleplay/android-developer/answer/16933379) ; guides tiers sur le « 12 testeurs, 14 jours » (aucun délai de réponse officiel).

**Supposé, à prouver**

1. PEPK accepte le fichier PKCS12 du propriétaire ; sinon conversion en `.jks`.
2. Ce que la console affiche pour une clé importée (une ou trois empreintes ; hybride ou non).
3. Google Play propose la mise à jour d'une application installée à la main (même paquet, même certificat).
4. Délais : validation d'identité du compte, réponse à la demande de production, revue des pistes de test.
5. Le premier bundle d'une nouvelle application doit être envoyé à la main ; le compte de service limité aux pistes de test ne peut pas publier en production.
6. Un test interne reste utilisable sans limite de durée.
7. Déclaration « commerçant / non-commerçant » du règlement européen : non documentée par Google dans les pages lues.
8. Les exigences de fiche et de déclarations pour le test fermé sont les mêmes que pour la production (la page ne le dit pas pour les pistes de test).
9. Le comportement de l'écran du drive face à l'examen de Google (inconnue R1).
10. Les libellés exacts de la console en français.
