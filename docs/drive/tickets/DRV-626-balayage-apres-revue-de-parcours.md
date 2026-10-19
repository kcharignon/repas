# DRV-626 — Balayage après la revue de parcours

- **Dépôt** : front (`drive-agent/src/ui/`, `src/features/drive/`, `src/i18n/fr.ts`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-621 à 624
- **Statut** : en cours (lancé le 2026-10-19)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md), [`audit-simplicite.md`](../audit-simplicite.md) (§ 7), skills `repas-po`, `repas-agent-dev`, `repas-front-dev`.

## Contexte

Après DRV-621 à 624, le PO a fait parcourir le chemin heureux à 360 px (web simulé, agent réel dans l'émulateur, 80 captures) et lu les écrans. Les tickets précédents ont été testés chacun de leur côté : la revue a trouvé ce qui ne se voit qu'une fois les écrans mis bout à bout. Les douze points ci-dessous sont ceux que le PO a lus sur les captures et tranchés ; le reste est dans [`audit-simplicite.md`](../audit-simplicite.md) § 7.

## Portée

Agent :

1. **« Prêt à remplir votre panier »** : plus de compteurs à zéro (« 0 « j'en ai toujours » »), plus de « produits connus » / « nouveaux produits » pour des articles. Une phrase de synthèse identique à celle de l'écran de l'envoi de Repas (« 6 articles : 2 sont déjà choisis, 4 sont à choisir sur Leclerc. »).
2. **« Passer les n suivants » devient « Passer ces n articles »** : à « Poivre (1 sur 4) », « Passer les 4 suivants » laisse croire que l'article affiché n'est pas passé. Pendant la confirmation (« Ces n articles ne seront pas mis au panier. »), la liste de produits et la recherche sont masquées : un écran à deux boutons, une décision.
3. **Fiche produit** : la taille n'est affichée qu'une fois sur trois lignes (nom, prix, « n par produit : m produits. »).
4. **Pas de compteur « (1 sur 1) »** quand un seul article reste.
5. **« Vérifiez la quantité »** : le contenu du champ est sélectionné (taper 4 ne donne plus « 14 ») et « Il vous faut : 200 g. » rappelle la quantité demandée.
6. **Écran de fin quand rien n'est au panier** : « Le panier n'est pas rempli » (et non « …en partie ») ; même mot dans le récapitulatif de Repas.

Web :

7. **Liste envoyée** : la progression « 0 sur 6 articles dans le panier » (cases cochées en magasin) n'est pas affichée.
8. **Estimation du coût sans aucun prix connu** : une seule phrase (« Pas encore de prix : Repas les retient à chaque envoi au drive. »), sans compteur, avertissement ni bouton de détail ; le bloc complet reste dès qu'un prix existe.
9. **« Produits retenus, par ordre de préférence »** : disparaît (« Produit habituel : … ») ; « préférence » rejoint les mots gardés par le test du glossaire.
10. **« Aucun récapitulatif à afficher »** : un bouton principal (« Ouvrir mes listes »), plus d'impasse.
11. **Réglages du drive depuis « Moi »** : la note de vie privée tient en une phrase et un lien ; « Votre magasin » est visible sans défiler à 360 × 640.
12. **« Vos choix sont enregistrés pour les prochains envois. »** : seulement quand au moins un choix a été enregistré (vérifier si le défaut existe hors simulation).

## Hors portée

Les gestes de DRV-625 ; le bouton principal de « Vous n'êtes pas connecté » ; l'harmonisation des titres d'erreur entre l'agent et Repas ; « Android System WebView » ; les espaces insécables dans les guillemets ; les cibles tactiles de Repas hors du parcours Drive ; le back et le protocole.

## Critères d'acceptation

- Aucun compteur à zéro, aucun mot interdit, aucune phrase de plus de 15 mots dans les écrans touchés.
- Le parcours heureux garde ses touches (aucune ajoutée).
- À 360 × 640 : « Votre magasin » visible sans défiler dans les réglages ; « Ouvrir le drive » visible sans défiler sur l'écran de l'envoi.

## Tests exigés

Un test par point (voir la liste dans la PR) ; `voice-and-glossary.spec.ts` et `simpleScreens.spec.ts` à jour ; e2e de l'agent et de l'émulateur ; captures avant / après lues.

## Definition of Done

Celle du dépôt front ; version de l'agent incrémentée (0.20.0), empreinte recalculée ; touches avant / après dans la PR.

## Pièges connus

- Le web de la revue était simulé : un défaut vu seulement dans la simulation (point 12) se vérifie dans le code avant de le corriger.
- Un texte de l'agent change l'empreinte : une seule version de l'agent par PR.
