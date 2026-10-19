# STORE-009 — « Supprimer mon compte » dans « Moi » et page publique `/suppression-de-compte`

- **Dépôt** : front (`src/features/account/`, `src/views/`, `src/router/`, `src/i18n/fr.ts`, tests) ; l'assistant met à jour `docs/drive/note-vie-privee.md` du back après la fusion
- **Jalon** : M2 Conformité aux règles de Google Play
- **Taille** : M
- **Dépend de** : STORE-008 (contrat de l'API figé par `make api-update ref=<sha>`), STORE-001 (D2 : adresse d'assistance)
- **Faisable sans téléphone ni compte Play** : oui
- **Bloquant pour AUTH-002 / la connexion Google** : non (mais **STORE-017 en dépend** : Google exige le lien web)
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) § 6.d ; skills `repas-front-dev` et `repas-po` ; `docs/drive/principes-produit.md` (tutoiement, erreurs en trois phrases) ; `src/views/AccountView.vue`, `src/features/account/`.

## Contexte

Google demande (1) un chemin **dans l'application** : « Moi » → « Supprimer mon compte », et (2) un **lien web** public où l'on peut demander la suppression sans réinstaller l'application, qui nomme l'application, présente le chemin, dit ce qui est supprimé ou conservé et pour combien de temps. Une adresse d'assistance est un moyen de demande accepté pour qui ne peut plus se connecter.

## Portée

1. **Écran dans « Moi »** : un lien discret « Supprimer mon compte » en bas de la page, qui ouvre un écran : titre « Supprimer ton compte » ; texte « Cela efface ton compte, tes recettes, tes listes et tes réglages du drive. Tu ne pourras pas revenir en arrière. » ; un champ « Ton mot de passe » (compte avec mot de passe) **ou** « Tape ton adresse e-mail pour confirmer » (compte sans mot de passe : `hasPassword` exposé par l'API de lecture du compte) ; un bouton destructif « Supprimer mon compte » et un lien « Annuler ». **Touches** : Moi → Supprimer mon compte → (saisie) → Supprimer = 4 touches.
2. **Après `204`** : appeler `POST /api/auth/logout` (au mieux), vider l'état de session, aller à l'écran de connexion avec le message « Ton compte est supprimé. ».
3. **Erreurs en trois phrases** (ce qui s'est passé, ce qui est sauf, l'action), sans code : confirmation fausse → « Le mot de passe n'est pas le bon. Ton compte n'a pas été supprimé. Réessaie. » (variante adresse) ; `429` → « Trop d'essais. Ton compte n'a pas été supprimé. Attends un moment, puis réessaie. » ; compte protégé ou dernier administrateur → « Ce compte ne peut pas être supprimé ici. Rien n'a été modifié. Écris à l'assistance. » ; réseau → texte existant.
4. **Page publique `/suppression-de-compte`** (`meta: { public: true }`, ouverte aussi aux connectés, lisible **sans JavaScript lourd** ni session) : titre « Supprimer ton compte Repas » ; le nom de l'application **comme sur la fiche** ; le chemin en trois lignes (« 1. Ouvre Repas et connecte-toi. 2. Touche Moi, puis Supprimer mon compte. 3. Confirme. ») avec un bouton « Supprimer mon compte » qui mène à l'écran (connexion d'abord) ; « Ce qui est supprimé » (compte, recettes, listes de courses, ingrédients personnalisés, réglages et produits du drive, connexion Google) ; « Ce qui reste » (les descriptions de produits communes, sans lien avec toi ; les copies de tes recettes faites par d'autres, qui deviennent leurs recettes ; les copies de sauvegarde, jusqu'à deux semaines) ; « Tu ne peux plus te connecter ? » avec l'adresse d'assistance (D2) et l'engagement « nous supprimons ton compte sous un mois » ; lien vers `/confidentialite`.
5. Mettre à jour la phrase « Comment tout effacer » de la page des conditions du drive (qui renvoie aujourd'hui à « la personne qui administre ») ; test de glossaire et de voix étendu.

## Hors portée

Le back (STORE-008) ; la page de confidentialité (STORE-010) ; le formulaire de demande par e-mail côté serveur ; un délai de grâce.

## Critères d'acceptation

- Étant donné un utilisateur connecté, quand il touche « Supprimer mon compte » puis saisit son mot de passe, alors son compte est supprimé, il revient à l'écran de connexion avec « Ton compte est supprimé. », et il ne peut plus se connecter.
- Étant donné un visiteur non connecté, quand il ouvre `/suppression-de-compte`, alors la page s'affiche sans redirection, nomme l'application, montre le chemin, ce qui est supprimé, ce qui reste, et l'adresse d'assistance.
- Étant donné la page à 360 px, alors aucun défilement horizontal et le bouton principal est visible sans défiler.
- Étant donné un mauvais mot de passe, alors le texte en trois phrases s'affiche et rien n'est supprimé.
- Étant donné les écrans du parcours, alors le test de garde de DRV-628 ne trouve aucun mot d'avertissement hors de la page des conditions et de cette page.

## Tests exigés

Vitest : route publique ; textes (longueur des phrases, glossaire, tutoiement) ; états de l'écran (avec / sans mot de passe, erreurs) ; Playwright : parcours complet avec le faux back, navigation sans session ; **captures lues** (360 px, clair et sombre) ; `make check`.

## Definition of Done

Celle du dépôt front ; `CLAUDE.md` du front (une ligne : la route publique) ; description de PR avec touches avant / après et le texte exact de la page ; l'assistant met à jour `note-vie-privee.md` après la fusion.

## Pièges connus

- L'URL `/suppression-de-compte` est **donnée à Google** : elle doit rester stable (pas de redirection).
- Le texte sur la sauvegarde (« environ deux semaines ») vient de `deploy/backup.sh` ; à mettre à jour si la rétention change.
- Ne pas afficher d'adresse e-mail réelle dans les tests ni les fixtures ; l'adresse d'assistance vient d'une configuration de texte (`fr.ts`), saisie par le propriétaire.
- La page publique n'a **pas** d'avertissement qui fasse peur : elle informe (règle 10).
- Ne pas exposer à un inconnu l'existence d'un compte (aucune recherche par adresse sur cette page).
