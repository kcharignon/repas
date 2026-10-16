# 0020 — Confirmation d'adresse email à l'inscription

- **Statut** : Accepted
- **Date** : 2026-10-16
- **Liens** : [0007](0007-api-rest-v1.md) §12 et §14, [0008](0008-auth-session-et-jwt.md) §4, §7, §12, [0015](0015-refresh-token-du-spa.md), [0017](0017-deploiement-et-releases.md), issue `kcharignon/repas-front#29`

## Contexte

L'inscription publique (`POST /api/v1/users`) crée un compte utilisable aussitôt, sans rien vérifier de l'adresse saisie : n'importe qui peut inscrire l'adresse d'un tiers, et un mot de passe oublié n'aurait aucun canal de secours (issue `repas-front#31`, qui dépend de celle-ci). L'application n'envoyait aucun email.

Faits : Twig est supprimé ([0018](0018-extinction-de-twig.md)), donc pas de gabarits d'email ; Messenger n'a aucun transport asynchrone et la production ne lance aucun worker (`deploy/compose.yaml`) ; le bus de commandes est transactionnel ([0006](0006-buses-messenger.md)) ; DigitalOcean filtre le SMTP sortant des nouveaux droplets, l'envoi passe donc par un service transactionnel tiers.

## Décision

1. **Nouveau statut `PENDING`** (`UserStatus`). `User::register()` crée un compte `PENDING` ; `User::create()` reste `ACTIVE` (fixtures, données de démonstration). `User::verifyEmail()` ne fait passer que `PENDING` à `ACTIVE` : un compte désactivé le reste. Les comptes existants restent `ACTIVE` sans migration de données (la colonne est du texte).
2. **Le lien de confirmation** porte un secret aléatoire de 256 bits (`SecretGenerator`), stocké **haché** (SHA-256) dans `email_verification_token`, valable **24 h** (`EmailVerificationToken::TTL`), à **usage unique** (la confirmation le supprime), un seul valable par compte (en émettre un nouveau remplace l'ancien). Modèle `EmailVerificationToken`, port `EmailVerificationTokenRepository`, service de domaine `EmailVerificationIssuer` partagé par l'inscription et le renvoi.
3. **Le secret ne traverse pas le bus** : `VerifyEmailCommand` porte le hachage, calculé par le controller (comme `IssueRefreshTokenCommand`, [0015](0015-refresh-token-du-spa.md) §1). À l'inscription, le secret naît dans `EmailVerificationIssuer` et ne sort que par l'email.
4. **Envoi par Symfony Mailer** (`MAILER_DSN`, secret ; `MAILER_FROM`, l'expéditeur ; `FRONT_URL`, l'adresse du front). Port `EmailVerificationNotifier`, adaptateur `MailerEmailVerificationNotifier` (texte et HTML construits en PHP, sans Twig). **Envoi direct dans la requête** (`framework.mailer.message_bus: false`) : pas de transport asynchrone ni de worker à exploiter. Un échec de transport est **journalisé (sans l'adresse) et non relancé** : le compte est créé, l'utilisateur redemande le lien ; lever l'exception laisserait le renvoi révéler, par un `500`, quelles adresses sont des comptes en attente.
5. **Le lien mène au front** : `{FRONT_URL}/confirmation-email?token=<secret>`. Le front appelle ensuite l'API.
6. **Connexion refusée tant que l'adresse n'est pas confirmée** : `UserChecker::checkPostAuth()` lève `EmailNotVerifiedException` ; `EmailNotVerifiedFailureListener` répond `403 {"error":{"message":"EMAIL_NOT_VERIFIED"}}`. Le contrôle est **après** celui du mot de passe : un mot de passe faux reste `401 Invalid credentials.`, l'état du compte n'est révélé qu'à qui le connaît. C'est une exception motivée à « `403` réservé à l'autorisation » ([0007](0007-api-rest-v1.md) §12) : les identifiants sont valides et l'accès est refusé, et le front doit distinguer ce cas d'un mauvais mot de passe pour proposer le renvoi. Un compte désactivé reste un `401` générique ([0008](0008-auth-session-et-jwt.md) §7).
7. **Deux routes publiques** ([0007](0007-api-rest-v1.md) §14, chemin exact et méthode dans `access_control`) : `POST /api/v1/users/email-verification/send` (corps `{email}`) renvoie le lien ; `POST /api/v1/users/email-verification/confirm` (corps `{token}`) active le compte, `204` ou `422 INVALID_EMAIL_VERIFICATION_TOKEN` (inconnu, déjà utilisé ou expiré : on ne dit pas lequel).
8. **Le renvoi ne révèle rien** : `204` que l'adresse soit inconnue, déjà confirmée, désactivée, ou qu'un lien soit parti il y a moins de 60 s (`EmailVerificationToken::RESEND_COOLDOWN_SECONDS`, par compte : pas de mitraillage de la boîte d'un tiers).
9. **Plafond de débit** : les deux routes partagent le limiteur `email_verification` (20 par heure et par IP, `EmailVerificationRateLimitListener`), `429` + `Retry-After` au-delà ([0008](0008-auth-session-et-jwt.md) §12).
10. **Une adresse en attente n'est pas réattribuée** : s'inscrire avec une adresse déjà prise, confirmée ou non, répond `409` comme avant. Écraser le mot de passe d'un compte en attente permettrait à un tiers de s'inscrire avec l'adresse d'autrui, de laisser sa victime confirmer, puis de se connecter.
11. **Environnements** : en développement, Mailpit (service `mailer` de `compose.override.yaml`, boîte sur `http://localhost:8025`) ; en test, `null://null` (les tests lisent le journal du mailer) ; en production, l'envoi passe par l'**API HTTPS de Brevo** (`MAILER_DSN=brevo+api://<clé>@default`, pont `symfony/brevo-mailer`) parce que DigitalOcean bloque le SMTP sortant (25, 465, 587) ; `MAILER_DSN` et `MAILER_FROM` sont **obligatoires** dans `/opt/repas/.env` (`${…:?}` dans `deploy/compose.yaml`) : sans eux le déploiement échoue et le code revient en arrière ([0017](0017-deploiement-et-releases.md)). `FRONT_URL` vaut `https://${APP_HOST}`.

## Conséquences

- Positives : l'adresse d'un compte est prouvée ; le mot de passe oublié (`repas-front#31`) réutilise le service d'envoi, le port et le modèle de jeton (un lien de réinitialisation prouvera aussi l'adresse, ce qui débloque le compte d'un tiers qui aurait pris une adresse en attente) ; aucun worker à exploiter.
- Coûts : une table, une dépendance (`symfony/mailer`), un service d'envoi tiers à ouvrir, à authentifier par DNS (SPF, DKIM) et à surveiller ; l'envoi est synchrone, donc la latence du fournisseur s'ajoute à l'inscription et à son transaction ; budget SQL de l'inscription de 3 à 4 requêtes (l'insertion du jeton), deux nouveaux use cases d'écriture ; un compte jamais confirmé reste en base et occupe son adresse (pas de purge : à décider quand il y en aura) ; la liste d'administration des utilisateurs expose le statut (`status`) depuis [0021](0021-gestion-des-comptes-par-l-administrateur.md), qui permet aussi d'activer ou de désactiver un compte à la main.
- Garde-fou : tests des handlers (en mémoire), du dépôt (base), du notifier, du plafond et du parcours complet inscription → connexion refusée → lien → connexion (`EmailVerificationFlowTest`) ; `make api-doc-coverage` et `make openapi-check` pour les deux routes ; `make deploy-smoke` pour les variables de production.

## Alternatives écartées

- SMTP d'un service tiers (Brevo, SendGrid, Mailgun) sur le port 587 : bloqué par DigitalOcean sur les droplets ; seul le port 2525 ou une API HTTPS passe, et l'API n'ajoute qu'un pont Symfony.
- Envoi asynchrone par Messenger : demande un worker en production (supervision, redémarrage) pour un volume de quelques emails par jour.
- `TemplatedEmail` et gabarits Twig : Twig est supprimé ([0018](0018-extinction-de-twig.md)), deux gabarits courts tiennent en PHP.
- `401` pour un compte en attente : le front ne pourrait pas le distinguer d'un mauvais mot de passe sans lire un message, déjà générique par choix ([0008](0008-auth-session-et-jwt.md) §7).
- Réattribuer ou écraser un compte en attente à une nouvelle inscription : voir §10.
- Stocker le jeton dans la table `user` : un seul jeton serait suffisant, mais le mot de passe oublié en demande un second de durée différente ; un agrégat à part évite de grossir `User`.
- Code à six chiffres saisi à la main : un secret de 256 bits dans un lien se devine impossiblement et n'exige aucune saisie.
