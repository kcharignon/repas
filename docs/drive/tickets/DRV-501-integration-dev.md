# DRV-501 — Intégration en développement et checklist de recette

- **Dépôt** : back + front
- **Jalon** : M5 Intégration et livraison
- **Taille** : M
- **Dépend de** : M2, M3, M4 terminés
- **Statut** : à faire
- **Lire d'abord** : tout `docs/drive/` ; `docs/production.md` ; `make start` du back et `make up` du front (voir `CLAUDE.md`).

## Portée

1. Faire tourner ensemble, en développement, le back (avec `DRIVE_ENABLED=true`), le front, l'agent construit et le faux site Leclerc : un parcours complet du bouton au récapitulatif, avec un magasin enregistré par le mode `setup`.
2. Corriger les écarts de contrat entre les trois morceaux (formats, codes d'erreur, clés).
3. Écrire `docs/drive/recette.md` : checklist de recette sur le téléphone réel (pas à pas pour l'utilisateur) couvrant : installation du favori, enregistrement du magasin, première liste (tous les ingrédients nouveaux), deuxième liste (produits connus), « autre produit », « passer », « j'en ai toujours », produit indisponible, perte de réseau, blocage, liste rouverte, liste terminée, suppression du compte (purge).
4. Vérifier les budgets SQL, la couverture, Deptrac, PHPStan, l'absence de secret.
5. Mettre à jour `docs/drive/roadmap.md`.

## Critères d'acceptation

Un agent d'intégration déroule `recette.md` en développement sans écart non expliqué ; tous les contrôles qualité sont verts dans les deux dépôts.

## À vérifier à l'intégration (relevé par les agents du front, 2026-10-18)

- Fixer `BASKET_PATH` (`src/features/drive/leclerc.ts`, vide pour l'instant : page d'accueil du magasin) avec l'adresse de la page panier relevée à la recette ; confirmer le lien de recherche du mode assisté `${store.baseUrl}/recherche.aspx?TexteRecherche=…`.
- Vérifier côté application que `drive.result` arrive dans la page Repas au retour de `DriveActivity` (`useDriveReturn` écoute `repasNative` dès le démarrage et mène à `/drive/retour`) et qu'un passage abandonné renvoie un résultat `aborted`.
- Un vrai `PUT /drive/choices` avec identifiants et prix réels (bornes du back : contenu 0,001 à 100 000, prix 0 à 1 000 000 centimes, `retailerProductId` chaîne de 1 à 12 chiffres).
- `needed.unitSymbol` vide : le front omet `needed` pour la ligne (l'agent demande la quantité) ; le back renverra désormais le nom de l'unité à défaut de symbole (partie back de DRV-501) : retirer alors la rustine du front.
- Un `setup-result` (lecture du magasin) arrive dans `useDriveReturnStore().event` ; il manque un `startRun` pour un message `setup` dans le pont (DRV-403/314 : message `drive.setup`).
- Après un rechargement, les choix « autre produit » et « passer » ne sont pas gardés (le brouillon n'a volontairement aucune donnée personnelle).
- Le récapitulatif du retour est en mémoire seulement : une page rechargée avant l'enregistrement le perd, le brouillon reste.
