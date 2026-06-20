<?php

declare(strict_types=1);

namespace RoundlyConsulting\Geolocation\MaxMind;

use RoundlyConsulting\Geolocation\Exceptions\InvalidDatabaseException;

/**
 * Decodes the data section of a MaxMind DB binary buffer.
 *
 * The data section is a sequence of self-describing values introduced by a control
 * byte. The top three bits of the control byte select the type; the bottom five bits
 * carry the payload size (with documented 29/30/31 extension rules). Extended types
 * use type 0 followed by a byte that adds 7 to the type number. Pointers carry a value
 * (a byte offset into the data section) rather than payload bytes.
 */
final class Decoder
{
    private const TYPE_EXTENDED = 0;

    private const TYPE_POINTER = 1;

    private const TYPE_UTF8_STRING = 2;

    private const TYPE_DOUBLE = 3;

    private const TYPE_BYTES = 4;

    private const TYPE_UINT16 = 5;

    private const TYPE_UINT32 = 6;

    private const TYPE_MAP = 7;

    private const TYPE_INT32 = 8;

    private const TYPE_UINT64 = 9;

    private const TYPE_UINT128 = 10;

    private const TYPE_ARRAY = 11;

    private const TYPE_BOOLEAN = 14;

    private const TYPE_FLOAT = 15;

    public function __construct(
        private readonly string $buffer,
        private readonly int $pointerBase = 0,
    ) {}

    /**
     * Decode the value at $offset.
     *
     * @return array{0: mixed, 1: int} The decoded value and the offset just past it.
     */
    public function decode(int $offset): array
    {
        $control = $this->byteAt($offset);
        $offset++;

        $type = $control >> 5;

        if ($type === self::TYPE_EXTENDED) {
            $type = $this->byteAt($offset) + 7;
            $offset++;
        }

        if ($type === self::TYPE_POINTER) {
            return $this->decodePointer($control, $offset);
        }

        [$size, $offset] = $this->sizeFromControl($control, $offset);

        return $this->decodeByType($type, $size, $offset);
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    private function decodeByType(int $type, int $size, int $offset): array
    {
        return match ($type) {
            self::TYPE_MAP => $this->decodeMap($size, $offset),
            self::TYPE_ARRAY => $this->decodeArray($size, $offset),
            self::TYPE_UTF8_STRING => [$this->bytes($offset, $size), $offset + $size],
            self::TYPE_BYTES => [$this->bytes($offset, $size), $offset + $size],
            self::TYPE_UINT16, self::TYPE_UINT32, self::TYPE_UINT64, self::TYPE_UINT128 => [
                $this->decodeUnsigned($offset, $size), $offset + $size,
            ],
            self::TYPE_INT32 => [$this->decodeInt32($offset, $size), $offset + $size],
            self::TYPE_DOUBLE => [$this->decodeDouble($offset, $size), $offset + $size],
            self::TYPE_FLOAT => [$this->decodeFloat($offset, $size), $offset + $size],
            self::TYPE_BOOLEAN => [$size !== 0, $offset],
            default => throw InvalidDatabaseException::malformed("unknown data type [{$type}]"),
        };
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function decodeMap(int $size, int $offset): array
    {
        $map = [];

        for ($i = 0; $i < $size; $i++) {
            [$key, $offset] = $this->decode($offset);
            [$value, $offset] = $this->decode($offset);
            $map[(string) $key] = $value;
        }

        return [$map, $offset];
    }

    /**
     * @return array{0: list<mixed>, 1: int}
     */
    private function decodeArray(int $size, int $offset): array
    {
        $array = [];

        for ($i = 0; $i < $size; $i++) {
            [$value, $offset] = $this->decode($offset);
            $array[] = $value;
        }

        return [$array, $offset];
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    private function decodePointer(int $control, int $offset): array
    {
        $size = ($control >> 3) & 0x03;
        $value = $control & 0x07;

        $pointer = match ($size) {
            0 => ($value << 8) | $this->byteAt($offset),
            1 => ($value << 16) | $this->uint($offset, 2) + 2048,
            2 => ($value << 24) | $this->uint($offset, 3) + 526336,
            default => $this->uint($offset, 4),
        };

        $bytesRead = $size + 1;

        [$decoded] = $this->decode($this->pointerBase + $pointer);

        return [$decoded, $offset + $bytesRead];
    }

    /**
     * @return array{0: int, 1: int} The payload size and the offset just past the size bytes.
     */
    private function sizeFromControl(int $control, int $offset): array
    {
        $size = $control & 0x1F;

        if ($size < 29) {
            return [$size, $offset];
        }

        if ($size === 29) {
            return [29 + $this->byteAt($offset), $offset + 1];
        }

        if ($size === 30) {
            return [285 + $this->uint($offset, 2), $offset + 2];
        }

        return [65821 + $this->uint($offset, 3), $offset + 3];
    }

    private function decodeUnsigned(int $offset, int $size): int|string
    {
        if ($size === 0) {
            return 0;
        }

        // PHP ints are 64-bit signed; widths up to 7 bytes are always safe, and an
        // 8-byte value with the top bit clear fits too. Anything wider is returned as a
        // hex string so no precision is lost (uint64 with top bit set / uint128).
        if ($size <= 7) {
            return $this->uint($offset, $size);
        }

        $hex = bin2hex($this->bytes($offset, $size));
        $trimmed = ltrim($hex, '0');

        if ($trimmed === '') {
            return 0;
        }

        if ($size === 8 && strlen($trimmed) <= 15) {
            return (int) hexdec($trimmed);
        }

        return '0x'.$trimmed;
    }

    private function decodeInt32(int $offset, int $size): int
    {
        if ($size === 0) {
            return 0;
        }

        $value = $this->uint($offset, $size);

        $signBit = 1 << ($size * 8 - 1);

        if (($value & $signBit) !== 0) {
            return $value - (1 << ($size * 8));
        }

        return $value;
    }

    private function decodeDouble(int $offset, int $size): float
    {
        if ($size !== 8) {
            throw InvalidDatabaseException::malformed("double of size [{$size}]");
        }

        /** @var array{1: float} $unpacked */
        $unpacked = unpack('E', $this->bytes($offset, 8));

        return $unpacked[1];
    }

    private function decodeFloat(int $offset, int $size): float
    {
        if ($size !== 4) {
            throw InvalidDatabaseException::malformed("float of size [{$size}]");
        }

        /** @var array{1: float} $unpacked */
        $unpacked = unpack('G', $this->bytes($offset, 4));

        return $unpacked[1];
    }

    private function uint(int $offset, int $size): int
    {
        $value = 0;

        for ($i = 0; $i < $size; $i++) {
            $value = ($value << 8) | $this->byteAt($offset + $i);
        }

        return $value;
    }

    private function byteAt(int $offset): int
    {
        if ($offset < 0 || $offset >= strlen($this->buffer)) {
            throw InvalidDatabaseException::truncated();
        }

        return ord($this->buffer[$offset]);
    }

    private function bytes(int $offset, int $size): string
    {
        if ($size === 0) {
            return '';
        }

        if ($offset < 0 || $offset + $size > strlen($this->buffer)) {
            throw InvalidDatabaseException::truncated();
        }

        return substr($this->buffer, $offset, $size);
    }
}
