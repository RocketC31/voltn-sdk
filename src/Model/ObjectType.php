<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

/**
 * Kind of item for `GET /objectpath` (`object_type` parameter). The values
 * are the platform's own French labels, as documented.
 */
enum ObjectType: string
{
    case File = 'Fichier';
    case Folder = 'Dossier';
}
