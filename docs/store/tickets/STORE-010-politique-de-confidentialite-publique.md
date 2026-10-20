# STORE-010 — Page publique de confidentialité `/confidentialite` et lien dans l'application

- **Dépôt** : front (`src/views/`, `src/router/`, `src/i18n/fr.ts`, tests) ; l'assistant met à jour `docs/drive/note-vie-privee.md` du back après la fusion
- **Jalon** : M2 Conformité aux règles de Google Play
- **Taille** : S
- **Dépend de** : STORE-001 (D2 : nom de l'éditeur et adresse d'assistance) ; à coordonner avec [AUTH-010](../../auth/tickets/AUTH-010-conditions-d-utilisation-publiques.md) (page `/conditions`)
- **Faisable sans téléphone ni compte Play** : oui
- **Bloquant pour AUTH-002 / la connexion Google** : non (mais **STORE-017 en dépend** : la politique est obligatoire dans la console et dans l'application)
- **Statut** : à faire
- **Lire d'abord** : [`../publication-play-store.md`](../publication-play-store.md) §§ 6.e et 13 ; `docs/drive/note-vie-privee.md` (§ 2 le texte, § 4 le registre) ; `docs/auth/connexion-google.md` § 5.4 ; skills `repas-front-dev` et `repas-po`.

## Contexte

Google exige une politique de confidentialité qui « décrit comment l'application accède, collecte, utilise et partage les données », y compris la conservation et la suppression, accessible par un **lien public** (console) **et dans l'application**. Elle n'existe pas : la page des conditions est derrière la connexion (AUTH-010 la rend publique) et ne parle que du drive.

## Portée

1. **Page `/confidentialite`** (`meta: { public: true }`), en français simple, au tutoiement, phrases de 15 mots au plus, sans jargon. Sections : **Qui édite Repas** (nom ou pseudonyme et adresse d'assistance : textes fournis par le propriétaire, D2) ; **Ce que Repas retient** (adresse e-mail ; mot de passe, jamais lisible, seulement son empreinte ; la date de ta dernière connexion, une seule, que seule la personne qui administre Repas voit (`docs/drive/note-vie-privee.md` § 4 bis) ; identifiant Google si connexion Google ; tes recettes, ingrédients personnalisés et listes ; pour le drive : ton magasin, les produits retenus avec leur prix vu, la date d'envoi des listes ; la connexion garde un jeton dans un cookie réservé à Repas) ; **Ce que Repas ne retient jamais** (identifiants, cookies et panier de l'enseigne ; position ; contacts ; photos ; aucune publicité, aucune mesure d'audience) ; **Qui reçoit des données** (l'hébergeur du serveur, le service qui envoie les e-mails de confirmation, Google si tu choisis la connexion Google ; rien n'est vendu) ; **Combien de temps** (tant que le compte existe ; sauvegardes nocturnes, 14 conservées, environ deux semaines) ; **Supprimer ton compte** (lien vers `/suppression-de-compte`) ; **Tes droits** (écrire à l'assistance) ; **Enfants** (Repas s'adresse aux personnes de 18 ans et plus) ; **Changements** (date de dernière mise à jour).
2. **Liens** : un lien discret « Confidentialité » au pied des écrans de connexion et d'inscription (à côté de « Conditions d'utilisation », AUTH-010), et dans « Moi » (rubrique du compte) ; le lien existe donc **dans l'application** (la page chargée par la coquille).
3. **Les conditions** (`/conditions`) et la confidentialité se renvoient l'une à l'autre en une ligne.
4. Test de garde de DRV-628 : la route fait partie des exceptions (comme `/conditions`).

## Hors portée

La qualification juridique (base légale, registre des traitements, mentions complètes) : à revoir par le propriétaire, **non tranchée ici** ; la section « Connexion avec Google » des conditions (AUTH-010) ; la déclaration « sécurité des données » dans la console (STORE-017).

## Critères d'acceptation

- Étant donné un visiteur non connecté, quand il ouvre `/confidentialite`, alors la page s'affiche sans redirection, avec toutes les sections ci-dessus.
- Étant donné l'écran de connexion, alors un lien « Confidentialité » est visible au pied, discret, et mène à la page ; de même dans « Moi ».
- Étant donné la page à 360 px, alors elle se lit sans défilement horizontal.
- Étant donné les données listées, alors chacune correspond à une table ou un champ réel (voir « Pièges »).

## Tests exigés

Vitest : route publique, liens, textes (longueur des phrases, glossaire, tutoiement, absence de « jeton », « OAuth », « identité » côté utilisateur) ; Playwright : navigation sans session, capture à 360 px lue ; `make check`.

## Definition of Done

Celle du dépôt front ; `CLAUDE.md` du front (ligne de la route) ; description de PR : la liste des données et d'où elle vient ; l'assistant met à jour `note-vie-privee.md`.

## Pièges connus

- **Chaque phrase a sa source** (tableau « À garder vrai » de `note-vie-privee.md` § 3) ; ajouter les données du compte (table `user`, `refresh_token`, `user_identity`) et celles des prestataires (Brevo pour les e-mails, DigitalOcean pour l'hébergement ; la région du serveur n'est **pas** documentée dans le dépôt : ne pas l'affirmer).
- Les journaux du serveur (adresses IP, durée de conservation) ne sont pas documentés dans le dépôt : **à vérifier** avant d'écrire « Repas ne retient pas ton adresse IP » ; en cas de doute, ne pas l'écrire.
- Ne mettre aucun nom, aucune adresse ni aucun numéro dans le dépôt : l'identité de l'éditeur est un texte de configuration saisi par le propriétaire.
- L'URL `/confidentialite` est donnée à Google (console, fiche, éventuellement vérification de marque) : stable, sans redirection.
- Une page rendue seulement par JavaScript peut ne pas être lue par un robot : si la lisibilité sans JavaScript est jugée utile, la servir comme fichier statique de `public/` (le Caddy du front sert d'abord un fichier existant) ; trancher dans la PR.
