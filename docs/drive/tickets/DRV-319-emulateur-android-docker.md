# DRV-319 — Émulateur Android en Docker pour tester l'application sans Leclerc

- **Dépôt** : front (`kcharignon/repas-front`), dossier `android/`
- **Jalon** : M3b Application Android
- **Taille** : L
- **Dépend de** : DRV-313 (fait) pour la première partie (émulateur et test de fumée) ; DRV-317 et DRV-309 (faux site Leclerc) pour les scénarios complets
- **Statut** : fait (livraison 1 : PR [repas-front#90](https://github.com/kcharignon/repas-front/pull/90) ; livraison 2 : PR [repas-front#119](https://github.com/kcharignon/repas-front/pull/119), 2026-10-18)
- **Lire d'abord** : [`../pivot-application-android.md`](../pivot-application-android.md), `android/README.md`, DRV-309, DRV-313, DRV-317, `CLAUDE.md` du front.

## Contexte

L'utilisateur propose de mettre en place un émulateur pour que les agents testent l'application Android sans dépendre de son téléphone. Faisabilité constatée le 2026-10-18 : le processeur expose `vmx`, le noyau WSL a les modules `kvm` et `kvm-intel`, mais `/dev/kvm` n'existe pas tant que `sudo modprobe kvm_intel` n'a pas été exécuté (étape humaine).

## Portée

1. **Image Docker d'émulateur** (cible `make android-emulator`) : SDK Android, image système `x86_64` (Google APIs, niveau 34 ou 35, avec WebView), AVD sans fenêtre (`-no-window -no-audio -gpu swiftshader_indirect`), accélération KVM (`--device /dev/kvm`), démarrage vérifié (`adb wait-for-device`, `sys.boot_completed`), arrêt propre.
2. **Cible `make android-e2e`** : installe l'APK de débogage, lance des scénarios automatisés (UI Automator ou Espresso/Compose-less, au choix, dans `android/`) : lancement de la coquille Repas (DRV-317) sur un front de développement local (hôte de l'émulateur `10.0.2.2`), connexion avec les données de démonstration du back de développement, navigation, pont `repasNative`, ouverture de `DriveActivity`.
3. **Faux site Leclerc** : la variante de débogage fait confiance à une autorité de certification de test (`network_security_config` propre au débogage) et résout un hôte `*.leclercdrive.fr` factice vers le faux site de DRV-309 (image AOSP ou `google_apis` userdebug : `adb root`, entrée `hosts`), afin de jouer un passage complet (plan → récapitulatif → recherche → choix → ajout → résultat → retour dans Repas) **sans jamais contacter le vrai Leclerc**.
4. Rapport de test en texte (réussites, échecs, captures d'écran des écrans clés lues par l'agent) ; temps d'exécution < 15 minutes ; CI optionnelle (`workflow_dispatch`, KVM absent sur les exécuteurs hébergés).
5. Documentation en français : comment activer KVM dans WSL (`sudo modprobe kvm_intel`, droits de `/dev/kvm`, démarrage automatique via `/etc/wsl.conf`), comment lancer l'émulateur, dépannage.

## Hors portée

Tout test contre le vrai Leclerc (connexion et anti-robot ne s'automatisent pas : c'est le rôle du test automatique DRV-318 sur le téléphone de l'utilisateur).

## Critères d'acceptation

- Étant donné `/dev/kvm` accessible, quand on lance `make android-e2e`, alors l'émulateur démarre, l'APK s'installe et les scénarios passent ou échouent avec un rapport lisible.
- Le scénario du faux site déroule un passage complet sans aucun accès réseau vers Leclerc (vérifié par la résolution d'hôtes de l'émulateur).

## Definition of Done

Celle du dépôt front ; PR concise ; fusion si CI verte.

## Découpage conseillé (2026-10-18)

1. **Première livraison (peut démarrer maintenant)** : image d'émulateur, `make android-emulator`, installation de l'APK de débogage actuel, **test de fumée** (l'application démarre sans plantage, la WebView affiche une page locale de test, le script d'injection et le pont répondent sur cette page, le menu de débogage s'ouvre), rapport avec captures d'écran lues.
2. **Deuxième livraison (après DRV-317 et DRV-309)** : scénarios complets (coquille Repas sur le front de développement, pont `repasNative`, passage contre le faux site Leclerc avec autorité de certification de test).

## Installation permanente (demande de l'utilisateur, 2026-10-18)

L'émulateur doit être installé **de façon durable**, pas à refaire à chaque session :
- **KVM au démarrage de WSL** : ajouter à `/etc/wsl.conf` (étape humaine, une seule fois, puis `wsl --shutdown` côté Windows) :
  ```
  [boot]
  command = modprobe kvm_intel && chmod 666 /dev/kvm
  ```
  Ne pas écraser un `[boot]` existant ; documenter dans `android/README.md`.
- **SDK, image système et AVD dans un volume Docker nommé** (ou un dossier hors du dépôt, par exemple `~/.cache/repas-android-sdk`) : téléchargés une fois, réutilisés ensuite ; `.dockerignore` et `.gitignore` à jour.
- **Cibles** : `make android-emulator-up` (démarre et attend la fin du démarrage), `make android-emulator-down`, `make android-emulator-status` ; `make android-e2e` démarre l'émulateur s'il est arrêté.
- L'agent qui implémente démarre lui-même la pile de développement (`make up` dans les deux dépôts) quand un scénario en a besoin ; l'utilisateur n'a rien à lancer.

## Réalisation de la livraison 1 (2026-10-18, PR repas-front#90)

`android/emulator/` : image Docker (outils Android en ligne de commande, `dnsmasq`, Python), SDK, émulateur, image système Android 14 Google APIs x86_64 (WebView) et AVD dans le volume nommé `repas-front_android-emulator-sdk` ; émulateur sans fenêtre avec `/dev/kvm`. Cibles `make android-emulator-up|down|status` et `make android-smoke` (26 contrôles OK, 63 s une fois l'émulateur démarré). Premier démarrage à vide 2 min 45 (environ 2 Go téléchargés, 6 Go sur disque), démarrages suivants 26 à 34 s. **Jamais Leclerc** : le DNS de l'émulateur envoie `*.leclercdrive.fr` vers un faux site HTTPS local (`10.0.2.2`) et ne résout rien d'autre ; le test s'arrête si le nom ne pointe pas vers `10.0.2.2` ; certificat jetable dans le volume, accepté par la WebView via `--ignore-certificate-errors-spki-list` écrit dans l'émulateur seulement (l'application n'est pas modifiée ; image Google APIs « userdebug »). Pas de `network_security_config` propre au débogage : à reconsidérer. Reste (livraison 2, après DRV-317 et DRV-309) : `make android-e2e` (démarre l'émulateur s'il est arrêté), scénarios de la coquille Repas sur le front de développement, pont `repasNative`, `DriveActivity`, faux site Leclerc complet. Étape humaine facultative : `[boot] command = modprobe kvm_intel && chmod 666 /dev/kvm` dans `/etc/wsl.conf`, puis `wsl --shutdown`.

## Réalisation livraison 2 (2026-10-18, PR repas-front#119, issue front #88 fermée)

- `make android-e2e` : sept scénarios dans l'émulateur, avec l'agent réel dans la vraie WebView ; le faux site Leclerc de DRV-309 est servi en HTTPS sous `courses-test.leclercdrive.fr`, le faux Repas joue le front. Durée mesurée : 265 s de scénarios, 4 min 42 à 5 min 04 de bout en bout.
- Scénarios : lecture du magasin (`drive.setup`) ; passage complet (récapitulatif, phase A farine ×3 et sucre ×1, phase B poivre choisi ×2, retour avec le détail) ; « Annuler » au récapitulatif ; abandon en plein passage (`aborted` / `CANCELLED`) ; application tuée puis reprise sans doublon ; blocage simulé (403 : trois requêtes puis plus rien, `partial` / `BLOCKED`) ; site changé (`error` / `CONTRACT_CHANGED`).
- Le faux site juge à chaque passage complet le panier, la cadence (au moins 800 ms, aucune requête en parallèle) et la forme exacte des requêtes.
- Dispositif : faux Leclerc (Node) dans le conteneur de l'émulateur derrière un relais HTTPS limité à ce nom ; le faux certificat n'est accepté que par la WebView de l'émulateur de test, réglée de l'extérieur, jamais par l'application (vérifié par `android/__tests__/emulator-e2e.spec.ts`).
- Écarts : pas de job CI émulateur (pas de KVM en CI, SDK de 2 Go : cible manuelle, option prévue par le ticket) ; pas de `network_security_config` propre au débogage ; la connexion avec les données de démonstration du back n'est pas rejouée (le faux Repas remplace le front).
- Hypothèse à vérifier sur le vrai site (recette, partie 6) : `drive.start` ouvre `https://<hôte du magasin>/` ; le faux site redirige cette racine vers l'accueil du magasin.
- Raccords liés (PR repas-front#118) : `CLAUDE.md` du front aligné sur le back (Claude fusionne), message `CONTRACT_CHANGED` « application Repas » (agent 0.8.1), phrase « premier écrivain » dans la note de vie privée complète du front (vouvoiement).
