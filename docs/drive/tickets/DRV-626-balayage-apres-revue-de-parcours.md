# DRV-626 — Balayage après la revue de parcours

- **Dépôt** : front (`drive-agent/src/ui/`, `src/features/drive/`, `src/i18n/fr.ts`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-621 à 624
- **Statut** : fait (2026-10-19, PR repas-front#147, agent 0.20.0)
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

## Réalisation (2026-10-19, PR repas-front#147, agent 0.20.0)

Les douze points sont faits. Front `main` e32d9ce ; **rien n'est publié** (le protocole ne change pas : aucune clé nouvelle, donc pas d'ordre de publication entre le site et l'application pour ce ticket).

- **Agent 0.20.0**, empreinte `sha384-MEBncHZKGWZPJlfAUD/5K1EFhrX37289y7tw8i7PaGz8oiL7iB1K1nqdvS71pbfP` (`android/agent.js.sha384`).
- **Libellés, ancien → nouveau** : « 2 produits connus à mettre au panier / 4 nouveaux produits à choisir / 0 « j'en ai toujours » / 0 « pas cette fois » » → « 6 articles : 2 sont déjà choisis, 4 sont à choisir sur Leclerc. » (la même phrase que Repas, prouvée identique sur 864 combinaisons de comptes) ; « Passer les n suivants » → « Passer ces n articles » ; « Le panier est rempli en partie » (rien au panier ni choisi) → « Le panier n'est pas rempli » ; estimation sans prix (quatre messages) → « Pas encore de prix : Repas les retient à chaque envoi au drive. » ; « Produits retenus, par ordre de préférence » → « Produit habituel : » (un seul) ou « Produits retenus : » (plusieurs) ; note de vie privée de 50 mots → « Repas ne voit jamais votre mot de passe ni votre panier Leclerc. » ; nouveaux : « Il vous faut : 200 g. » (quantité), « Ouvrir mes listes » (sur « Aucun récapitulatif à afficher »).
- **Touches** : premier envoi de 4 nouveaux articles 3 / 3 ; deuxième envoi inchangé ; quantité à vérifier une suppression de moins ; réglages du drive un défilement de moins ; « Aucun récapitulatif » de l'impasse à 1 touche. Aucune touche ajoutée.
- **Point 12** : le défaut existait hors simulation. « Vos choix sont enregistrés pour les prochains envois. » s'affichait dès la fin de l'enregistrement, même sans aucun choix (erreurs, tout passé) ou avec de simples prix relus ; la phrase n'apparaît plus qu'avec au moins un choix enregistré (produit habituel, « Ne plus proposer », « J'en ai toujours »). Les prix seuls restent enregistrés, sans phrase.
- **Décisions de l'agent d'implémentation, confirmées par le PO** : la seconde ligne d'un libellé de produit n'est retirée que si elle redit la taille lue ; l'avertissement du back (« Estimation d'après… ») est masqué quand aucun prix n'est connu ; le champ de quantité se re-sélectionne à la première frappe (le curseur se pose après le toucher) ; pendant la confirmation de « Passer ces n articles », « Annuler l'envoi » est masqué aussi (deux boutons).
- **À confirmer par l'utilisateur** : le risque « Leclerc peut suspendre un compte » n'est plus dans la phrase courte de vie privée des réglages. Il reste dans la note complète (« Avant d'utiliser l'envoi : ce que vous devez savoir »). Remettre une phrase courte est une chaîne.
- **Vérifié** : `make check` (2668 tests), `make quality`, `make agent-verify`, `make android-test`, `make agent-e2e` (61), `make e2e` (235 réussis, 5 ignorés comme avant), `make android-e2e` (scénarios 1 à 15, 179 contrôles, 0 échec ; nouveau scénario 14 : taper 4 dans le champ de quantité donne 4 produits au panier). Captures lues : agent à 360 × 640 (Chromium et émulateur), web à 360 × 640 en clair et en sombre.

### Points laissés

- L'écran « Ce récapitulatif ne correspond pas à votre dernier envoi » n'a pas de bouton principal non plus.
- Un envoi terminé où tout est passé (rien au panier) dit encore « Le panier est rempli ».
- Si l'enregistrement de simples prix échoue, le texte parle de « choix ».
- La liste de l'écran d'envoi garde ses rangs « 1. », « 2. » (l'ordre est visible).
