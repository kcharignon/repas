# DRV-628 — Une interface sans avertissement ni explication superflue

- **Dépôt** : front (`drive-agent/src/ui/`, `src/features/drive/`, `src/i18n/fr.ts`, `android/`)
- **Jalon** : M6 Simplicité d'usage
- **Taille** : M
- **Dépend de** : DRV-626
- **Statut** : à faire (décision de l'utilisateur du 2026-10-19)
- **Lire d'abord** : [`principes-produit.md`](../principes-produit.md) (règle 10), [`audit-simplicite.md`](../audit-simplicite.md), [`note-vie-privee.md`](../note-vie-privee.md), skills `repas-po`, `repas-agent-dev`, `repas-front-dev`.

## Contexte

Décision de l'utilisateur : « Les messages qui font peur, je ne les veux que dans des CGU, nulle part ailleurs. Sur l'appli et dans le flux quotidien, je me fiche des avertissements. On veut une interface au service de l'utilisateur sur son action en cours (envoyer sa liste au drive pour remplir son panier). Enlève tout ce qui n'est pas nécessaire à la compréhension et tout ce qui alourdit la compréhension. Prévenir des risques à chaque flux alourdit clairement. »

Règle qui en sort : **une phrase ne reste à l'écran que si elle répond à « que se passe-t-il ? » ou à « que dois-je faire maintenant ? »**. Tout le reste est retiré de l'écran. Les risques et les engagements vivent dans une seule page, les conditions d'utilisation du drive (les « CGU »), que l'on ouvre seulement si on le veut.

## Portée

1. **Inventaire** de tous les textes du parcours (agent, application, web) à passer au crible, dans le code (`strings.ts`, `fr.ts`, textes Kotlin) : une ligne par texte retiré ou gardé, dans la description de la PR.
2. **Retirer de l'écran** :
   - les risques et mises en garde (compte Leclerc suspendu, anti-robot, « ne relancez pas en boucle », cadence, cagnotte) ;
   - les rassurances qui allongent (« Rien n'est commandé ni payé. », « Repas ne vide pas votre panier Leclerc. », « Le panier Leclerc n'est pas vidé par Repas. », « Rien ne part tant que vous n'avez pas touché « Commencer ». », « Repas ne voit jamais votre mot de passe ni votre panier Leclerc. ») ;
   - les réserves de fiabilité de l'estimation (« Estimation peu fiable… », « Repas ne devine jamais un prix », « D'après les prix vus le… » si le total tient seul) : le total estimé, le nombre d'articles estimés, et rien d'autre ;
   - les notes de vie privée dans les réglages du drive et dans l'aide du parcours : « Votre magasin » est le premier bloc, sans note au-dessus ;
   - les phrases d'explication de mécanisme (comment l'agent travaille, pourquoi une pause) ;
   - toute phrase qui répète le titre ou le bouton qui la suit.
3. **Garder** : le titre, la phrase de synthèse (« 6 articles : 2 sont déjà choisis, 4 sont à choisir sur Leclerc. »), le bouton principal, la conséquence du verrouillage dans la fenêtre « Envoyer la liste au drive ? » (« La liste ne sera plus modifiable. Vous pourrez la rouvrir. »), la consigne utile pendant l'envoi (« Gardez l'application ouverte. »), et les erreurs en trois phrases (ce qui s'est passé, ce qui est sauf, une action) sans ton alarmant.
4. **Une seule page de conditions d'utilisation du drive** : la « note complète » actuelle (vie privée, ce que Repas retient, droits, « Avant d'utiliser l'envoi : ce que vous devez savoir ») devient cette page, titrée « Conditions d'utilisation du drive ». Elle est atteinte par **un** lien discret (« Conditions d'utilisation ») au pied de la rubrique Drive de « Moi » et dans la page d'aide de l'application. Rien dans le flux ne l'impose : pas de case à cocher, pas de fenêtre. Le plus léger : renommer la section existante et poser le lien, sans nouvelle route sauf nécessité.
5. **Garde contre le retour** : un test (`voice-and-glossary.spec.ts` ou voisin) échoue si un texte hors de la page des conditions contient « risque », « suspen… », « attention », « jamais », « ne voit », « peu fiable », « ni commandé », « n'est pas vidé » ou « ne vide pas ». Les erreurs peuvent contenir « jamais » seulement si le test le justifie au cas par cas.
6. Agent : version **0.21.0**, empreinte recalculée (`android/agent.js.sha384`). Le protocole ne change pas.

## Hors portée

Le tutoiement (DRV-627 : les textes restent au vouvoiement en attendant, y compris ceux que ce ticket réécrit) ; les gestes de DRV-625 ; le back ; l'ajout d'une acceptation obligatoire des conditions.

## Critères d'acceptation

- Aucun écran du parcours (réglages du drive, envoi, agent, récapitulatif, liste envoyée) ne contient de mise en garde ni de rassurance : relecture des captures à 360 × 640.
- Chaque écran touché a moins de texte qu'avant (mots avant / après dans la PR).
- Le parcours heureux garde ses touches (aucune ajoutée).
- Les conditions d'utilisation du drive contiennent tout ce qui a été retiré de l'écran (rien ne se perd) et s'ouvrent depuis « Moi » et depuis l'aide.

## Tests exigés

Le test de garde du point 5 ; `simpleScreens.spec.ts` et les tests de l'agent mis à jour ; e2e de l'agent et de l'émulateur ; captures avant / après lues.

## Definition of Done

Celle du dépôt front ; touches et mots avant / après dans « À relire » ; agent 0.21.0 ; rien n'est publié.

## Pièges connus

- Ne pas confondre risque et erreur : « Leclerc a ralenti les demandes » est une erreur (ce qui s'est passé), pas une mise en garde ; elle reste, en trois phrases.
- Un texte supprimé de l'agent change l'empreinte : une seule version de l'agent par PR.
- `recette.md` cite plusieurs de ces textes : la mise à jour est faite par le PO après la fusion.
