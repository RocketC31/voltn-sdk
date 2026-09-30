<?php

declare(strict_types=1);

namespace RocketC31\Voltn;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RocketC31\Voltn\Auth\AccessToken;
use RocketC31\Voltn\Auth\AuthenticationMiddleware;
use RocketC31\Voltn\Auth\OAuth2Client;
use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Resources\FileClient;
use RocketC31\Voltn\Resources\FolderClient;
use RocketC31\Voltn\Resources\RootClient;
use RocketC31\Voltn\Resources\TokenClient;
use RocketC31\Voltn\Upload\TusUploadManager;

/**
 * Entry point to the Voltn SDK. Build one via {@see ClientBuilder}.
 *
 * ```php
 * $client = ClientBuilder::create('https://tenant.voltn.example')
 *     ->withClientCredentials('client-id', 'client-secret')
 *     ->build();
 *
 * $folder = $client->folders()->get(1, depth: 1);
 * ```
 */
final class Client
{
    private FolderClient $folders;

    private FileClient $files;

    private RootClient $roots;

    private TokenClient $tokens;

    private TusUploadManager $tus;

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly HttpTransport $tusTransport,
        private readonly AuthenticationMiddleware $authenticationMiddleware,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?OAuth2Client $oauth2Client = null,
    ) {
        $this->folders = new FolderClient($this->transport);
        $this->files = new FileClient($this->transport);
        $this->roots = new RootClient($this->transport);
        $this->tokens = new TokenClient($this->transport);
        $this->tus = new TusUploadManager($this->transport, $this->tusTransport, $this->requestFactory, $this->streamFactory);
    }

    public function folders(): FolderClient
    {
        return $this->folders;
    }

    public function files(): FileClient
    {
        return $this->files;
    }

    public function roots(): RootClient
    {
        return $this->roots;
    }

    public function tokens(): TokenClient
    {
        return $this->tokens;
    }

    public function tus(): TusUploadManager
    {
        return $this->tus;
    }

    /**
     * The OAuth2 client, if the SDK was configured with credentials or a
     * client ID that allow building one (all usage modes except the fully
     * manual "I already have a token" mode).
     */
    public function oauth2(): ?OAuth2Client
    {
        return $this->oauth2Client;
    }

    /**
     * The access token currently held by the SDK (may trigger a refresh on
     * the next request if it is close to expiring; this accessor itself
     * does not perform any I/O).
     */
    public function getCurrentAccessToken(): ?AccessToken
    {
        return $this->authenticationMiddleware->getCurrentToken();
    }

    public function getTransport(): HttpTransport
    {
        return $this->transport;
    }
}
