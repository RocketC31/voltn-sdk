<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

/**
 * Which versions of a file `DELETE /file/(fileId)` affects
 * (`versions` parameter).
 */
enum FileVersionScope: string
{
    /** The file and all of its versions (platform default). */
    case All = 'all';

    /** Only the current version. */
    case None = 'none';

    /** The current version and older ones, keeping newer ones if any. */
    case Previous = 'previous';
}
