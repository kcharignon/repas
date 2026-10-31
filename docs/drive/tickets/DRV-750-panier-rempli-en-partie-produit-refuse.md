# DRV-750 — L'écran de retour dit « rempli en partie » quand un produit choisi est refusé

- **Dépôt** : front (web)
- **Jalon** : M7 Plusieurs enseignes (retour du propriétaire, hors ordre)
- **Taille** : XS
- **Dépend de** : DRV-746 (clé `notInCart`), DRV-747 (écran de retour qui coche les articles)
- **Statut** : à faire. Effort recommandé : `high`.
- **Lire d'abord** : skill `repas-po` ; `src/features/drive/DriveReturnView.vue` (titre et phrases du résultat `completed`, `basketReady`, `basketTicked`) ; `src/features/drive/basket.ts` (la règle « ligne mise au panier ») ; `src/i18n/fr.ts` (`driveReturn.outcome`) ; l'écran de fin de l'agent (`unreached`, `drive-agent/`) pour la même règle.

## Contexte

Constat de la recette du 2026-10-31 (téléphone de test, application 1.3.0). Un produit choisi à la main est refusé par le drive (« en rupture »). L'application dit bien « enregistré, mais pas dans ton panier », la ligne reste non cochée, mais l'écran de retour du site dit **« Le panier est rempli »** et « Ton panier est rempli. Tu peux finaliser ta commande… » : c'est faux pour cette ligne. Le propriétaire demande que l'écran dise « en partie ».

## À faire

1. **Quand le résultat est `completed` mais qu'au moins une ligne `CHOSEN` porte `notInCart: true`**, le titre devient « Le panier est rempli en partie » (la clé `driveReturn.outcome.partial.title` existe déjà ; ne pas dupliquer un texte). Même règle que `basket.ts` et que `unreached` de l'agent : lire les deux et aligner, ne pas inventer une troisième règle.
2. **Les phrases** (voix `repas-po`, tutoiement, trois phrases au plus, 15 mots au plus chacune, mots interdits de `voice-and-glossary.spec.ts`) disent : ce qui s'est passé (un produit que tu avais choisi n'est pas dans ton panier), ce qui est sauf (le reste est au panier et coché), ce qu'il reste à faire (« Reprendre l'envoi » est déjà proposé : une seule phrase d'action). Pas de nouvel avertissement. Proposer le texte dans la PR.
3. **Inchangé** : un résultat sans ligne refusée garde « Le panier est rempli » ; un agent d'avant, qui n'écrit pas `notInCart`, aussi (absence de la clé = au panier) ; « Pas cette fois », « J'en ai toujours » et un produit introuvable ne changent pas le titre (hors de la demande, dire dans la PR si une de ces trois issues mérite le même titre).
4. Tests : `DriveReturnView.spec.ts` (completed + une ligne refusée : titre « en partie », phrases, coches des seules lignes au panier ; completed sans ligne refusée : titre d'avant ; sans la clé : titre d'avant), l'e2e du retour s'il couvre le titre, la capture de l'écran lue avant de fusionner.
5. Docs front dans le même commit (skill `repas-front-dev`, `CLAUDE.md` du front, ADR front 0008 si elle cite le titre).

## Ordre de livraison

Release du site seule, sans le back ni l'application. Le titre se juge sur le résultat que l'agent 0.54.0 et suivants écrivent déjà.
