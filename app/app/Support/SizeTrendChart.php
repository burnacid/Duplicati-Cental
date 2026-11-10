<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Geometry for the source / backup size line chart (rendered as inline SVG by
 * <x-size-trend-chart>). Sizes are in bytes, times are Unix timestamps.
 */
class SizeTrendChart
{
    public const WIDTH = 720;
    public const HEIGHT = 220;
    private const LEFT = 64;
    private const RIGHT = 12;
    private const TOP = 10;
    private const BOTTOM = 26;
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /** @var list<array{t: int, source: int, backup: int}> */
    private array $points;

    private float $maxY;

    /**
     * @param list<array{t: int, source: int, backup: int}> $points oldest first
     */
    public function __construct(array $points)
    {
        $this->points = array_values($points);
        $max = 0;
        foreach ($this->points as $p) {
            $max = max($max, $p['source'], $p['backup']);
        }
        [$this->maxY] = $this->niceScale($max);
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    public function path(string $series): string
    {
        $parts = [];
        foreach ($this->points as $i => $p) {
            $parts[] = ($i === 0 ? 'M' : 'L') . $this->x($p['t']) . ' ' . $this->y($p[$series]);
        }

        return implode(' ', $parts);
    }

    /** @return list<array{x: float, ys: float, yb: float, title: string}> */
    public function markers(): array
    {
        return array_map(fn ($p) => [
            'x' => $this->x($p['t']),
            'ys' => $this->y($p['source']),
            'yb' => $this->y($p['backup']),
            'title' => Carbon::createFromTimestamp($p['t'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i')
                . ' · source ' . self::formatBytes($p['source']) . ' · backup ' . self::formatBytes($p['backup']),
        ], $this->points);
    }

    /** Horizontal grid lines with their labels. @return list<array{y: float, label: string}> */
    public function yTicks(): array
    {
        [, $step] = $this->niceScale($this->maxY);
        $ticks = [];
        for ($v = 0.0; $v <= $this->maxY + 0.5; $v += $step) {
            $ticks[] = ['y' => $this->y($v), 'label' => self::formatBytes($v)];
        }

        return $ticks;
    }

    /** Up to five evenly spaced date labels. @return list<array{x: float, label: string, anchor: string}> */
    public function xTicks(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $first = $this->points[0]['t'];
        $last = end($this->points)['t'];
        if ($first === $last) {
            return [['x' => $this->x($first), 'label' => $this->dateLabel($first), 'anchor' => 'middle']];
        }

        $ticks = [];
        for ($i = 0; $i <= 4; $i++) {
            $t = (int) round($first + ($last - $first) * $i / 4);
            $ticks[] = ['x' => $this->x($t), 'label' => $this->dateLabel($t), 'anchor' => match ($i) { 0 => 'start', 4 => 'end', default => 'middle' }];
        }

        return $ticks;
    }

    public function plotLeft(): int
    {
        return self::LEFT;
    }

    public function plotRight(): int
    {
        return self::WIDTH - self::RIGHT;
    }

    public function plotBottom(): int
    {
        return self::HEIGHT - self::BOTTOM;
    }

    public static function formatBytes(float $bytes): string
    {
        $i = 0;
        while ($bytes >= 1024 && $i < count(self::UNITS) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $bytes >= 100 ? 0 : 1) . ' ' . self::UNITS[$i];
    }

    private function x(int $t): float
    {
        $first = $this->points[0]['t'] ?? 0;
        $last = $this->points === [] ? 0 : end($this->points)['t'];
        $width = self::WIDTH - self::LEFT - self::RIGHT;

        if ($last === $first) {
            return round(self::LEFT + $width / 2, 1);
        }

        return round(self::LEFT + ($t - $first) / ($last - $first) * $width, 1);
    }

    private function y(float $value): float
    {
        $height = self::HEIGHT - self::TOP - self::BOTTOM;

        return round(self::TOP + $height - ($this->maxY > 0 ? $value / $this->maxY : 0) * $height, 1);
    }

    private function dateLabel(int $t): string
    {
        return Carbon::createFromTimestamp($t)->setTimezone(config('app.timezone'))->format('j M');
    }

    /**
     * A rounded maximum and step (about four steps) in whole binary units,
     * so the axis reads 0, 50 GB, 100 GB … instead of odd byte counts.
     *
     * @return array{0: float, 1: float}
     */
    private function niceScale(float $max): array
    {
        if ($max <= 0) {
            return [1024.0, 256.0];
        }

        $unit = 1024 ** min(count(self::UNITS) - 1, (int) floor(log($max, 1024)));
        $raw = $max / $unit / 4;
        $magnitude = 10 ** floor(log10($raw));
        $step = collect([1, 2, 2.5, 5, 10])->map(fn ($f) => $f * $magnitude)->first(fn ($s) => $s >= $raw) * $unit;

        return [ceil($max / $step) * $step, $step];
    }
}
