<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

/**
 * Kind of item for `GET /objectpath` (`object_type` parameter).
 */
enum ObjectType: string
{
    case File = 'file';
    case Folder = 'folder';
}
