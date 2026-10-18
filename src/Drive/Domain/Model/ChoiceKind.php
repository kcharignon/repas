<?php

namespace Repas\Drive\Domain\Model;


enum ChoiceKind: string
{
    /** L'utilisateur achète cet ingrédient : produits acceptés (classés) et refusés. */
    case PRODUCTS = 'PRODUCTS';
    /** « J'en ai toujours » : la ligne est ignorée à l'envoi, aucun produit. */
    case HAVE_AT_HOME = 'HAVE_AT_HOME';
}
