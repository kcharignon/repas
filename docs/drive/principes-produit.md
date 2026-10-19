# Principes produit : un parcours et des mots simples

Référence du rôle de Product Owner sur le chantier Drive (skill `/repas-po`). À lire avant tout ticket qui touche un écran, un texte, un message d'erreur ou un enchaînement de gestes, dans les trois domaines : front web, agent, application Android. Décision de l'utilisateur (2026-10-19) : le flux et les textes doivent être **le plus simples possible pour les utilisateurs finaux**.

## 1. Pour qui

L'utilisateur final est l'auteur et ses proches : des personnes **non techniques**, sur un téléphone, souvent debout dans une cuisine ou dans un magasin, qui ne lisent pas les textes longs et ne connaissent ni « agent », ni « WebView », ni « API ». Ils sont habitués à Repas et au site de Leclerc, pas aux outils de développement.

Critère de réussite du chantier : **un proche réussit son premier envoi sans aide**, puis ses envois suivants sans y penser.

## 2. Règles de parcours

1. **Un écran, une décision.** Un seul bouton principal, visible sans faire défiler ; les actions secondaires sont plus discrètes, et rares.
2. **Compter les touches.** Tout ticket qui change un parcours écrit le nombre de touches du parcours heureux avant et après. On n'en ajoute pas une sans en retirer une.
3. **Ne jamais demander ce qu'on sait déjà** : le magasin, les produits retenus, les quantités calculées, la ligne que l'utilisateur vient de choisir.
4. **Une confirmation par geste important, jamais deux pour la même chose.** On confirme ce qui est difficile à défaire (verrouiller la liste, mettre au panier), jamais ce qui se défait d'un geste. La confirmation que la sécurité impose (l'agent annonce ce qu'il va faire avant d'écrire) est la **seule** de l'étape : l'écran précédent ne la répète pas.
5. **Un défaut sûr et utile.** L'option la plus courante est celle qui est déjà choisie ; les options avancées sont rangées derrière « Plus d'options » ou dans les réglages.
6. **Rien à régler avant de servir.** Le premier envoi marche sans réglage préalable ; ce qui manque (le magasin) s'obtient au moment où il est nécessaire, par **un bouton qui fait le travail** (« Choisir mon magasin »), pas par un message qui renvoie vers un autre écran.
7. **L'erreur est un écran du parcours**, pas une impasse : elle dit ce qui s'est passé, ce qui est sauf, et propose **une** action. Voir § 4.
8. **L'utilisateur ne voit jamais l'architecture** : ni le plan, le passage, l'agent, le pont, le protocole, le contrat, la WebView, ni un code d'erreur. Les détails techniques vivent dans « Détails » (repliés) et dans le diagnostic.
9. **Le chemin heureux est court même quand tout est nouveau, et très court quand tout est connu** : un deuxième envoi, où tout est déjà retenu, tient en quatre touches (voir `audit-simplicite.md`).

## 3. Règles de texte

- **Vouvoiement partout** (décision PO du 2026-10-19) : c'est la voix de Repas depuis toujours (111 occurrences dans le front, aucune de « tu ») et celle des pages de Leclerc que l'agent recouvre. Le tutoiement de l'agent et de l'application Android est à corriger (DRV-621). Les documents de développement restent libres.
- **Phrases courtes** : 15 mots au plus, une idée par phrase, la plus importante d'abord. Un écran tient en un titre, une à deux phrases, un bouton.
- **Le titre dit ce qui se passe ou ce qu'on attend de l'utilisateur** (« Le panier est rempli », « Choisissez votre magasin »), pas le nom de la fonction (« Retour du drive », « Résultat de l'essai »).
- **Un bouton est un verbe qui dit ce qu'il fait** : « Ouvrir le drive », « Enregistrer mon magasin ». Pas de « OK », « Valider », « Continuer » seuls quand le verbe précis existe.
- **Les nombres s'écrivent en chiffres** et s'accordent (« 1 produit », « 3 produits »). Jamais de compteur à zéro dans un récapitulatif : on n'affiche que ce qui existe.
- **Un mot pour une chose** : voir le glossaire. Une même idée ne change pas de nom d'un écran à l'autre, ni entre l'application, l'agent et l'aide.
- **Pas d'anglicisme ni de jargon** (« drive » est un mot d'usage : accepté). Pas de MAJUSCULES, pas de point d'exclamation, pas d'humour dans une erreur.

## 4. Les erreurs en trois phrases

Format : **ce qui s'est passé** (en mots du quotidien), **ce qui est sauf** (le panier, la liste, les choix : rassurer), **ce que l'on peut faire** (une action, avec son bouton).

| Mal | Bien |
|---|---|
| « Réponse incomprise. L'application a renvoyé une réponse que Repas ne comprend pas. » | « Le drive n'a pas répondu comme prévu. Rien n'a été perdu. Mettez l'application à jour, puis réessayez. » [Mettre à jour l'application] |
| « Passage arrêté. » | « L'envoi s'est arrêté. Ce qui est déjà au panier y reste. » [Reprendre l'envoi] |
| « Code : CONTRACT_CHANGED » | (le code n'apparaît que dans « Détails ») |

Quand l'utilisateur ne peut rien faire (Leclerc a changé son site), le texte le dit franchement et propose le repli : « Chercher à la main sur Leclerc ».

## 5. Glossaire : le mot à employer et les mots interdits à l'écran

| Notion | À écrire | À ne pas écrire à l'écran |
|---|---|---|
| L'opération complète | **envoi** (« Envoyer au drive », « Reprendre l'envoi », « liste envoyée ») | passage, plan, plan d'envoi, run, remplissage du panier (sauf « Le panier est rempli » en résultat) |
| La ligne de la liste (farine) | **article** | ligne, ingrédient (sauf dans les recettes) |
| Ce qu'on achète chez Leclerc (Farine de blé 1 kg) | **produit** | référence, fiche, SKU |
| Le panier du site de Leclerc | **panier** (« votre panier Leclerc ») | caddie, cart |
| Déjà chez soi, toujours | **J'en ai toujours** | Déjà à la maison, Vous en avez déjà, HAVE_AT_HOME |
| Pas pour cet envoi seulement | **Pas cette fois** | Passer cette fois, Ignoré, Passés |
| Produit retenu pour un article | **produit habituel** ; son remplaçant : **produit de remplacement** | préféré, primaire, secours, fallback |
| Garder un produit choisi pour les envois suivants | **Toujours prendre celui-ci** / **Cette fois seulement** (la question : « Toujours prendre ce produit ? ») | Mon nouveau produit habituel, préféré, secours, une seule fois |
| Passer plusieurs articles d'un coup | **Passer les n suivants** (n compte l'article affiché), puis **Revenir au choix** | Tout passer, Ignorer le reste |
| Produit exclu | **Ne plus proposer** | Refusé, rejeté |
| Le fait que Repas se souvient | **Repas s'en souvient** | mémorisé, persisté, enregistré dans les préférences |
| Écran de fin d'un envoi | **Récapitulatif** | Résumé, Retour du drive, Résultat |
| Le magasin Leclerc de l'utilisateur | **votre magasin** | point de retrait, drive (pour un magasin), id |
| Mettre l'appli à jour | **Mettre à jour l'application** | Installer l'application (quand elle l'est déjà) |
| L'écran du profil et des réglages | **Moi** : le nom de l'onglet, repris tel quel dans le titre de la page et dans tous les textes qui y renvoient (aujourd'hui le titre de la page dit « Mon compte » : à aligner, DRV-621) | Mon compte |
| Repli sans automatisation | **Chercher à la main** | mode assisté |
| Si Leclerc change son site | « Leclerc a changé son site, l'envoi automatique est arrêté » | contrat modifié, CONTRACT_CHANGED |

Tout nouveau mot qui ne figure pas dans ce tableau est soumis au même test : *un proche le dirait-il à voix haute ?* Sinon on cherche un autre mot, et on l'ajoute ici.

## 6. Revue d'un ticket ou d'une PR qui touche l'interface

Une PR qui change un écran, un texte ou un enchaînement répond, dans sa description (section « À relire »), à ces questions :

1. Quel est le parcours heureux, et **combien de touches** (avant, après) ?
2. Y a-t-il **une seule décision** par écran, un seul bouton principal ?
3. Tous les textes respectent-ils le **vouvoiement**, les **phrases courtes** et le **glossaire** (aucun mot interdit, aucun code visible) ?
4. Chaque erreur suit-elle le format **en trois phrases** avec une action ?
5. Le texte tient-il à 360 px de large, sans défilement horizontal, et reste-t-il lisible dans la page de Leclerc en mode ordinateur (agent) ?
6. La capture de l'écran a-t-elle été **lue** (test visuel Chromium pour le web ; émulateur pour l'agent) ?

Un ticket qui ajoute un écran ajoute aussi, dans sa section « Tests exigés », un test qui échoue si un mot interdit du glossaire réapparaît (voir DRV-621).

## 7. Mesures et essais

- **Touches du parcours heureux** (premier envoi, deuxième envoi) : relevées à chaque recette (`recette.md`) et reportées dans `audit-simplicite.md`.
- **Essai d'un proche** : avant d'ouvrir l'application à d'autres personnes, un proche fait un envoi seul, sans explication ; ce qui le fait hésiter est un ticket (liste à tenir dans `audit-simplicite.md`).
- **Retours bruts** de l'utilisateur : ses phrases mot pour mot (« Passage arrêté, je dois faire quoi ? ») valent critère d'acceptation.

## 8. Rôle du PO

Le PO décide et note la décision (ici, dans ce fichier ou dans `audit-simplicite.md`) plutôt que de poser une question dont la réponse a un défaut raisonnable ; il ne demande à l'utilisateur que ce qui change le produit de façon difficile à défaire (publication, données, argent, vie privée). Toute décision de ton, de vocabulaire ou de parcours est réversible : elle se change dans le glossaire, puis dans un ticket de balayage.
