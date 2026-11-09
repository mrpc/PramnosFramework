<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * A chart as an image, drawn on the server: as SVG always, as PNG where the server can turn SVG
 * into one — for a report sent by mail, where no mail client runs a script or shows an SVG.
 *
 * PNG needs one of, tried in this order:
 *
 * - the `imagick` PHP extension, with ImageMagick able to read SVG (its `librsvg` or `MSVG`
 *   delegate): `apt install php-imagick librsvg2-2`;
 * - the `rsvg-convert` program: `apt install librsvg2-bin`.
 *
 * Neither keeps anything running: each chart is one call. Without either, or when one fails,
 * {@see png()} answers null and the caller shows the numbers instead — the mail still goes.
 */
final class ChartImage
{
    /** The image's width in pixels, as a mail shows it. */
    public const WIDTH = 640;

    /** The image's height in pixels. */
    public const HEIGHT = 300;

    /** The series' colours, in turn. */
    private const COLOURS = ['#2563eb', '#16a34a', '#dc2626', '#9333ea', '#ea580c', '#0891b2', '#ca8a04', '#db2777'];

    /** @var (callable(string): ?string)|null A converter set for a test */
    private static $converter = null;

    /**
     * The chart as SVG: lines or bars, an axis of values from zero, the labels under it, and a
     * legend.
     *
     * @param list<string>                                   $labels
     * @param list<array{label: string, data: list<?float>}> $sets
     */
    public static function svg(string $type, array $labels, array $sets, string $title = ''): string
    {
        $w      = self::WIDTH;
        $h      = self::HEIGHT;
        $left   = 56;
        $right  = 12;
        $top    = $title !== '' ? 34 : 14;
        $bottom = 56;
        $plotW  = $w - $left - $right;
        $plotH  = $h - $top - $bottom;
        $values = array_filter(array_merge(...array_map(static fn (array $s): array => $s['data'], $sets ?: [['data' => []]])), static fn ($v): bool => $v !== null);
        $max    = $values === [] ? 1.0 : max(0.0, (float) max($values));
        $min    = $values === [] ? 0.0 : min(0.0, (float) min($values));
        [$min, $max, $step] = self::scale($min, $max);
        $y      = static fn (float $v): float => $top + $plotH - ($v - $min) / ($max - $min) * $plotH;
        $count  = max(1, count($labels));
        $slot   = $plotW / $count;
        $e      = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" font-family="DejaVu Sans, Arial, sans-serif" font-size="11">'
            . '<rect width="100%" height="100%" fill="#ffffff"/>';
        if ($title !== '') {
            $svg .= '<text x="' . $left . '" y="20" font-size="14" font-weight="bold" fill="#111827">' . $e($title) . '</text>';
        }
        // The value axis: lines across, their numbers on the left.
        for ($v = $min; $v <= $max + $step / 2; $v += $step) {
            $at   = round($y($v), 1);
            $svg .= '<line x1="' . $left . '" y1="' . $at . '" x2="' . ($w - $right) . '" y2="' . $at . '" stroke="#e5e7eb"/>'
                . '<text x="' . ($left - 6) . '" y="' . ($at + 4) . '" text-anchor="end" fill="#6b7280">' . $e(self::number($v)) . '</text>';
        }
        // The labels under the plot, as many as fit.
        $every = max(1, (int) ceil($count / max(1, (int) floor($plotW / 60))));
        foreach ($labels as $i => $label) {
            if ($i % $every === 0) {
                $svg .= '<text x="' . round($left + $slot * ($i + 0.5), 1) . '" y="' . ($top + $plotH + 16) . '" text-anchor="middle" fill="#374151">' . $e(mb_substr((string) $label, 0, 12)) . '</text>';
            }
        }
        $bars = max(1, count($sets));
        foreach (array_values($sets) as $n => $set) {
            $colour = self::COLOURS[$n % count(self::COLOURS)];
            if ($type === 'bar') {
                $width = $slot * 0.8 / $bars;
                foreach ($set['data'] as $i => $v) {
                    if ($v === null) {
                        continue;
                    }
                    $from = $y(max(0.0, $min));
                    $to   = $y((float) $v);
                    $svg .= '<rect x="' . round($left + $slot * $i + $slot * 0.1 + $width * $n, 1) . '" y="' . round(min($from, $to), 1) . '" width="' . round($width, 1) . '" height="' . round(abs($from - $to), 1) . '" fill="' . $colour . '"/>';
                }
            } else {
                $points = [];
                foreach ($set['data'] as $i => $v) {
                    if ($v !== null) {
                        $points[] = round($left + $slot * ($i + 0.5), 1) . ',' . round($y((float) $v), 1);
                    }
                }
                if ($points !== []) {
                    $svg .= '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="' . $colour . '" stroke-width="2"/>';
                }
            }
            // The legend, under the labels.
            $lx   = $left + ($n % 3) * 190;
            $ly   = $h - 22 + intdiv($n, 3) * 14;
            $svg .= '<rect x="' . $lx . '" y="' . ($ly - 8) . '" width="10" height="10" fill="' . $colour . '"/>'
                . '<text x="' . ($lx + 14) . '" y="' . $ly . '" fill="#111827">' . $e(mb_substr($set['label'], 0, 28)) . '</text>';
        }

        return $svg . '</svg>';
    }

    /**
     * The chart as PNG, or null when the server cannot make one — no converter, or it failed (the
     * failure is logged).
     *
     * @param list<string>                                   $labels
     * @param list<array{label: string, data: list<?float>}> $sets
     */
    public static function png(string $type, array $labels, array $sets, string $title = ''): ?string
    {
        $svg = self::svg($type, $labels, $sets, $title);
        try {
            if (self::$converter !== null) {
                return (self::$converter)($svg);
            }
            if (extension_loaded('imagick') && class_exists(\Imagick::class) && \Imagick::queryFormats('SVG') !== []) {
                $image = new \Imagick();
                $image->setBackgroundColor(new \ImagickPixel('white'));
                $image->readImageBlob($svg);
                $image->setImageFormat('png');
                $png = $image->getImageBlob();
                $image->clear();

                return $png !== '' ? $png : null;
            }

            return self::rsvg($svg);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('A chart could not be made a PNG: ' . $e->getMessage(), 'metrics');

            return null;
        }
    }

    /**
     * Use another converter from SVG to PNG — for a test, or a service of the application's own;
     * null puts the built-in ones back.
     *
     * @param (callable(string): ?string)|null $converter
     */
    public static function convertWith(?callable $converter): void
    {
        self::$converter = $converter;
    }

    /** PNG from `rsvg-convert`, or null when it is not there or fails. */
    private static function rsvg(string $svg): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $process = @proc_open(['rsvg-convert', '--format=png', '--background-color=white'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        fwrite($pipes[0], $svg);
        fclose($pipes[0]);
        $png = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || !str_starts_with($png, "\x89PNG")) {
            if ($code !== 127) {
                \Pramnos\Logs\Logger::log('rsvg-convert failed (' . $code . '): ' . trim($err), 'metrics');
            }

            return null;
        }

        return $png;
    }

    /** An axis from a round number to a round number, and the step between its lines. @return array{0: float, 1: float, 2: float} */
    private static function scale(float $min, float $max): array
    {
        if ($max - $min <= 0) {
            $max = $min + 1;
        }
        $raw   = ($max - $min) / 5;
        $power = 10 ** floor(log10($raw));
        $step  = $power * (($raw / $power) <= 1 ? 1 : (($raw / $power) <= 2 ? 2 : (($raw / $power) <= 5 ? 5 : 10)));

        return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
    }

    /** A number on the axis: thousands as k, millions as M. */
    private static function number(float $v): string
    {
        $abs = abs($v);

        return match (true) {
            $abs >= 1_000_000 => \Pramnos\General\NumberFormat::trimmed($v / 1_000_000, 1) . 'M',
            $abs >= 10_000    => \Pramnos\General\NumberFormat::trimmed($v / 1_000, 1) . 'k',
            default           => \Pramnos\General\NumberFormat::trimmed($v, 2),
        };
    }
}
