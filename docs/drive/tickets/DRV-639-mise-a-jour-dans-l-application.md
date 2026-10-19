# DRV-639 — L'application se met à jour elle-même (variante hors Google Play)

- **Dépôt** : front (`android/`, Kotlin) + web
- **Jalon** : M6 Simplicité d'usage
- **Taille** : L
- **Dépend de** : DRV-638, [AUTH-015](../../auth/tickets/AUTH-015-tronc-commun-kotlin.md) (tronc commun Kotlin : le code d'installation est de la plateforme, il reste dans `app`)
- **Statut** : à faire (idée de l'utilisateur du 2026-10-19 : « aller lire les fichiers téléchargés pour voir le dernier, un bouton qui supprime le dernier APK avant ou après le téléchargement, car là c'est complexe »). Effort de réalisation : `xhigh` (installation de paquets, intégrité, signature).
- **Lire d'abord** : `android/PUBLICATION.md`, `android/ARCHITECTURE.md`, [ADR 0025](../../adr/0025-publication-sur-google-play.md), skills `repas-android-dev`, `repas-po`.

## Contexte

Aujourd'hui l'utilisateur ouvre GitHub, télécharge `repas-drive.apk` (Chrome ajoute « (1) », « (2) »… aux téléchargements suivants), cherche le bon fichier et l'installe. Le web ne peut pas voir le dossier des téléchargements ; seule l'application voit ce qu'elle a elle-même téléchargé (stockage cloisonné d'Android).

## Portée

1. L'application lit `version.json` de la dernière release (adresse fixe, HTTPS), compare à sa propre version et propose « Mettre à jour » seulement si une version plus récente existe.
2. Elle télécharge l'APK elle-même dans son espace privé, **vérifie l'empreinte SHA-256** de `version.json` avant de lancer l'installation, puis ouvre l'installateur d'Android (fournisseur de fichiers, permission « installer des applications inconnues » demandée une seule fois, avec l'explication courte).
3. Elle **supprime l'APK téléchargé** après l'installation (ou au prochain démarrage si l'installation a été annulée) : il ne reste jamais deux fichiers.
4. Le pont natif expose l'état (`aJour`, `miseAJourDisponible`, `telechargement`, `pretAInstaller`) à la page `/drive/aide` (DRV-638), qui n'a qu'un bouton à plus.

## Hors portée

Mise à jour silencieuse, Google Play (cette fonction n'existe PAS dans la variante Play : Google interdit à une application distribuée par Play de s'installer ailleurs ; elle disparaît avec STORE-xxx, le build Play ne contient ni la permission ni ce code), iPhone.

## Critères d'acceptation

- Étant donné une version plus récente publiée, l'application la propose, télécharge, vérifie l'empreinte, installe ; l'ancien fichier n'existe plus.
- Étant donné une empreinte qui ne correspond pas, rien n'est installé et le fichier est supprimé.
- Étant donné aucune connexion, un message d'une phrase et rien d'autre.

## Tests exigés

Tests Kotlin (comparaison de versions, empreinte, suppression, état), contrôle d'architecture du tronc commun, e2e sur émulateur, revue de sécurité (aucune adresse lue ailleurs que celle écrite en dur).

## Definition of Done

Celle du dépôt front ; `android/PUBLICATION.md` décrit la variante sans cette fonction pour Google Play.

## Pièges connus

- `REQUEST_INSTALL_PACKAGES` est une permission sensible : à ne déclarer que dans la variante hors Play.
- `version.json` et l'APK viennent du même dépôt : l'empreinte ne protège pas d'un dépôt compromis, seule la signature d'Android (même clé) le fait ; ne jamais assouplir la vérification de signature.
- Option bon marché à étudier avant tout : nommer l'asset de release avec la version (`repas-drive-0.1.10.apk`) pour que le « dernier » téléchargement soit évident.
