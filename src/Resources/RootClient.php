<?php

declare(strict_types=1);

namespace RocketC31\Voltn\Resources;

use RocketC31\Voltn\Http\HttpTransport;
use RocketC31\Voltn\Model\RootSpace;

/**
 * `$client->roots()`: list the root spaces (top-level containers) the
 * authenticated principal has access to.
 */
final class RootClient
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * @return list<RootSpace>
     */
    public function list(): array
    {
        $data = $this->transport->sendForJson('GET', '/roots');

        $items = $data['roots'] ?? $data ?? [];

        if (!is_array($items)) {
            return [];
        }

        return array_map(
            static fn (mixed $item): RootSpace => RootSpace::fromArray(is_array($item) ? $item : []),
            array_values($items),
        );
    }

    /**
     * Fetch a single root space by its platform-defined type (e.g.
     * `personal`, `shared`).
     */
    public function get(string $type): RootSpace
    {
        $data = $this->transport->sendForJson('GET', sprintf('/root/%s', rawurlencode($type)));

        return RootSpace::fromArray($data ?? []);
    }
}
