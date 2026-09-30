<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Auth;

/**
 * OAuth2 grant types supported by the Voltn `/oauth2/token` endpoint.
 */
enum GrantType: string
{
    case AuthorizationCode = 'authorization_code';
    case RefreshToken = 'refresh_token';
    case ClientCredentials = 'client_credentials';
}
