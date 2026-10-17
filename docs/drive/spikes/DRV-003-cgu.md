# DRV-003 — Rapport : CGU et CGV de Leclerc

Lecture faite par l'assistant le 2026-10-17 à partir du texte que l'utilisateur a copié depuis `https://www.e.leclerc/e/legal` : « Conditions Générales d'Utilisation du site E.Leclerc — Mise à jour : 20/08/2026 » (la lecture automatique des pages légales est bloquée par Leclerc). Ce n'est **pas un avis juridique**.

- Date de lecture : 2026-10-17
- Documents lus : CGU du site E.Leclerc, version du 20/08/2026 (Parties I à V).
- **Documents non lus** : les CGU et CGV propres au drive (`leclercdrive.fr`, lien en pied de page), qui peuvent ajouter leurs propres règles. **À fournir** pour clore ce ticket.
- Portée du texte lu : la Partie III (« relatives au Site ») définit le « Site » comme `e.leclerc`. La Partie II (Compte E.Leclerc) s'applique, elle, à tous les sites et applications qui partagent le compte, dont `drive.leclerc` et l'application Leclerc DRIVE.

## Mots cherchés

« robot », « automatisé », « automatique », « extraction », « script », « logiciel », « outil », « moissonnage », « aspiration », « copie », « utilisation à des fins commerciales », « identifiants », « mot de passe ». Aucun de « robot », « script », « moissonnage », « aspiration » n'apparaît. « Automatisé » n'apparaît que dans « système de traitement automatisé de données ». « Outils » n'apparaît que dans « outils mis à disposition sur le Site ».

## Constats

| Sujet | Article / titre | Citation courte | Interprétation |
|---|---|---|---|
| Accès ou extraction automatisés interdits ? | Partie III, 3.1 | L'utilisateur s'interdit « de porter atteinte au système de traitement automatisé de données lié au Site, et notamment d'y accéder, de s'y maintenir frauduleusement, d'en entraver ou de fausser le fonctionnement » et « d'entraver l'accès et le bon fonctionnement du Site » | Pas d'interdiction explicite des robots ni des scripts. Ces clauses visent l'intrusion frauduleuse et le fait de gêner le service. Un script qui agit dans la session de l'utilisateur, en série, à faible cadence, ne trompe ni ne gêne le service : plutôt compatible, mais la limite dépend de l'appréciation de Leclerc. |
| Extraction du contenu (base de données) | Partie III, 2.2 | L'utilisateur peut « extraire et/ou réutiliser une partie du contenu du Site à des fins exclusivement personnelles de consultation » si cela « ne porte pas sur une partie (…) substantielle » et n'a pas de finalité professionnelle, économique, commerciale ni de « communication au public ». Interdit : « (iii) l'extraction et/ou la réutilisation répétée et systématique du contenu (…) non substantiel (…) lorsque ces opérations excèdent manifestement les conditions d'utilisation normale » | C'est la clause la plus sensible. Chercher quelques produits pour sa propre liste de courses est un usage personnel et non substantiel. En revanche, un moissonnage du catalogue (hors projet), ou la mise à disposition large des produits mémorisés, sortirait du cadre. |
| Reproduction du catalogue (noms, prix, photos) | Partie III, 2.1 | La licence ne permet que « de représenter les pages du Site sur son écran » ; l'utilisateur « s'interdit de copier ou télécharger tout ou partie de ses composants » ; reproduction « à des fins exclusives d'information pour un usage strictement personnel » | À respecter : Repas ne mémorise que l'identifiant, le libellé, le contenu net et (décision D22 de l'utilisateur) le prix d'un produit choisi, pas de photos, et ne recopie pas le catalogue. |
| Usage d'outils tiers sur son propre compte | — | Aucune clause | Silence du texte lu. Le site drive peut en avoir une (à lire). |
| Partage d'identifiants avec un tiers | Partie II, 2.4 | L'utilisateur s'engage « à conserver ses identifiants secrets et à ne les divulguer à aucun tiers » | Conforme au projet : Repas ne voit, ne reçoit et ne stocke jamais d'identifiant ni de cookie (principe de l'ADR 0022). |
| Sanctions (suspension du compte, blocage) | Partie III, 3.1 ; Partie II, 7.3 | En cas de manquement, l'Éditeur peut « supprimer l'accès de l'Utilisateur à son Compte E.Leclerc, de façon temporaire ou définitive, sans (…) notification préalable » ; en cas d'utilisation « frauduleuse ou abusive », suppression du compte et « annulation de ses Tickets E.Leclerc » | **Le risque concret est pour le Compte E.Leclerc de l'utilisateur** (un seul compte pour le drive, le site et la carte de fidélité) et sa cagnotte de Tickets. Dommages et intérêts possibles en théorie. |

## Conclusion (pour DRV-005)

- Clause interdisant explicitement les outils tiers : **non** (pour les CGU du site E.Leclerc) ; les CGU/CGV du drive restent à lire.
- Point ambigu : la clause 2.2 (iii) sur l'extraction répétée et systématique, et celle sur « entraver le bon fonctionnement du Site ».
- Risque retenu pour un usage personnel et familial : **faible à moyen** ; la conséquence possible est la suspension du compte E.Leclerc de la personne concernée. À accepter explicitement par chaque proche (note vie privée, DRV-504).
- Action : **continuer, avec garde-fous** : requêtes en série, cadence lente, arrêt après deux refus ; seulement à la demande et pour les ingrédients de la liste ; mémoriser le strict nécessaire (identifiant, libellé, contenu net, prix daté selon D22) ; ne jamais exposer de liste du catalogue ni de recherche libre dans l'API de Repas ; ne pas conserver de photos ; l'interrupteur `DRIVE_ENABLED` ; prévenir les proches du risque ; mode assisté (liens de recherche) en repli permanent. Éventuel contact avec Leclerc (service client) pour demander une tolérance : facultatif.

## Décision de l'utilisateur (2026-10-17)

L'utilisateur considère les CGU comme acceptées et clôt le sujet (D23) : il ne demande pas d'avis juridique. Il décide de **mémoriser le prix** des produits choisis pour pouvoir donner plus tard une estimation (D22) et de continuer à respecter le site : requêtes lentes, en série, sans surcharge. Les CGU et CGV propres au drive n'ont pas été fournies ; la lecture ci-dessus reste à titre d'information.
