# Recette : des ingrédients propres à chaque compte (ING-010)

Procédure à dérouler **par le propriétaire du catalogue, dans le navigateur ou l'application, avec l'assistant** qui le guide **une étape à la fois** (skill `/repas-ops`, [`CLAUDE.md`](../../CLAUDE.md)). Elle prouve, sur la vraie production, ce que les tests prouvent sur la base de test : plus aucun ingrédient n'est commun, chaque compte a les siens, et personne ne perd rien.

**État au 2026-10-24** : la passation des anciens communs au propriétaire est faite (2026-10-21), le back `v0.6.22` et le front `v0.5.27` sont en production, l'amorçage à l'activation est allumé (`INGREDIENT_SEEDING_ENABLED=true`), le code mort est retiré (ING-014) et le catalogue de la production n'a plus aucun ingrédient sans créateur (relevé en lecture seule). Cette recette est donc une **recette de réception** : elle se joue après coup, et chaque partie finit par un critère mesurable et une case à signer.

**Dépôt public** : ce document ne contient ni adresse, ni identifiant, ni nom d'ingrédient personnel, ni chiffre détaillé de la production. Les chiffres sont des ordres de grandeur ; les comptes sont désignés par leur rôle.

## Comment lire ce fichier

- Chaque étape a quatre lignes : **But**, **Geste** (ce que tu fais, exactement), **Attendu**, **Si ça diffère**. Fais une étape, regarde, puis seulement passe à la suivante.
- Les mots entre « » sont ceux de l'écran ; le sens compte plus que la lettre.
- Tu n'écris **jamais** un identifiant, un mot de passe ou un code reçu par e-mail dans le chat. Les relevés de l'assistant ne contiennent que des nombres de comptes.
- Les lectures sur le serveur sont faites par l'assistant, en lecture seule. **Ce qui écrit** (le script d'amorçage, un retour arrière) est lancé par toi, avec `!`, jamais par l'assistant.

## 1. L'état de la production (l'assistant lit)

### 1.1 Les bonnes versions tournent
**But** : savoir que le code testé est celui de la production.
**Geste** : l'assistant lit `state/ok-back` et `state/ok-front` sur le serveur et la santé du site.
**Attendu** : back `v0.6.22` ou plus récent, front `v0.5.27` ou plus récent, `/api/v1/health` répond `200`.
**Si ça diffère** : on ne continue pas ; on regarde la dernière release (`references/release.md`).

### 1.2 Aucun ingrédient sans créateur, tout compte actif a les siens
**But** : prouver « tout ingrédient est à quelqu'un » sur la vraie base.
**Geste** : l'assistant lance, en lecture seule, la vérification de santé de l'amorçage (`amorcer-ingredients.sh --verifier`) et compte les ingrédients sans créateur.
**Attendu** : « Rien à faire » ; **0** ingrédient sans créateur ; chaque compte actif a au moins ses ingrédients de base ; aucune ligne de recette ou de liste ne désigne l'ingrédient d'un autre compte.
**Si ça diffère** : noter le chiffre qui diffère (sans identifiant) et s'arrêter : c'est un défaut, pas une étape de recette.

### 1.3 Une sauvegarde récente existe
**But** : pouvoir revenir en arrière.
**Geste** : l'assistant liste les sauvegardes (`backups/`, noms seulement).
**Attendu** : une sauvegarde du jour ou de la veille, et celle d'avant la dernière release.
**Si ça diffère** : lancer une sauvegarde (`references/incident.md`) avant de continuer.

## 2. Ce que voit chaque compte

### 2.1 Le propriétaire retrouve tout
**But** : la passation n'a rien fait disparaître chez lui.
**Geste** : connecté en propriétaire, ouvre la liste des ingrédients, une de tes recettes, ta liste de courses active et, si tu l'utilises, ton plan d'envoi au drive.
**Attendu** : les mêmes ingrédients qu'avant (de l'ordre de 260), tes recettes, tes listes (dont celles déjà envoyées au drive), tes produits mémorisés : rien de manquant, aucun doublon d'ingrédient.
**Si ça diffère** : noter quoi, ne rien modifier, prévenir l'assistant.

### 2.2 Un compte déjà existant voit « ses » ingrédients
**But** : un compte migré a ses copies, sans doublon et sans perte.
**Geste** : connecté avec un compte de la famille (ou un compte d'essai ancien), ouvre ses recettes et sa liste.
**Attendu** : ses recettes et sa liste se lisent comme avant ; la recherche d'ingrédient ne montre **qu'un** « Tomate », le sien.
**Si ça diffère** : noter le rayon et le mot cherché, prévenir l'assistant.

## 3. Un compte d'essai, de l'inscription à la suppression

### 3.1 Inscription et confirmation
**But** : un nouveau compte reçoit ses ingrédients à l'activation, dans la même opération.
**Geste** : inscris un compte d'essai (une adresse que tu contrôles), confirme-la par le lien reçu, connecte-toi.
**Attendu** : à la première connexion, la liste des ingrédients contient les **ingrédients de base** (de l'ordre de 150), avec leurs conversions (par exemple une plaquette de beurre en grammes).
**Si ça diffère** : la liste est vide : vérifier l'interrupteur d'amorçage et la santé (étape 1.2).

### 3.2 Une recette et une liste
**But** : le compte d'essai peut travailler sans rien demander à personne.
**Geste** : crée une recette avec trois ingrédients, ajoute-la à une liste, ouvre la liste.
**Attendu** : les lignes se calculent, les unités se convertissent comme pour les autres comptes.
**Si ça diffère** : noter l'ingrédient et l'unité.

### 3.3 Suppression du compte par l'administrateur
**But** : tout ce que possède un compte disparaît avec lui.
**Geste** : en administrateur, supprime le compte d'essai (écran des utilisateurs).
**Attendu** : le compte disparaît ; ses ingrédients, ses recettes et ses listes aussi (l'assistant confirme en lecture seule : plus aucune ligne à son nom) ; les ingrédients des autres comptes sont intacts.
**Si ça diffère** : c'est un défaut de la purge (`UserOwnedDataCoverageTest`) : noter, ne pas recommencer.

## 4. Créer un ingrédient et le marquer « de base » (propriétaire seulement)

### 4.1 Un nouvel ingrédient n'arrive chez personne
**But** : un ingrédient créé reste à son créateur.
**Geste** : en propriétaire, crée un ingrédient (par exemple « Essai recette »). Connecte-toi avec un autre compte.
**Attendu** : l'autre compte **ne voit pas** « Essai recette ».
**Si ça diffère** : un ingrédient s'est propagé : défaut grave, arrêter et prévenir l'assistant.

### 4.2 Le marqueur « Ingrédient de base »
**But** : seul le propriétaire règle ce qui part aux nouveaux comptes.
**Geste** : sur la fiche de « Essai recette », active « Ingrédient de base » (propriétaire). Ouvre ensuite la fiche avec un autre compte administrateur, puis avec un compte ordinaire.
**Attendu** : l'interrupteur et sa pastille n'existent que pour le propriétaire ; les autres ne le voient pas ; les comptes **déjà actifs** ne reçoivent pas l'ingrédient.
**Si ça diffère** : noter qui voit le réglage.

### 4.3 Le prochain compte activé le reçoit
**But** : le marqueur sert aux nouveaux comptes, pas aux anciens.
**Geste** : inscris et confirme un second compte d'essai.
**Attendu** : il a « Essai recette » parmi ses ingrédients, avec les autres de base.
**Si ça diffère** : l'amorçage ne lit pas le marqueur : défaut.
*Ensuite* : retire « Essai recette » (supprime l'ingrédient en propriétaire, puis les deux comptes d'essai).

## 5. Les droits

### 5.1 L'administrateur ne modifie pas l'ingrédient d'un autre
**But** : être administrateur ne donne pas la main sur les ingrédients des autres.
**Geste** : avec un compte administrateur qui n'est pas le propriétaire, ouvre la fiche d'un ingrédient d'un autre compte (le lien d'un onglet ouvert plus tôt convient).
**Attendu** : la fiche est introuvable ; une modification ou une suppression est refusée.
**Si ça diffère** : un passe-droit est revenu : défaut grave (`NoSharedIngredientTest`).

## 6. Copier une recette, fusionner

### 6.1 Copier la recette d'un autre
**But** : la copie se branche sur tes ingrédients, sans doublon évident.
**Geste** : copie une recette d'un autre compte qui contient des ingrédients que tu as déjà.
**Attendu** : la copie utilise **tes** ingrédients quand ils existent (même nom) ; seuls les ingrédients que tu n'avais pas sont ajoutés.
**Si ça diffère** : noter le nom de l'ingrédient en double.

### 6.2 Les doublons possibles
**But** : retrouver les ingrédients presque identiques et les réunir.
**Geste** : ouvre l'écran « Doublons possibles » (lien visible seulement s'il y en a). Sur une paire, essaie « Pas le même », puis sur une autre « Fusionner ».
**Attendu** : « Pas le même » retire la paire pour de bon ; « Fusionner » affiche d'abord ce qui va se passer (« Fusionner « X » dans « Y » ? », avec les totaux), puis recopie tout sur l'ingrédient gardé : les recettes et les listes se lisent comme avant, sans l'ingrédient absorbé.
**Si ça diffère** : noter la paire (sans autre donnée) et ne pas insister.

## 7. Rejouer ne change rien

### 7.1 Un second lancement n'écrit rien
**But** : les outils d'exploitation sont rejouables sans danger.
**Geste** : lance `! ssh … 'bash -s -- --verifier' < .claude/skills/repas-ops/references/amorcer-ingredients.sh` (lecture seule).
**Attendu** : « Rien à faire », code de sortie 0, aucun compte à rattraper.
**Si ça diffère** : un compte actif n'est pas amorcé : le rattrapage (`--appliquer`) est une décision humaine, après relevé à blanc.

## Signature

**Relue par le propriétaire le 2026-10-24 : « tout me semble ok ».** Le tableau ci-dessous n'a pas été rempli ligne à ligne ; il reste le modèle à remplir si la recette est rejouée (nouvelle enseigne, nouvelle passation, reprise après incident).

La signature du propriétaire est demandée **avant** d'allumer ou de laisser allumé l'interrupteur d'amorçage pour de nouveaux comptes (aujourd'hui allumé : ce tableau fait foi de ce qui a été vu).

| Partie | Vu par | Date | Résultat (OK / écart noté) |
|---|---|---|---|
| 1. État de la production | assistant (lecture) | | |
| 2. Ce que voit chaque compte | propriétaire | | |
| 3. Compte d'essai, de l'inscription à la suppression | propriétaire | | |
| 4. Créer un ingrédient, le marquer « de base » | propriétaire | | |
| 5. Les droits | propriétaire | | |
| 6. Copier une recette, fusionner | propriétaire | | |
| 7. Rejouer ne change rien | assistant (lecture) | | |

Signature du propriétaire du catalogue : Kantin Charignon  Date : 24 octobre 2026
