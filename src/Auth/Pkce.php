<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Auth;

use InvalidArgumentException;

/**
 * Pure, dependency-free RFC 7636 PKCE helper. Voltn's documented
 * OAuth2 flow only supports the S256 challenge method, so that's all this
 * implements.
 */
final class Pkce
{
    private const MIN_VERIFIER_LENGTH = 43;
    private const MAX_VERIFIER_LENGTH = 128;

    /**
     * Unreserved characters per RFC 7636 section 4.1:
     * `[A-Z] / [a-z] / [0-9] / "-" / "." / "_" / "~"`.
     */
    private const UNRESERVED_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';

    private function __construct()
    {
    }

    /**
     * Generate a new random code verifier and its S256 code challenge.
     *
     * @param int $length length of the generated code verifier, must be
     *                    between 43 and 128 per RFC 7636
     */
    public static function generate(int $length = 128): PkcePair
    {
        $verifier = self::generateCodeVerifier($length);

        return new PkcePair(
            codeVerifier: $verifier,
            codeChallenge: self::challengeFromVerifier($verifier),
            codeChallengeMethod: 'S256',
        );
    }

    /**
     * Generate a random code verifier string of the given length.
     */
    public static function generateCodeVerifier(int $length = 128): string
    {
        if ($length < self::MIN_VERIFIER_LENGTH || $length > self::MAX_VERIFIER_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'PKCE code verifier length must be between %d and %d characters, got %d.',
                self::MIN_VERIFIER_LENGTH,
                self::MAX_VERIFIER_LENGTH,
                $length,
            ));
        }

        $alphabetSize = strlen(self::UNRESERVED_CHARS);
        $verifier = '';

        for ($i = 0; $i < $length; ++$i) {
            $verifier .= self::UNRESERVED_CHARS[random_int(0, $alphabetSize - 1)];
        }

        return $verifier;
    }

    /**
     * Derive the S256 code challenge for a given code verifier:
     * `BASE64URL-ENCODE(SHA256(ASCII(code_verifier)))`.
     */
    public static function challengeFromVerifier(string $codeVerifier): string
    {
        $hash = hash('sha256', $codeVerifier, true);

        return self::base64UrlEncode($hash);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
