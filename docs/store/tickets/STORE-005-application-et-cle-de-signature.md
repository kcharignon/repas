# STORE-005 — Créer l'application dans la console et importer la clé de signature (PEPK)

- **Dépôt** : aucun (étape humaine, guidée par l'assistant) ; l'assistant adapte la commande PEPK
- **Jalon** : M1 Clé de signature et construction
- **Taille** : M (une heure, dont une commande dans le terminal)
- **Dépend de** : STORE-003 (compte validé), STORE-004 (sauvegardes, clé d'envoi)
- **Faisable sans téléphone ni compte Play** : **non** (compte Play) ; le téléphone n'est pas nécessaire
- **Bloquant pour AUTH-002 / la connexion Google** : **OUI pour les étapes 11 à 13 d'AUTH-002** (client OAuth Android : paquet + empreinte SHA-1 de la clé qui signe l'application) ; AUTH-013 partie C en dépend aussi
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 4 (toute la section) et § 9 phase B ; [AUTH-002](../../auth/tickets/AUTH-002-console-google-cloud.md) étapes 11 à 13 ; skill `repas-ops`.

## Contexte

Par défaut, une nouvelle application reçoit une clé générée par Google en signature « hybride » à **trois clés**. Cela changerait l'empreinte que `assetlinks.json` et le client OAuth Android attendent, et empêcherait la mise à jour par-dessus les APK installés à la main. Google permet de **changer ce défaut avant toute version en test ouvert ou en production** et de fournir **une copie de sa propre clé** (outil PEPK, qui la chiffre). Trois points ne sont pas documentés et se lèvent ici : PEPK accepte-t-il un fichier PKCS12 ; la console affiche-t-elle une ou trois empreintes pour une clé importée ; ce que fera la mise à jour par-dessus (prouvé à STORE-016).

## Portée

Guider, une action par message, selon B.1 à B.12 de l’analyse (§ 9, phase B). En résumé : créer l'application « Repas » (français, application, gratuite) ; ouvrir la signature d'application Play ; **changer la clé avant tout envoi de bundle** ; « fournir une copie de la clé de signature » ; télécharger PEPK dans `~/repas-cle` ; copier la commande affichée dans le chat à l'assistant, qui l'**adapte** (image Docker du JDK, fichier `repas-publication.p12`, alias `repas`) sans jamais demander le fichier ni le mot de passe ; l'utilisateur la lance et tape le mot de passe ; téléverser le `.zip` chiffré ; lire les empreintes.

À la fin, **consigner dans `publication-play-store.md` § 4.2** (par l'assistant, sans valeur secrète) : PEPK a-t-il accepté le PKCS12 (sinon : `keytool -importkeystore -destkeystore … -deststoretype JKS`, même clé) ; **nombre d'empreintes** affichées (une ou trois) ; l'empreinte SHA-256 est-elle **identique** à celle de `assetlinks.json` ? Puis :

- **Identique, une seule** → cas A : STORE-007 se réduit à la documentation ; AUTH-002 étapes 11 à 13 peuvent se faire avec le SHA-1 affiché (qui est celui de la clé actuelle).
- **Différente, ou trois** → cas B : STORE-007 devient obligatoire avant tout téléphone ; AUTH-002 utilise les SHA-1 de la console ; prévenir le propriétaire que les APK installés à la main ne se mettront pas à jour par Play (réinstallation).

## Hors portée

L'envoi du premier bundle (STORE-016) ; le client OAuth Android lui-même (AUTH-002) ; la modification de `assetlinks.json` (STORE-007).

## Critères d'acceptation

- Étant donné la page « signature d'application Play », quand le propriétaire l'ouvre, alors « Certificat de signature de l'application » affiche des empreintes et la clé n'est **pas** celle générée par défaut (le propriétaire décrit l'écran ; il ne copie aucun fichier).
- Étant donné l'empreinte SHA-256 affichée, quand l'assistant la compare à `assetlinks.json`, alors le résultat (identique / différente) et le nombre d'empreintes sont notés dans l'analyse et la roadmap.
- Étant donné le dossier `~/repas-cle`, alors le `.zip` chiffré peut être supprimé (il n'est utile qu'à l'envoi) et aucune clé n'a quitté l'ordinateur autrement que chiffrée.
- Aucun envoi de bundle n'a été fait avant le changement de clé.

## Tests exigés

Aucun automatique. Preuve : comparaison des empreintes, décrite par le propriétaire.

## Definition of Done

Clé choisie, empreintes relevées et comparées, cas A ou B noté, STORE-007 et AUTH-002 informés ; roadmap cochée avec la date.

## Pièges connus

- **Faire cette étape avant tout envoi de bundle** : après une version en test ouvert ou en production, la clé est figée (jusque-là le choix est modifiable).
- Les menus s'appellent en anglais *Protected with Play > Play Store distribution > Go to Play app signing* (traduits dans la console) : lire à l'écran.
- La commande PEPK contient la **clé publique de chiffrement de Google** : elle n'est pas secrète, mais le fichier `.p12` et son mot de passe le sont ; le mot de passe est tapé dans le terminal, jamais écrit.
- Si PEPK refuse le PKCS12 : ne pas régénérer de clé ; convertir en `.jks` (même clé) et refaire.
- Si la console affiche **trois** empreintes, ne pas en déduire que tout est perdu : dire exactement ce qui est affiché et appliquer le cas B, ou essayer l'installation sur un téléphone Android 17 à STORE-016.
- L'empreinte SHA-1 n'est pas secrète ; ne jamais enregistrer l'empreinte de la **clé de débogage** (publique) dans la console Google Cloud (ADR 0024 décision 14).
