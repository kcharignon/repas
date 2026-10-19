# DRV-714 — Spike Auchan A3 : recherche, produits, panier

- **Dépôt** : — (tâche **humaine** sur téléphone ; rapport `docs/drive/spikes/DRV-714-rapport.md`)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : M
- **Dépend de** : DRV-712
- **Statut** : à faire.
- **Faisable sans téléphone** : **non, et jamais depuis un ordinateur ni un serveur** (voir DRV-712).
- **Lire d'abord** : [`../multi-enseignes.md`](../multi-enseignes.md) §§ 11, 12 (inconnues U7 à U12) ; [`../spikes/DRV-007-requete-ajout-panier.md`](../spikes/DRV-007-requete-ajout-panier.md) et [`../recherche.md`](../recherche.md) § 2 (ce qu'on a relevé chez Leclerc, modèle de rapport) ; [`../architecture.md`](../architecture.md) §§ 6, 8 (ce que l'agent exige d'un adaptateur).

## Contexte

L'adaptateur doit savoir : chercher un produit, lire ses champs, fixer **une quantité absolue** au panier, lire disponibilité et prix, retrouver ce que le panier contient. Chez Leclerc ces faits ont été relevés par l'observation d'une vraie session (recherche embarquée dans la page, panier en `POST` avec quantité absolue). Rien de tel n'est connu pour Auchan : une source parle d'API de recherche et de panier « solides » (A5, source unique) et d'une lecture du catalogue par les microdonnées schema.org de la page (A3).

## Règles de sécurité de l'essai

Celles de DRV-712 et DRV-713. En plus : **les requêtes ne se relèvent qu'en observant le navigateur du téléphone** (outil de développement du navigateur du téléphone, ou débogage USB de Chrome depuis un ordinateur **branché au téléphone, le site n'étant jamais ouvert dans un navigateur de l'ordinateur** : le trafic reste celui du téléphone). C'est à l'humain de décider s'il accepte le débogage USB ; sinon il note ce qu'il voit à l'écran (champs visibles, prix, disponibilité) et le rapport le dit.

## Portée (une ligne chacun)

1. **Recherche** : adresse de la page de résultats (forme, **sans** identifiant de magasin réel) ; paramètre de recherche ; les résultats sont-ils dans le HTML, dans un JSON embarqué, ou chargés par une requête séparée (forme de la requête, méthode, nom des champs) ; pagination.
2. **Produit** : champs disponibles (identifiant, libellé, marque, contenu net, prix, prix au kilo ou au litre, promotion, photo, disponibilité, code-barres) avec leur **nom** exact ; **forme de l'identifiant** (longueur, chiffres/lettres : numérique ou non), stable d'un magasin à l'autre ou non ; présence d'un EAN dans le HTML ou une réponse.
3. **Disponibilité** : champ qui dit « indisponible » (nom, valeurs) ; cas « bientôt disponible » ; le mode choisi (A9) change-t-il la valeur ?
4. **Ajout au panier** : méthode, adresse (forme), en-têtes inhabituels (nom seulement), forme du corps (champs), **quantité absolue ou delta** (essai : 1 puis 3 puis 2 sur un produit, résultat), retrait complet, code de réponse et forme de la réponse (état complet du panier ?).
5. **Jetons** : la requête d'ajout exige-t-elle un jeton ou un en-tête propre à la session (nom seulement, jamais la valeur) ? Est-il présent dans la page (donc lisible par un script de la page) ?
6. **Lecture du panier** : total et quantité d'un produit, sans écrire ; où (page du panier, réponse d'ajout, widget).
7. **Plafonds** : quantité maximale par produit ; message de dépassement.
8. **Contenu net** : champ structuré ou à extraire d'un libellé (exemple inventé) ; prix à l'unité de mesure.
9. **Photos** : domaine de l'image, forme de l'adresse (exemple inventé).
10. **Pages du panier** : adresse (forme) de la page du panier et si elle est atteignable directement ou seulement depuis le site (cas « introuvable » de Leclerc, DRV-631).

## Hors portée

Du code ; reproduire une requête avec un script ; tout appel hors du téléphone ; la comparaison de prix.

## Critères d'acceptation

- Le rapport répond à U7 à U12 ou dit précisément pourquoi une réponse manque.
- Un tableau « champ attendu par l'agent → champ observé chez Auchan » (identifiant, libellé, contenu, prix, disponibilité, quantité au panier) est rempli ou signale le trou.
- Le sens de la quantité (absolue ou delta) est établi par un essai, pas par supposition.
- Aucune donnée personnelle, aucun jeton, aucun HTML brut : des noms de champs et des exemples inventés.

## Tests exigés

Aucun test de code ; relecture du rapport (fuites).

## Definition of Done

Rapport fusionné ; `multi-enseignes.md` § 11 à jour ; roadmap cochée.

## Pièges connus

- Chez Leclerc, la première lecture du contrat était fausse (adresse sans ville, en-tête personnalisé) : relever la requête **telle que le site la fait**, pas une version simplifiée.
- Une écriture sans quantité absolue casserait l'invariant « le panier n'est jamais réduit » : c'est la première chose à établir.
- Les écritures en parallèle ont fait bloquer un client chez Leclerc : à la main, une à la fois.
