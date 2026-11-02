# DRV-761 — Recette réelle de 1.6.3 : la carte ne doit plus couvrir un champ, textes de Leclerc et d'Auchan

- **Dépôt** : front (`drive-agent/`, `android/`)
- **Jalon** : M7 Plusieurs enseignes (recette réelle du 2026-11-02, application 1.6.3)
- **Taille** : M
- **Dépend de** : [DRV-760](DRV-760-recette-reelle-de-la-1-6-2.md)
- **Statut** : fait (front PR #315 fusionnée le 2026-11-02, agent 0.62.7, relue par un agent indépendant puis son delta par l'assistant principal ; application `android-v1.6.4` publiée, `APP_LATEST_VERSION=1.6.4` ; recette réelle de 1.6.4 faite, voir « Recette de 1.6.4 » plus bas)
- **Lire d'abord** : [DRV-760](DRV-760-recette-reelle-de-la-1-6-2.md), `drive-agent/src/ui/fit.ts` et `ui.ts`, `drive-agent/src/leclerc/guide-look.ts`, `drive-agent/src/auchan/`, `android/` (`StoreBanner.kt`, `DrivePass.kt`)

## Contexte

Recette réelle de 1.6.3 sur le téléphone de test (compte de démo), Leclerc puis Auchan, lue comme une personne novice, avec le propriétaire qui regarde l'écran (les captures du drive sont noires : écran protégé ; le texte de la carte n'apparaît pas toujours dans l'arbre d'accessibilité). **Les deux parcours vont jusqu'au bout** (« Le panier est rempli »).

## Ce que la 1.6.3 a corrigé (mesuré)

- **Mur de cookies de Leclerc** : la carte est visible, en barre réduite « Étape 1 sur 2 · Réponds aux cookies » (le propriétaire la voit). Le diagnostic « Carte » disait vrai : dite à l'écran oui, toucher au centre sur l'agent, conteneur dernier du document oui, un déplacement. **Le mur n'est pas une couche supérieure** : c'est un panneau ordinaire du site (`div#didomi-popup`, z-index 2147483641, « boîtes modales 0 ») ; l'hypothèse « mur en couche supérieure » de la relecture de la PR #308 est fausse.
- **Auchan** : barre « Étape 1 sur 2 · Réponds aux cookies » devant le mur ; **le panneau « Choisir un drive ou la livraison » ne la fait plus disparaître** (« Étape 1 sur 2 · Écris ton code postal »).

## Défauts relevés (à corriger dans cette PR)

1. **Leclerc, page de connexion, étape du mot de passe** : la carte dépliée s'affiche en haut ; son bouton « Arrêter pour l'instant » se pose **sur le champ « Mot de passe »**. Un toucher sur le champ a **arrêté l'envoi** (« Tu as arrêté l'envoi »). « Réduire » règle le problème. Règle voulue : la carte ne couvre jamais un champ de saisie ni le bouton de validation d'un formulaire, en haut comme en bas ; sinon elle se réduit d'office.
2. **Leclerc, page « session expirée »** (« Vous avez été déconnecté(e) automatiquement… », bouton « Retour au magasin ») : un texte périmé « Touche « Commencer mes courses ». » restait affiché. Le guide doit nommer le bouton qui existe.
3. **Auchan, accueil** : la carte dit « Touche « Choisir vos courses », en haut de la page. » alors que le bouton s'appelle « Choisir mon mode de livraison » (nom accessible). La carte cite le nom accessible du bouton.
4. **Auchan, page « Code de vérification »** (« Saisissez le code à 6 chiffres envoyé à … », « Renvoyer un code ») : le bandeau natif garde « Connecte-toi dans la page. Tu tapes toi-même ton e-mail et ton mot de passe. » au lieu de `StoreBanner.LOGIN_CODE` (« Tape le code reçu par e-mail, dans la page. »).

Constats sans correction dans cette PR : le bouton ⋮ natif monte (il passe d'environ y 2148 à 1365 px) quand la carte est dépliée ; la fenêtre « Enregistrer le mot de passe dans … » d'un gestionnaire de mots de passe apparaît à la connexion d'Auchan sur le téléphone de test (propre à ce téléphone). Le texte de la carte absent de l'arbre d'accessibilité est à surveiller (un lecteur d'écran ne le lirait pas) : à traiter si un proche s'en sert.

## Non vérifié

- Tout ce qui se joue sur le vrai site reste à rejouer sur le téléphone de test avec la version qui suit (`android-v1.6.4`) : le parcours Leclerc avec le clavier ouvert sur la page du mot de passe, la page du code e-mail de Leclerc (non rencontrée : aucun code demandé cette fois), la page du code d'Auchan.
- **Le vrai Leclerc ne se teste pas dans l'émulateur** : essayé le 2026-11-02 (Android 14, WebView 113), il répond par un défi anti-robot (« Verification Required »), jamais passé par l'assistant. À ne pas recommencer : le trafic part de l'adresse de la maison.

## Livraison

Front (agent et application) : PR, relecture indépendante, fusion si la CI est verte, étiquette `android-v1.6.4`, approbation et `APP_LATEST_VERSION` par le propriétaire, recette réelle de 1.6.4. Voir aussi [DRV-762](DRV-762-apres-l-envoi-la-liste-disparait.md) (site).

## Recette de 1.6.4 (téléphone de test, 2026-11-02)

Rejouée avec le propriétaire (hCaptcha de Leclerc et code e-mail d'Auchan par lui), après « Se déconnecter » de chaque enseigne.

- **Leclerc, page du mot de passe : corrigé.** La carte déployée occupe le haut de l'écran (bouton « Arrêter pour l'instant » en bas de la carte) et le champ « Mot de passe » commence plus bas, sans chevauchement ; le texte dit « Écris ton mot de passe, puis coche « Je suis un humain » » (la page est bien reconnue). Toucher le champ n'arrête plus l'envoi ; la touche Entrée valide (le bouton « Se connecter » est hors écran quand le clavier est ouvert). « Le panier est rempli ».
- **Auchan, nom du bouton : corrigé.** La carte dit « Touche « Choisir mon mode de livraison », en haut de la page. ».
- **Auchan, page du code : texte vrai, pas précis.** Le bandeau natif dit « Connecte-toi dans la page. Tu tapes toi-même tes identifiants, et le code reçu par e-mail s'il est demandé. ». Le bandeau « Tape le code reçu par e-mail, dans la page. » ne se déclenche **pas** sur cette page : le titre de la page est le même à toutes les étapes. La seule méthode sûre serait de lire le contenu de la page de connexion, ce que l'architecture interdit (aucun agent sur le compte d'Auchan).
- **Non vus** : la page Leclerc « session expirée » (titre et chemin réels inconnus : `SESSION_EXPIRED` n'est pas prouvé sur le vrai site), la page du code e-mail de Leclerc (aucun code demandé cette fois), le vrai clavier avec Auchan.
- **Restes connus, non corrigés** : le bouton ⋮ natif monte avec la carte dépliée ; le texte de la carte n'apparaît pas toujours dans l'arbre d'accessibilité (un lecteur d'écran ne le lirait pas) ; `aria-labelledby` n'est pas lu par `accessibleName` ; la fenêtre « Enregistrer le mot de passe » d'un gestionnaire de mots de passe sur le téléphone de test.
