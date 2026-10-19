# AUTH-010 — Conditions d'utilisation publiques et section « Connexion avec Google »

- **Dépôt** : front (`src/features/drive/DriveTermsView.vue`, `src/router/`, `src/i18n/fr.ts`) ; l'assistant met à jour `docs/drive/note-vie-privee.md` et `recette.md` du back après la fusion
- **Jalon** : M2 Front web
- **Taille** : S
- **Dépend de** : AUTH-001 (D4)
- **Faisable sans téléphone** : oui
- **Statut** : à faire
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) § 5.4 ; `docs/drive/tickets/DRV-628-interface-sans-avertissement.md` (règle : les risques et les engagements vivent dans **une seule** page de conditions, jamais dans le parcours) ; `docs/drive/note-vie-privee.md` ; skill `repas-po`.

## Contexte

Depuis DRV-628, la seule page de conditions est « Conditions d'utilisation du drive » (`/drive/conditions`), **derrière la connexion**. Un visiteur qui hésite devant le bouton Google ne peut pas la lire. La vérification de marque de Google (si D4 = oui) exige en outre une page de confidentialité publique, sur le même domaine que la page d'accueil. La règle 10 du produit tient : aucun avertissement sur l'écran de connexion, seulement un lien discret.

## Portée

1. La page devient **publique** et s'appelle « Conditions d'utilisation » (route `/conditions`, `meta: { public: true }` ; `/drive/conditions` y redirige). Elle contient deux parties : « Connexion avec Google » (nouvelle) et « Envoi de la liste au drive » (le texte actuel, inchangé).
2. Section « Connexion avec Google » (en français simple, phrases de 15 mots au plus, au tutoiement : DRV-627 est fait), qui dit : ce que Google voit (que ce compte se connecte à Repas, quand ; la page de connexion charge un script de Google) ; ce que Repas reçoit et **ignore** (nom, photo) ; ce que Repas retient (l'identifiant que Google donne et l'adresse e-mail, déjà retenue pour tout compte) ; ce que Repas ne demande pas (aucun accès à Gmail, Drive, contacts) ; comment tout effacer (suppression du compte) et comment retirer l'accès chez Google ; que sans Google, le mot de passe reste possible.
3. Un lien discret « Conditions d'utilisation » au pied des écrans de connexion et d'inscription, et le lien existant de « Moi » et de l'aide, qui mènent à `/conditions`.
4. Aucune case à cocher, aucune fenêtre, aucun avertissement ailleurs.
5. Le test de garde de DRV-628 (mots d'avertissement hors de la page des conditions) est étendu à la nouvelle route publique.

## Hors portée

La demande de vérification de marque (humain, AUTH-002 si D4 = oui) ; l'acceptation obligatoire des conditions ; les textes juridiques de Leclerc.

## Critères d'acceptation

- Étant donné un visiteur non connecté, quand il ouvre `/conditions`, alors la page s'affiche sans redirection vers la connexion.
- Étant donné l'écran de connexion, alors un lien « Conditions d'utilisation » est visible au pied, discret, et mène à la page.
- Étant donné la page, alors les mots « jeton », « OAuth », « identité » n'y figurent pas ; elle dit ce que Google voit, ce que Repas retient et comment effacer.
- Étant donné `/drive/conditions` (ancien lien, signet), alors la redirection mène à `/conditions` avec le fragment conservé.
- Étant donné les écrans du parcours (hors page des conditions), alors le test de garde ne trouve aucun mot d'avertissement.

## Tests exigés

Vitest : route publique et redirection ; textes (longueur des phrases, glossaire) ; liens ; le test de garde de DRV-628 étendu. Playwright : navigation sans session ; capture à 360 px lue.

## Definition of Done

Celle du dépôt front (`make check`, captures lues) ; `CLAUDE.md` du front : la ligne de la page des conditions ; l'assistant met à jour `note-vie-privee.md` (§ « Connexion avec Google ») dans le dépôt back.

## Pièges connus

- Le test de garde de DRV-628 interdit « risque », « jamais », « ne voit »… **hors de la page des conditions** : la nouvelle route doit être dans sa liste d'exceptions, sinon il échoue.
- `guestOnly` et `public` n'ont pas le même sens dans `src/router/index.ts` : la page doit rester ouverte aux utilisateurs connectés aussi.
- Ne pas alourdir l'écran de connexion : un seul lien, en petit.
- Si D4 = oui, l'adresse `https://kantin.eu/conditions` est celle à donner à Google ; elle doit rester stable (la redirection ne suffit pas à Google pour une page de confidentialité).
- La page parle de Google à des personnes non techniques : « compte Google », pas « fournisseur d'identité ».
