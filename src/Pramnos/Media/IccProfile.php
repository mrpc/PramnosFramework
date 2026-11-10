<?php

declare(strict_types=1);

namespace Pramnos\Media;

/**
 * An RGB display ICC profile built from its numbers: sRGB, the colours every browser assumes, and
 * Display P3, the one an iPhone shoots in — so a photograph's colours can be converted to sRGB
 * without an `sRGB.icc` file the server may not have.
 *
 * A version 2 matrix/TRC profile: the primaries adapted to D50 (the profile connection space's white),
 * and the sRGB tone curve as a table. Display P3 shares that curve and that white; only its primaries
 * differ.
 */
final class IccProfile
{
    /** The sRGB primaries, adapted to D50: red, green, blue as XYZ. */
    private const SRGB = [[0.4361, 0.2225, 0.0139], [0.3851, 0.7169, 0.0971], [0.1431, 0.0606, 0.7141]];

    /** The Display P3 primaries, adapted to D50. */
    private const P3 = [[0.5151, 0.2412, -0.0011], [0.2920, 0.6922, 0.0419], [0.1571, 0.0666, 0.7841]];

    /** D50, the white of the connection space. */
    private const D50 = [0.9642, 1.0, 0.8249];

    /** @var array<string, string> Built once */
    private static array $built = [];

    /** sRGB, as an ICC profile's bytes. */
    public static function srgb(): string
    {
        return self::$built['srgb'] ??= self::build('sRGB (Pramnos)', self::SRGB);
    }

    /** Display P3, as an ICC profile's bytes — for a test, or a picture known to be in it. */
    public static function displayP3(): string
    {
        return self::$built['p3'] ??= self::build('Display P3 (Pramnos)', self::P3);
    }

    /**
     * A profile from its name and primaries.
     *
     * @param array{0: array{float, float, float}, 1: array{float, float, float}, 2: array{float, float, float}} $primaries
     */
    private static function build(string $name, array $primaries): string
    {
        $xyz   = static fn (array $v): string => 'XYZ ' . "\0\0\0\0" . implode('', array_map(self::s15f16(...), $v));
        $curve = 'curv' . "\0\0\0\0" . pack('N', 1024);
        for ($i = 0; $i < 1024; $i++) {
            $c = $i / 1023;
            $linear = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            $curve .= pack('n', (int) round($linear * 65535));
        }
        $desc = 'desc' . "\0\0\0\0" . pack('N', strlen($name) + 1) . $name . "\0"
            . pack('N', 0) . pack('N', 0)          // no Unicode description
            . pack('n', 0) . "\0" . str_repeat("\0", 67);   // no ScriptCode description
        $tags = [
            'desc' => $desc,
            'cprt' => 'text' . "\0\0\0\0" . "No copyright, use freely\0",
            'wtpt' => $xyz(self::D50),
            'rXYZ' => $xyz($primaries[0]),
            'gXYZ' => $xyz($primaries[1]),
            'bXYZ' => $xyz($primaries[2]),
            'rTRC' => $curve,
            'gTRC' => $curve,
            'bTRC' => $curve,
        ];

        // The three curves are one: written once, pointed at three times.
        $table  = '';
        $data   = '';
        $offset = 128 + 4 + 12 * count($tags);
        $placed = [];
        foreach ($tags as $signature => $bytes) {
            if (!isset($placed[$bytes])) {
                $placed[$bytes] = $offset + strlen($data);
                $data .= $bytes . str_repeat("\0", (4 - strlen($bytes) % 4) % 4);
            }
            $table .= $signature . pack('N', $placed[$bytes]) . pack('N', strlen($bytes));
        }
        $body = pack('N', count($tags)) . $table . $data;
        $size = 128 + strlen($body);

        $header = pack('N', $size) . "\0\0\0\0" . pack('N', 0x02100000) . 'mntr' . 'RGB ' . 'XYZ '
            . pack('n6', 2026, 1, 1, 0, 0, 0) . 'acsp' . "\0\0\0\0" . pack('N', 0)
            . "\0\0\0\0" . "\0\0\0\0" . str_repeat("\0", 8) . pack('N', 0)
            . implode('', array_map(self::s15f16(...), self::D50)) . "\0\0\0\0";
        $header .= str_repeat("\0", 128 - strlen($header));

        return $header . $body;
    }

    /** A number as ICC's s15Fixed16, big-endian. */
    private static function s15f16(float $value): string
    {
        return pack('N', ((int) round($value * 65536)) & 0xFFFFFFFF);
    }
}
