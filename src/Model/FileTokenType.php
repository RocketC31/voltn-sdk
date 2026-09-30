<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Model;

/**
 * Purposes a file token can be generated for via
 * `POST /token/file/(fileId)/(type)`.
 */
enum FileTokenType: string
{
    case Preview = 'preview';
    case Edit = 'edit';
    case Download = 'download';
}
