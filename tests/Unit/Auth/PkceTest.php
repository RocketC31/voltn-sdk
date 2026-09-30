<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Tests\Unit\Auth;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RocketC31\Voltn\Auth\Pkce;

final class PkceTest extends TestCase
{
    /**
     * RFC 7636 Appendix B worked example.
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7636#appendix-B
     */
    public function testChallengeFromVerifierMatchesRfc7636AppendixB(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        self::assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            Pkce::challengeFromVerifier($verifier),
        );
    }

    public function testGenerateProducesVerifierWithinRfcLengthBounds(): void
    {
        $pair = Pkce::generate();

        self::assertGreaterThanOrEqual(43, strlen($pair->codeVerifier));
        self::assertLessThanOrEqual(128, strlen($pair->codeVerifier));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]+$/', $pair->codeVerifier);
        self::assertSame('S256', $pair->codeChallengeMethod);
    }

    public function testGenerateDerivesChallengeFromVerifier(): void
    {
        $pair = Pkce::generate();

        self::assertSame(Pkce::challengeFromVerifier($pair->codeVerifier), $pair->codeChallenge);
    }

    public function testGenerateProducesUrlSafeChallengeWithoutPadding(): void
    {
        $pair = Pkce::generate();

        self::assertStringNotContainsString('=', $pair->codeChallenge);
        self::assertStringNotContainsString('+', $pair->codeChallenge);
        self::assertStringNotContainsString('/', $pair->codeChallenge);
    }

    public function testGenerateProducesDifferentVerifiersEachTime(): void
    {
        $first = Pkce::generate();
        $second = Pkce::generate();

        self::assertNotSame($first->codeVerifier, $second->codeVerifier);
    }

    public function testGenerateRejectsTooShortLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Pkce::generate(42);
    }

    public function testGenerateRejectsTooLongLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Pkce::generate(129);
    }

    public function testGenerateAcceptsBoundaryLengths(): void
    {
        self::assertSame(43, strlen(Pkce::generateCodeVerifier(43)));
        self::assertSame(128, strlen(Pkce::generateCodeVerifier(128)));
    }
}
