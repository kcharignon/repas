# AUTH-014 — Poser un premier mot de passe depuis « Moi » (facultatif)

- **Dépôt** : back puis front
- **Jalon** : Facultatif (décision D3)
- **Taille** : S
- **Dépend de** : AUTH-006, AUTH-009 ; à faire avant AUTH-013 si D3 = « maintenant », sinon après `repas-front#31` ou jamais
- **Faisable sans téléphone** : oui
- **Statut** : à faire (facultatif)
- **Lire d'abord** : [`../connexion-google.md`](../connexion-google.md) §§ 4.2 (cas 17 et 18), 8 (réversibilité) ; `src/User/Application/UpdateUserSettings/UpdateUserSettingsHandler.php` ; skill `repas-dev` ; skill `repas-po` ; front : `src/features/account/SettingsForms.vue`.

## Contexte

Un compte créé par Google a un mot de passe verrouillé (`!`). Le changement de mot de passe de « Moi » exige l'**ancien** mot de passe : ce compte ne peut jamais en poser un. Tant que « mot de passe oublié » (`repas-front#31`) n'existe pas, c'est la seule issue si la fonction Google est coupée un jour, si le compte Google est perdu, ou si le proche veut se connecter autrement. Le ticket est facultatif : à faire si D3 = « maintenant », ou avant d'ouvrir la fonction à tout le monde sans `#31`.

## Portée

1. **Back** : `UpdateUserSettingsHandler` : si le compte n'a pas de mot de passe (`!$user->hasPassword()`) et qu'un nouveau mot de passe est fourni, accepter **sans** `actualPassword` ; sinon le comportement actuel (mot de passe actuel exigé, `422 INVALID_PASSWORD`). `changePassword()` fait monter la version des identifiants : les autres sessions sont révoquées. `GET /api/v1/me` expose `hasPassword: boolean` (DTO, loader, OpenAPI, `make openapi`).
2. **Front** : dans « Moi », quand `hasPassword` est faux, le champ « Mot de passe actuel » est absent, le bloc s'appelle « Choisir un mot de passe » ; après l'enregistrement, le bloc redevient « Changer le mot de passe ». Même règle de longueur (8 caractères) qu'à l'inscription. Textes dans `fr.ts`, vouvoiement jusqu'à DRV-627.
3. Documentation : `connexion-google.md` § 4.2 (cas 18 : la ligne passe de « facultatif » à « fait »), ADR 0024 décision 10.

## Hors portée

« Mot de passe oublié » par e-mail (`repas-front#31`) ; retirer le lien Google ; une confirmation par e-mail avant d'accepter le premier mot de passe.

## Critères d'acceptation

- Étant donné un compte créé par Google, quand il envoie un nouveau mot de passe sans l'ancien, alors `204`, `GET /api/v1/me` renvoie `hasPassword: true`, il peut se connecter par `POST /api/login_check` avec l'adresse et ce mot de passe.
- Étant donné un compte qui a déjà un mot de passe, quand il envoie un nouveau mot de passe sans l'ancien, alors `422 INVALID_PASSWORD` (inchangé).
- Étant donné un mot de passe posé, alors les autres jetons du compte sont révoqués (version des identifiants incrémentée) et la session courante doit se rouvrir.
- Étant donné « Moi » pour un compte sans mot de passe, alors un seul champ « Nouveau mot de passe » et un bouton ; aucun champ « Mot de passe actuel ».

## Tests exigés

Handler en unitaire (deux cas), `User` (`hasPassword`), loader/DTO (`hasPassword`), controller (clés JSON), scénario `WriteBudget` existant inchangé (mesurer) ; front : composant `SettingsForms`, textes et glossaire ; Playwright avec captures lues.

## Definition of Done

Celle des deux dépôts ; `docs/api/openapi.json` régénéré ; le front fige le contrat (`make api-update ref=<sha>`) après la fusion du back.

## Pièges connus

- Un jeton de session volé sur un compte sans mot de passe permettrait de **poser** un mot de passe et de garder l'accès après la révocation de la session : risque accepté (le voleur a déjà la session, un mois de cookie de rafraîchissement) ; à rouvrir si D3 change. Pas de contournement par e-mail dans ce ticket.
- Ne pas déduire « pas de mot de passe » d'un hachage vide : seule la valeur `User::NO_PASSWORD` l'indique (`hasPassword()`).
- L'écran ne doit pas faire croire qu'un mot de passe est obligatoire : c'est facultatif, rien ne l'impose dans le parcours (règles du produit).
