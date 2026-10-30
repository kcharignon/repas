# DRV-737 — Bouton « Envoyer au drive » masqué sans drive, « Configurer un drive », choix à chaque envoi

- **Dépôt** : front (web)
- **Jalon** : M7 Plusieurs enseignes
- **Taille** : S
- **Dépend de** : DRV-736
- **Statut** : fait (PR front #267, 2026-10-30). Décision du propriétaire du 2026-10-30 (ADR 0027, amendement).

## Ce que l'utilisateur voit

- **Aucun drive configuré** (`selection.mode = NONE`) : pas de bouton « Envoyer au drive » sur la liste en cours (« Terminer » devient l'action principale) ; le menu « ⋮ » propose « **Configurer un drive** » (liste en cours, dans l'application, fonction allumée, jamais dans un navigateur ni pour une liste envoyée ou terminée), qui ouvre l'écran des réglages du drive : « Quel drive utilises-tu ? » (une carte par drive) ou, avec un seul drive allumé, « Choisir mon magasin » ; le magasin enregistré, la personne revient à sa liste. L'écran d'envoi reste un filet de sécurité quand on y arrive directement.
- **Un seul drive** (`AUTO`) : aucun changement, aucune question.
- **Plusieurs drives** (`ASK`) : la question « Où envoyer ta liste ? » est posée à chaque envoi, le drive habituel (sinon le dernier utilisé) est coché, un seul bouton « Ouvrir {drive} » ; le lien « Envoyer ailleurs » est retiré.
- « Mon drive habituel » (dans « Moi ») : « Repas te le propose d'avance quand tu envoies ta liste. »

## Détails

Un back sans `selection` retombe sur la présence d'un magasin pour afficher le bouton ; l'entrée « Configurer un drive » exige strictement `mode = NONE`. Tests : Vitest et Playwright (le banc de l'émulateur tourne sur un faux Repas qui ne passe ni par ce bouton ni par `selection` : aucune adaptation nécessaire).
