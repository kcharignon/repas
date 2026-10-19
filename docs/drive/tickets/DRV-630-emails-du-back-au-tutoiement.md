# DRV-630 — Les textes du back adressés à l'utilisateur passent au tutoiement

- **Dépôt** : back (`kcharignon/repas`), contexte `User`
- **Jalon** : M6 Simplicité d'usage
- **Taille** : S
- **Dépend de** : DRV-627 (fait : le front parle déjà au tutoiement)
- **Statut** : fait (2026-10-19, PR back #200 ; le texte part avec la prochaine release du back)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (§ 3, tutoiement), [`DRV-627`](DRV-627-tutoiement.md), skills `repas-po` et `repas-dev`, [ADR 0020](../../adr/0020-confirmation-d-adresse-email.md) (e-mail de vérification d'adresse).

## Contexte

DRV-627 a passé tous les textes du front, de l'agent et de l'application Android au tutoiement. Il reste, dans le back, deux textes écrits pour l'utilisateur au vouvoiement (relevé par `grep -rniE "vous|votre|vos" src`, le 2026-10-19 : ces deux fichiers seulement) :

- `src/User/Infrastructure/Mailer/MailerEmailVerificationNotifier.php` : l'e-mail de vérification d'adresse, reçu par chaque personne qui s'inscrit : l'objet « Confirmez votre adresse email », le corps texte et le corps HTML (« Pour activer votre compte, confirmez votre adresse email… », « Si vous n'avez pas créé de compte… », « copiez cette adresse dans votre navigateur »). C'est le **premier texte** que lit un proche : il doit parler comme le reste de Repas.
- `src/User/Domain/Service/UserChecker.php` : le message « Votre compte est désactivé. » de `CustomUserMessageAccountStatusException`. L'API ne le montre jamais (une connexion refusée répond `401 Invalid credentials.`, ADR 0008 § 7 et ADR 0021) ; on le met à la même voix pour que le dépôt ne garde aucun « vous » adressé à l'utilisateur.

## Portée

1. L'e-mail de vérification : objet, corps texte et corps HTML au tutoiement, **sans changer le fond** (même lien, même durée de validité, mêmes phrases), par exemple « Confirme ton adresse email », « Pour activer ton compte, confirme ton adresse email en ouvrant ce lien (valable 24 heures) », « Si tu n'as pas créé de compte, ignore ce message : rien ne sera activé. », « copie cette adresse dans ton navigateur ». Phrases courtes, mêmes mots que le front (glossaire) ; l'orthographe « email » ne change pas.
2. `UserChecker` : « Ton compte est désactivé. »
3. Les tests qui citent ces textes : `tests/User/Infrastructure/Mailer/MailerEmailVerificationNotifierTest.php` (objet), `tests/User/Infrastructure/Mailer/BrevoTransportTest.php` (objet de la fixture), `tests/Shared/Infrastructure/Http/ApiAuthenticationFailureListenerTest.php` (message de la fixture).
4. Un balayage final : `grep -rniE "vous|votre|vos" src tests` ne trouve plus aucun texte adressé à l'utilisateur.

## Hors portée

Le fond ou la mise en page de l'e-mail, l'orthographe « email » / « e-mail », le fournisseur d'envoi (Brevo), le front, tout changement de l'API : **aucun** (`docs/api/openapi.json` ne bouge pas, `make openapi-check` reste vert). Les messages d'erreur de l'API sont des codes (`SHOPPING_LIST_SENT`, `DRIVE_DISABLED`…) que le front traduit : rien à faire.

## Critères d'acceptation

- L'e-mail de vérification, en texte et en HTML, ne contient ni « vous », ni « votre », ni « vos », ni impératif en « -ez » ; il contient toujours le lien vers le front et la durée de validité (« 24 heures »).
- Le message de `UserChecker` est au tutoiement ; une connexion refusée répond toujours `401 Invalid credentials.` (rien n'est révélé).
- Aucune autre route ni aucun budget SQL ne change.

## Definition of Done

Celle du dépôt back : `make test` et `make quality` verts ; PR concise ; fusion si la CI est verte. L'e-mail se lit en développement dans Mailpit (http://localhost:8025) : relire l'objet et le corps, texte et HTML. Rien n'est publié par ce ticket : le texte part avec la prochaine release du back.

## Réalisation (2026-10-19, PR back #200)

Fusionné dans le back `main` (`7086b6f`) ; **rien n'est publié** : le texte part avec la prochaine release du back (v0.6.2, qui porte aussi le durcissement des libellés de produits, PR #198 : à publier **après** le front et l'application, voir `docs/drive/runbook.md`).

- **E-mail de vérification** : objet « Confirmez votre adresse email » → « Confirme ton adresse email » ; corps texte et HTML au tutoiement (« Pour activer ton compte, confirme ton adresse email en ouvrant ce lien (valable 24 heures) : », « Si tu n'as pas créé de compte, ignore ce message : rien ne sera activé. », « Si le lien ne s'ouvre pas, copie cette adresse dans ton navigateur : »). Même lien, même durée, mêmes phrases ; 15 mots au plus ; aucun avertissement ajouté.
- **`UserChecker`** : « Votre compte est désactivé. » → « Ton compte est désactivé. » (jamais montré : une connexion refusée répond toujours `401 Invalid credentials.`).
- **Tests** : fixtures de `BrevoTransportTest` et `ApiAuthenticationFailureListenerTest`, objet attendu de `MailerEmailVerificationNotifierTest` et `UserCheckerTest` alignés ; un test échoue si « vous », « votre », « vos » ou un impératif en « -ez » revient dans l'objet, le texte ou le HTML ; la durée « 24 heures » est contrôlée.
- **Vérifié** : `make test` (2284 tests), `make quality` vert, contrat OpenAPI inchangé, budget SQL inchangé ; relecture dans Mailpit (objet, texte, HTML) faite, avec une adresse fictive.
