<?php

namespace Repas\Repas\Infrastructure\Http;


/**
 * Les messages des bornes des corps de requête (`Assert\…`), en français : la réponse `422` les cite telle quelle. Ils reprennent les
 * bornes de `QuantityBounds` en toutes lettres (un message de validation ne formate pas `0.000001` lisiblement).
 */
final readonly class PayloadMessages
{
    public const string QUANTITY = 'La quantité doit être un nombre supérieur à 0 et au plus égal à 1 000 000.';
    public const string COEFFICIENT = 'Le coefficient doit être un nombre compris entre 0,000001 et 1 000 000.';
    public const string BASE_FACTOR = 'Le facteur de base doit être un nombre compris entre 0,000001 et 1 000 000.';
    public const string SERVING = 'Le nombre de personnes doit être un nombre entier de 1 à 100.';
    public const string SECRET = 'Le choix « recette secrète » doit être vrai ou faux.';
    public const string FAVORITE = 'Le choix « recette favorite » doit être vrai ou faux.';
    public const string TOO_MANY_ROWS = 'Une recette ne peut pas avoir plus de 100 ingrédients.';

    private function __construct()
    {
    }
}
