<?php

namespace Repas\Repas\Application\Command\HandOverCatalog;


enum HandOverOutcome: string
{
    /** Les anciens communs sont passés au propriétaire. */
    case HANDED_OVER = 'HANDED_OVER';
    /** Il n'y avait plus rien à passer : la passation était déjà faite, rien n'est écrit. */
    case ALREADY_DONE = 'ALREADY_DONE';
}
