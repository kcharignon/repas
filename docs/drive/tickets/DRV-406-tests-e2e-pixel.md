# DRV-406 — Tests e2e Pixel 7, accessibilité et test visuel

- **Dépôt** : front, `e2e/`
- **Jalon** : M4 Front
- **Taille** : M
- **Dépend de** : DRV-403, DRV-404, DRV-405
- **Statut** : à faire
- **Lire d'abord** : `playwright.config.ts`, `e2e/` existants, `CLAUDE.md` du front (`make e2e`, `make e2e-visual`, comptes de démonstration).

## Contexte

Le parcours complet se joue sur téléphone. Il faut des tests de bout en bout contre le vrai back de développement, avec un agent **simulé** (le vrai agent est testé contre le faux site dans DRV-309).

## Portée

1. Scénarios Playwright (profil Pixel 7 et bureau) : envoyer une liste (avec `DRIVE_ENABLED=true` en développement) → écran du plan → surcharges → ouverture simulée (une page de test sert de « Leclerc » et lit le fragment) → retour avec un résultat préparé → enregistrement des choix → récapitulatif → « Terminer » ; liste envoyée non modifiable ; « Rouvrir » ; « J'en ai toujours » puis « Réactiver » ; page de réglages.
2. Accessibilité (axe) sur chaque nouvel écran, clair et sombre.
3. Test visuel : captures mobile et bureau lues avant fusion.
4. Données de démonstration : `make fixtures` fournit les comptes `admin@repas.fr` et `user@repas.fr` ; ajouter ce qu'il faut côté back (magasin et choix de démonstration) uniquement si nécessaire et dans le dépôt back.

## Critères d'acceptation

- `make e2e` passe sur les nouveaux scénarios, séquentiellement (`workers: 1`) ; un échec est reproductible.
- Aucun secret ni identifiant réel dans les tests ; mots de passe de démonstration par variables d'environnement.

## Definition of Done

Celle du front ; captures jointes ou décrites dans la PR.

## Pièges connus

- Vider le limiteur de débit avant une série : `docker compose exec php bin/console cache:pool:clear cache.rate_limiter` (voir le `CLAUDE.md` du front).
