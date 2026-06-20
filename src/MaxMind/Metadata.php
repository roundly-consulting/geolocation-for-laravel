<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\MaxMind;

use RoundlyConsulting\Geolocation\Exceptions\InvalidDatabaseException;

/**
 * Parsed MaxMind DB metadata describing the search tree layout.
 */
final readonly class Metadata
{
    public int $nodeByteSize;

    public int $searchTreeSize;

    public function __construct(
        public int $nodeCount,
        public int $recordSize,
        public int $ipVersion,
        public string $databaseType,
    ) {
        if (! in_array($recordSize, [24, 28, 32], true)) {
            throw InvalidDatabaseException::malformed("unsupported record size [{$recordSize}]");
        }

        $this->nodeByteSize = intdiv($recordSize * 2, 8);
        $this->searchTreeSize = $nodeCount * $this->nodeByteSize;
    }

    /**
     * Build metadata from the decoded metadata map.
     *
     * @param  array<string, mixed>  $map
     */
    public static function fromArray(array $map): self
    {
        foreach (['node_count', 'record_size', 'ip_version', 'database_type'] as $key) {
            if (! array_key_exists($key, $map)) {
                throw InvalidDatabaseException::malformed("metadata is missing [{$key}]");
            }
        }

        return new self(
            nodeCount: (int) $map['node_count'],
            recordSize: (int) $map['record_size'],
            ipVersion: (int) $map['ip_version'],
            databaseType: (string) $map['database_type'],
        );
    }

    /**
     * Byte offset where the data section begins (16-byte separator follows the tree).
     */
    public function dataSectionStart(): int
    {
        return $this->searchTreeSize + 16;
    }
}
