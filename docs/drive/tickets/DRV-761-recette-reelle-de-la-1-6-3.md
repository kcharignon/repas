# DRV-761 — Recette réelle de 1.6.3 : la carte ne doit plus couvrir un champ, textes de Leclerc et d'Auchan

- **Dépôt** : front (`drive-agent/`, `android/`)
- **Jalon** : M7 Plusieurs enseignes (recette réelle du 2026-11-02, application 1.6.3)
- **Taille** : M
- **Dépend de** : [DRV-760](DRV-760-recette-reelle-de-la-1-6-2.md)
- **Statut** : en cours (agent de code sur la branche `fix/drv-761-carte-champs`, relecture indépendante à faire avant la fusion, puis `android-v1.6.4`)
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
