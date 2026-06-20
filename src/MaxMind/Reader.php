<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\MaxMind;

use RoundlyConsulting\Geolocation\Exceptions\DatabaseNotFoundException;
use RoundlyConsulting\Geolocation\Exceptions\InvalidDatabaseException;

/**
 * A native reader for the MaxMind DB (.mmdb) binary file format.
 *
 * The whole file is read into memory once and the search tree is walked bit-by-bit per
 * lookup. No third-party MaxMind library is used — the binary format is parsed directly.
 */
final class Reader
{
    private const METADATA_MARKER = "\xab\xcd\xefMaxMind.com";

    private const DATA_SECTION_SEPARATOR = 16;

    private readonly string $buffer;

    private readonly Metadata $metadata;

    private readonly Decoder $treeDecoder;

    public function __construct(string $path)
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw DatabaseNotFoundException::forPath($path === '' ? null : $path);
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw DatabaseNotFoundException::forPath($path);
        }

        $this->buffer = $contents;
        $this->metadata = $this->readMetadata();
        $this->treeDecoder = new Decoder($this->buffer, $this->metadata->dataSectionStart());
    }

    public function metadata(): Metadata
    {
        return $this->metadata;
    }

    /**
     * Look up an IP address and return its decoded record, or null when absent.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $ipAddress): ?array
    {
        $packed = @inet_pton($ipAddress);

        if ($packed === false) {
            return null;
        }

        $rawBits = unpack('C*', $packed);

        if ($rawBits === false) {
            return null;
        }

        /** @var list<int> $bytes */
        $bytes = array_values($rawBits);

        $isIpv4 = strlen($packed) === 4;

        if (! $isIpv4 && $this->metadata->ipVersion === 4) {
            return null;
        }

        $pointer = $this->findAddressPointer($bytes, $isIpv4);

        if ($pointer === null) {
            return null;
        }

        $record = $this->resolveDataPointer($pointer);

        return is_array($record) ? $record : null;
    }

    /**
     * Walk the binary search tree and return the data-section pointer for the address,
     * or null when the address resolves to the "no data" record.
     *
     * @param  list<int>  $bytes
     */
    private function findAddressPointer(array $bytes, bool $isIpv4): ?int
    {
        $node = 0;

        // An IPv4 address looked up in an IPv6 database starts 96 bits into the tree.
        if ($isIpv4 && $this->metadata->ipVersion === 6) {
            $node = $this->ipv4StartNode();
        }

        $bitCount = count($bytes) * 8;

        for ($i = 0; $i < $bitCount; $i++) {
            if ($node >= $this->metadata->nodeCount) {
                break;
            }

            $bit = ($bytes[$i >> 3] >> (7 - ($i % 8))) & 1;
            $node = $this->readNode($node, $bit);
        }

        if ($node === $this->metadata->nodeCount) {
            return null;
        }

        if ($node > $this->metadata->nodeCount) {
            return $node;
        }

        throw InvalidDatabaseException::malformed('search tree walked past the data section');
    }

    private function ipv4StartNode(): int
    {
        $node = 0;

        for ($i = 0; $i < 96; $i++) {
            if ($node >= $this->metadata->nodeCount) {
                break;
            }

            $node = $this->readNode($node, 0);
        }

        return $node;
    }

    private function readNode(int $node, int $bit): int
    {
        $baseOffset = $node * $this->metadata->nodeByteSize;

        return match ($this->metadata->recordSize) {
            24 => $this->readNode24($baseOffset, $bit),
            28 => $this->readNode28($baseOffset, $bit),
            default => $this->readNode32($baseOffset, $bit),
        };
    }

    private function readNode24(int $baseOffset, int $bit): int
    {
        $offset = $bit === 0 ? $baseOffset : $baseOffset + 3;

        return $this->uintAt($offset, 3);
    }

    private function readNode28(int $baseOffset, int $bit): int
    {
        $middle = $this->byteAt($baseOffset + 3);

        if ($bit === 0) {
            return (($middle >> 4) << 24) | $this->uintAt($baseOffset, 3);
        }

        return (($middle & 0x0F) << 24) | $this->uintAt($baseOffset + 4, 3);
    }

    private function readNode32(int $baseOffset, int $bit): int
    {
        $offset = $bit === 0 ? $baseOffset : $baseOffset + 4;

        return $this->uintAt($offset, 4);
    }

    private function resolveDataPointer(int $pointer): mixed
    {
        $offset = $pointer - $this->metadata->nodeCount - self::DATA_SECTION_SEPARATOR;

        [$value] = $this->treeDecoder->decode($this->metadata->dataSectionStart() + $offset);

        return $value;
    }

    private function readMetadata(): Metadata
    {
        $markerPosition = strrpos($this->buffer, self::METADATA_MARKER);

        if ($markerPosition === false) {
            throw InvalidDatabaseException::missingMetadata();
        }

        $metadataStart = $markerPosition + strlen(self::METADATA_MARKER);

        $metadataBuffer = substr($this->buffer, $metadataStart);
        $decoder = new Decoder($metadataBuffer);

        [$map] = $decoder->decode(0);

        if (! is_array($map)) {
            throw InvalidDatabaseException::malformed('metadata is not a map');
        }

        /** @var array<string, mixed> $map */
        return Metadata::fromArray($map);
    }

    private function byteAt(int $offset): int
    {
        if ($offset < 0 || $offset >= strlen($this->buffer)) {
            throw InvalidDatabaseException::truncated();
        }

        return ord($this->buffer[$offset]);
    }

    private function uintAt(int $offset, int $size): int
    {
        $value = 0;

        for ($i = 0; $i < $size; $i++) {
            $value = ($value << 8) | $this->byteAt($offset + $i);
        }

        return $value;
    }
}
