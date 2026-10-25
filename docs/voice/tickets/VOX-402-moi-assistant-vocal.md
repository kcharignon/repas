# VOX-402 — Front : « Moi » — assistant vocal

- **Dépôt** : front
- **Jalon** : M4
- **Taille** : S
- **Dépend de** : VOX-202
- **Statut** : à faire
- **Lire d'abord** : `src/views/AccountView.vue` ; skill `repas-po` ; [`../alexa.md`](../alexa.md) § 2.

## Portée

Une section « Assistant vocal » dans l'écran « Moi », visible seulement quand la fonction est allumée (`voiceEnabled` dans `GET /api/v1/me`, clé toujours écrite, ajoutée côté back dans ce ticket jumeau) :

- liaison présente : « Alexa est liée. Dis : « Alexa, demande à *Jarvis* d'ajouter du pain ». » + bouton « Délier Alexa » (`DELETE /api/v1/voice/links/{id}`, confirmation en un geste) ;
- aucune liaison : « Pour lier Alexa, ouvre l'application Alexa, Skills, *Jarvis*. » ;
- une seule ligne d'aide : « Alexa ne voit pas ta liste de courses d'Amazon : dis bien le nom du skill. »

## Critères d'acceptation

- La section est absente fonction éteinte ; « Délier » retire la liaison et la section revient à l'état « aucune liaison » sans recharger ; textes dans `fr.ts` ; captures lues.

## Pièges connus

- Le nom du skill vient d'une constante de configuration (D1), pas d'un texte en dur dans trois endroits.
