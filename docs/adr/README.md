# Architecture Decision Records

Décisions d'architecture du projet Repas. **À relire avant de développer ou de migrer une fonctionnalité** (le skill `repas-dev` le demande). Format : voir [0000-template.md](0000-template.md). Une décision remplacée n'est jamais supprimée : on passe son statut à `Superseded by …`.

| N° | Titre | Statut |
|---|---|---|
| [0001](0001-architecture-hexagonale.md) | Architecture hexagonale, règle de dépendance, bounded contexts | Accepted |
| [0002](0002-modele-domaine-riche.md) | Modèle de domaine riche : `create()` vs `load()`, Clock/UuidGenerator injectés | Accepted |
| [0003](0003-cqrs-lecture-ecriture.md) | CQRS : écriture par CommandBus, lecture par QueryBus | Accepted |
| [0004](0004-repository-vs-loader.md) | Repository (agrégats, écriture) vs Loader (projections, lecture) | Accepted |
| [0005](0005-conventions-dto-lecture.md) | Conventions des DTO de lecture | Accepted |
| [0006](0006-buses-messenger.md) | Deux buses Messenger : `command.bus` et `query.bus` | Accepted |
| [0007](0007-api-rest-v1.md) | API REST `/api/v1` consommée par un front | Accepted |
| [0008](0008-auth-session-et-jwt.md) | Authentification : session pour le web, JWT stateless pour `/api` | Accepted |
| [0009](0009-strategie-de-migration.md) | Stratégie de migration « strangler » par slice de lecture | Accepted |
| [0010](0010-strategie-de-tests.md) | Stratégie de tests de la lecture et de l'API | Accepted |
| [0011](0011-garde-fous-deptrac-phpstan.md) | Garde-fous : Deptrac et PHPStan | Accepted |
| [0012](0012-pieges-compliance.md) | Pièges observés dans Compliance à ne pas reproduire | Accepted |
| [0013](0013-agregats-references-par-identifiant.md) | Un agrégat référence les autres agrégats par identifiant | Accepted |
| [0014](0014-montee-de-versions-et-dependabot.md) | Montée de versions, Dependabot et avis de sécurité | Accepted |
| [0015](0015-refresh-token-du-spa.md) | Authentification du SPA : refresh token en cookie `HttpOnly` à rotation | Accepted |
| [0016](0016-controle-de-version-etag-if-match.md) | Contrôle de modification concurrente : version, `ETag` et `If-Match` | Accepted |
| [0017](0017-deploiement-et-releases.md) | Déploiement : release GitHub, images GHCR, bordure Caddy, retour arrière automatique | Accepted |
| [0018](0018-extinction-de-twig.md) | Extinction de Twig : le back ne sert plus que l'API | Accepted |
| [0019](0019-recherche-d-ingredients-par-pertinence.md) | Recherche d'ingrédients par pertinence (Jaro-Winkler) | Accepted |
| [0020](0020-confirmation-d-adresse-email.md) | Confirmation d'adresse email à l'inscription : statut `PENDING`, lien à usage unique, Symfony Mailer | Accepted |
| [0021](0021-gestion-des-comptes-par-l-administrateur.md) | Gestion des comptes par l'administrateur : statut libre, compte protégé, suppression (purge des données, copies conservées) | Accepted |
| [0022](0022-remplissage-du-panier-drive.md) | Remplir le panier d'un drive : agent de page, aucun identifiant d'enseigne | Accepted |
| [0023](0023-liste-envoyee-au-drive.md) | Liste envoyée au drive : statut `SENT` et verrou | Accepted |
| [0024](0024-connexion-avec-google.md) | Connexion avec Google : jeton d'identité, GIS sur le web, Credential Manager dans l'application, compte lié par `sub` | Accepted |

## Sources

- Projet de référence : `/home/kantin/Projet/Compliance` (`CLAUDE.md`, `quality-rules/`, `src/Compliance/Application/{Query,Loader}`).
- Présentation : `/home/kantin/Projet/presentation-archi/presentation.md` et `CQRS-schema.png`.
- Roadmap de migration : [`../migration-roadmap.md`](../migration-roadmap.md).
- Skill de développement : `.claude/skills/repas-dev/SKILL.md`.

## Règles de maintenance

- Une décision d'architecture nouvelle = un nouvel ADR (numéro suivant) dans le même commit que le code qui l'applique.
- Changer une convention = mettre à jour l'ADR concerné, `CLAUDE.md`, le skill `repas-dev` et la roadmap ensemble.
