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
