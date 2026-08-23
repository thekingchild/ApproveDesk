<?php

namespace App\Services\Integrations;

use App\Contracts\Integrations\Connector;
use App\Enums\ConnectorKey;
use InvalidArgumentException;
use Traversable;

final class ConnectorRegistry
{
    /** @var array<string, Connector> */
    private array $connectors = [];

    /**
     * @param  iterable<Connector>  $connectors
     */
    public function __construct(iterable $connectors)
    {
        foreach ($connectors instanceof Traversable ? iterator_to_array($connectors) : $connectors as $connector) {
            $this->connectors[$connector->key()->value] = $connector;
        }
    }

    public function get(ConnectorKey $key): Connector
    {
        return $this->connectors[$key->value]
            ?? throw new InvalidArgumentException('Unknown connector: '.$key->value);
    }

    /** @return list<Connector> */
    public function all(): array
    {
        return array_values($this->connectors);
    }
}
