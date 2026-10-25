# VOX-602 — Recette sur l'Echo

- **Dépôt** : — (humain, guidé par l'assistant)
- **Jalon** : M6
- **Taille** : M
- **Dépend de** : VOX-002, VOX-204, VOX-401, VOX-601
- **Faisable sans téléphone** : non
- **Statut** : à faire

## Scénarios (un par message, l'utilisateur dit ce qu'il entend et voit)

1. **Liaison** depuis l'application Alexa (téléphone avec l'application Repas, puis sans) : la page d'autorisation s'ouvre où ? l'application Alexa détecte-t-elle la liaison ? (VOX-501)
2. « Alexa, demande à *mes courses* d'ajouter du pain » avec une liste en cours : la ligne arrive dans l'application.
3. Même phrase sans liste active, puis avec seulement une liste envoyée au drive : liste créée, phrase d'Alexa.
4. Séance : « ouvre *mes courses* », trois produits à la suite, « non, merci ».
5. « tomate », « tomates pelées », « farine », « lait », « pâtes » : le bon ingrédient ? Noter les erreurs de reconnaissance (matière pour VOX-301).
6. Un produit inconnu ; une phrase sans sens ; Alexa sans liaison.
7. « Délier Alexa » dans « Moi » : Alexa le sait et demande de relier.
8. Un proche (compte Amazon différent) : ce que l'on peut faire (D4).

## Critères d'acceptation

- Les scénarios 1 à 4 passent ; les écarts du 5 sont notés avec les phrases exactes ; la recette est signée par l'utilisateur (date) avant d'écrire à un proche.
