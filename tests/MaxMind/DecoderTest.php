<?php

declare(strict_types=1);

use RoundlyConsulting\Geolocation\Exceptions\InvalidDatabaseException;
use RoundlyConsulting\Geolocation\MaxMind\Decoder;

/**
 * Helper: decode a crafted byte string and return just the value.
 */
function decodeBytes(string $bytes, int $pointerBase = 0): mixed
{
    [$value] = (new Decoder($bytes, $pointerBase))->decode(0);

    return $value;
}

it('decodes a short utf-8 string', function (): void {
    // control 0b010_00101 = type 2 (string), size 5
    expect(decodeBytes("\x45hello"))->toBe('hello');
});

it('decodes an empty string', function (): void {
    expect(decodeBytes("\x40"))->toBe('');
});

it('decodes a uint16', function (): void {
    // type 5, size 2
    expect(decodeBytes("\xa2\x01\xf4"))->toBe(500);
});

it('decodes a zero-size unsigned as zero', function (): void {
    expect(decodeBytes("\xa0"))->toBe(0);
});

it('decodes a uint32', function (): void {
    // type 6, size 4
    expect(decodeBytes("\xc4\x00\x01\x00\x00"))->toBe(65536);
});

it('decodes a uint64 within php int range', function (): void {
    // extended type: control 0b000_01000 -> ext byte 0x02 => type 9 (uint64), size 8
    expect(decodeBytes("\x08\x02\x00\x00\x00\x00\x00\x00\x00\x2a"))->toBe(42);
});

it('decodes a large uint64 as a hex string', function (): void {
    $value = decodeBytes("\x08\x02\xff\xff\xff\xff\xff\xff\xff\xff");

    expect($value)->toBeString()->toStartWith('0x');
});

it('decodes a uint128 as a hex string', function (): void {
    // type 10: control 0b000_10000 ext 0x03, size 16
    $bytes = "\x10\x03".str_repeat("\x00", 15)."\x05";

    expect(decodeBytes($bytes))->toBe('0x5');
});

it('decodes a zero-valued uint128 as zero', function (): void {
    $bytes = "\x10\x03".str_repeat("\x00", 16);

    expect(decodeBytes($bytes))->toBe(0);
});

it('decodes a positive int32', function (): void {
    // type 8: control 0b000_00100 ext 0x01, size 4
    expect(decodeBytes("\x04\x01\x00\x00\x00\x2a"))->toBe(42);
});

it('decodes a negative int32', function (): void {
    expect(decodeBytes("\x04\x01\xff\xff\xff\xd6"))->toBe(-42);
});

it('decodes a zero-size int32 as zero', function (): void {
    expect(decodeBytes("\x00\x01"))->toBe(0);
});

it('decodes a double', function (): void {
    // type 3, size 8
    expect(decodeBytes("\x68".pack('E', 3.14)))->toBe(3.14);
});

it('decodes a float', function (): void {
    // type 15: control 0b000_00100 ext 0x08, size 4
    $value = decodeBytes("\x04\x08".pack('G', 1.1));

    expect($value)->toBeFloat()->toBeGreaterThan(1.0)->toBeLessThan(1.2);
});

it('decodes a boolean true and false', function (): void {
    // type 14: ext 0x07; size 1 = true, size 0 = false
    expect(decodeBytes("\x01\x07"))->toBeTrue()
        ->and(decodeBytes("\x00\x07"))->toBeFalse();
});

it('decodes bytes', function (): void {
    // type 4: control 0b100_00010 ext? no -> type 4 size 2
    expect(decodeBytes("\x82\x01\x02"))->toBe("\x01\x02");
});

it('decodes a map', function (): void {
    // type 7: control 0b111_00001 size 1; key "k" (string size1), value uint16 7
    $bytes = "\xe1\x41k\xa1\x07";

    expect(decodeBytes($bytes))->toBe(['k' => 7]);
});

it('decodes an array', function (): void {
    // type 11: control 0b000_00010 ext 0x04 size 2; two strings
    $bytes = "\x02\x04\x41a\x41b";

    expect(decodeBytes($bytes))->toBe(['a', 'b']);
});

it('decodes a size-29 extended payload', function (): void {
    // string of length 29 -> size nibble 29, +1 byte = 0 => 29
    $bytes = "\x5d\x00".str_repeat('x', 29);

    expect(decodeBytes($bytes))->toBe(str_repeat('x', 29));
});

it('decodes a size-30 extended payload', function (): void {
    // string length 285 -> size 30, 2 bytes = 0
    $length = 285;
    $bytes = "\x5e\x00\x00".str_repeat('y', $length);

    expect(decodeBytes($bytes))->toHaveLength($length);
});

it('decodes a size-31 extended payload', function (): void {
    // string length 65821 -> size 31, 3 bytes = 0
    $length = 65821;
    $bytes = "\x5f\x00\x00\x00".str_repeat('z', $length);

    expect(decodeBytes($bytes))->toHaveLength($length);
});

it('decodes an 11-bit pointer', function (): void {
    // pointer size 0: control 0b001_00_000 | value bits, next byte = low 8 bits.
    // Put a uint16(9) at offset 3, point at it.
    $buffer = "\x20\x03\x00\xa2\x00\x09"; // pointer at 0..1 -> base+3
    [$value] = (new Decoder($buffer))->decode(0);

    expect($value)->toBe(9);
});

it('decodes a 19-bit pointer with its offset', function (): void {
    // pointer size 1: value offset +2048. Build a buffer with target at offset 2048+X.
    $target = "\xa1\x2a"; // uint16(42)
    $padding = str_repeat("\x00", 2048);
    // pointer size 1 -> control 0b001_01_000 = 0x28, then 2 bytes pointer = X (before +2048)
    $buffer = "\x28\x00\x05".$padding.$target;
    // pointer value = (0<<16)|0x0005 + 2048 = 2053 -> target at 2053
    $buffer = "\x28\x00\x05".str_repeat("\x00", 2053 - 3).$target;
    [$value] = (new Decoder($buffer))->decode(0);

    expect($value)->toBe(42);
});

it('decodes a 27-bit pointer with its offset', function (): void {
    // pointer size 2 -> control 0b001_10_000 = 0x30, then 3 bytes pointer; +526336 offset.
    $target = "\xa1\x07"; // uint16(7)
    $pointerRaw = 100; // 3-byte value
    $targetOffset = $pointerRaw + 526336;
    $buffer = "\x30\x00\x00\x64"; // 3 pointer bytes = 0x000064 = 100
    $buffer .= str_repeat("\x00", $targetOffset - strlen($buffer));
    $buffer .= $target;

    [$value] = (new Decoder($buffer))->decode(0);

    expect($value)->toBe(7);
});

it('decodes a 32-bit pointer', function (): void {
    // pointer size 3 -> control 0b001_11_000 = 0x38, value bits ignored, then 4 bytes.
    $target = "\xa1\x2a"; // uint16(42)
    $targetOffset = 12;
    $buffer = "\x38\x00\x00\x00\x0c"; // 4 pointer bytes = 0x0000000c = 12
    $buffer .= str_repeat("\x00", $targetOffset - strlen($buffer));
    $buffer .= $target;

    [$value] = (new Decoder($buffer))->decode(0);

    expect($value)->toBe(42);
});

it('throws on a malformed float size', function (): void {
    // type 15 (float) with size 2 is invalid
    decodeBytes("\x02\x08\x00\x00");
})->throws(InvalidDatabaseException::class);

it('throws on an unknown data type', function (): void {
    // extended marker (0) + ext byte 5 -> type 12, which is unassigned
    decodeBytes("\x01\x05");
})->throws(InvalidDatabaseException::class);

it('throws on an unexpected end of file', function (): void {
    (new Decoder("\x45hel"))->decode(0);
})->throws(InvalidDatabaseException::class);

it('throws on a malformed double size', function (): void {
    // type 3 (double) with size 4 is invalid
    decodeBytes("\x64\x00\x00\x00\x00");
})->throws(InvalidDatabaseException::class);
