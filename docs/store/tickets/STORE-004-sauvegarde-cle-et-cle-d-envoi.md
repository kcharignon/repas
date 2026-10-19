# STORE-004 — Sauvegarder la clé de publication actuelle et créer la clé d'envoi

- **Dépôt** : aucun (étape humaine dans le terminal) ; une petite PR du front pour l'empreinte publique est faite dans STORE-006
- **Jalon** : M1 Clé de signature et construction
- **Taille** : S (une demi-heure)
- **Dépend de** : STORE-001 (D3)
- **Faisable sans téléphone ni compte Play** : oui (un ordinateur, Docker)
- **Bloquant pour AUTH-002 / la connexion Google** : indirectement (STORE-005 en dépend)
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 4.4 ; `android/PUBLICATION.md` du dépôt front (étapes 1 à 3 : même méthode, même image Docker) ; skill `repas-ops`.

## Contexte

Deux clés comptent. La **clé de publication** actuelle signe aujourd'hui l'APK et deviendra la clé de signature détenue par Google (STORE-005) : si elle est perdue, rien ne se met plus à jour par-dessus. Avant de l'exporter, on vérifie qu'elle est bien sauvegardée **et restaurable**. La **clé d'envoi** est nouvelle : c'est elle que la CI utilise pour signer le bundle ; si elle est perdue ou volée, Google la réinitialise (« Demander une réinitialisation de la clé d'envoi »), ce qui n'est pas possible pour la clé de signature.

## Portée

Guider, une action par message :

1. **Contrôler la sauvegarde de la clé de publication** : les deux copies existent (hors du PC) ; la commande `keytool -list -v` de l'étape 2 de `PUBLICATION.md` marche avec **une copie** (pas avec l'original) ; le mot de passe est dans le gestionnaire. L'empreinte SHA-256 affichée est celle de `assetlinks.json` (le propriétaire la compare à l'œil avec la page publique).
2. **Créer la clé d'envoi** `~/repas-cle/repas-envoi.p12` (PKCS12, RSA 4096, alias `envoi`, validité 10 000 jours, `-dname "CN=Repas envoi"`, **sans nom ni e-mail**), avec un mot de passe **différent** de l'autre, par la même commande Docker que l'étape 1 de `PUBLICATION.md` en changeant le nom du fichier et l'alias.
3. **Sauvegarder** la clé d'envoi à deux endroits qui ne sont pas ce PC, mot de passe dans le gestionnaire, et tester une copie.
4. **Lire l'empreinte SHA-256 du certificat d'envoi** (même commande `keytool -list -v`, ligne `SHA256:`) et la donner à l'assistant, qui l'écrit dans `android/upload-cert.sha256` (STORE-006). Elle est publique.
5. **Poser les quatre secrets GitHub** `ANDROID_UPLOAD_KEYSTORE_BASE64`, `ANDROID_UPLOAD_KEYSTORE_PASSWORD`, `ANDROID_UPLOAD_KEY_ALIAS`, `ANDROID_UPLOAD_KEY_PASSWORD` (mêmes gestes que l'étape 3 de `PUBLICATION.md`, dépôt `kcharignon/repas-front`), **avant** la fusion de STORE-006 (sinon la prochaine étiquette échoue).
6. Vérifier : `gh secret list -R kcharignon/repas-front` liste les huit noms, jamais les valeurs.

## Hors portée

L'export de la clé de publication vers Google (STORE-005) ; le code du workflow (STORE-006).

## Critères d'acceptation

- Étant donné la copie de sauvegarde de la clé de publication, quand `keytool -list -v` la lit, alors l'empreinte SHA-256 est celle de `assetlinks.json`.
- Étant donné le dossier `~/repas-cle`, alors il contient deux fichiers `.p12` distincts, chacun sauvegardé deux fois, avec deux mots de passe distincts dans le gestionnaire.
- Étant donné GitHub, alors huit secrets Android existent (quatre `ANDROID_KEYSTORE_*`/`ANDROID_KEY_*`, quatre `ANDROID_UPLOAD_*`).
- Aucun fichier de clé, mot de passe ni valeur de secret n'est passé dans le chat ou le dépôt.

## Tests exigés

Aucun automatique. Preuve : le propriétaire dit « fait » à chaque étape.

## Definition of Done

Clés sauvegardées et restaurées avec succès ; empreinte d'envoi transmise ; secrets posés ; roadmap cochée avec la date.

## Pièges connus

- **Ne jamais utiliser la même clé pour l'envoi et la signature** (Google le déconseille ; la CI garderait alors le secret irréversible).
- Un seul mot de passe par fichier PKCS12 (celui de la clé = celui du fichier) : la commande de `PUBLICATION.md` le gère.
- Le dossier `~/repas-cle` n'est **jamais** dans un dépôt Git.
- Ne pas lancer cette étape sur un ordinateur partagé ; ne pas coller la sortie complète de `keytool` dans le chat (elle ne contient pas de secret, mais seules les lignes `SHA1:` et `SHA256:` sont utiles).
- Rotation annuelle : refaire le test de restauration (entretien annuel de la roadmap).
