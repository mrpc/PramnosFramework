<?php

declare(strict_types=1);

/**
 * The results as the markdown tables the design doc carries (docs/Metrics-BI-Design.md §5.4).
 *
 *   php summarize.php
 */

$dir = __DIR__ . '/results';
$ms  = static fn (?float $v): string => $v === null ? '—' : ($v >= 100 ? number_format($v, 0) : number_format($v, 1));
$mb  = static fn (int $b): string => $b >= 1e9 ? number_format($b / 1e9, 2) . ' GB' : number_format($b / 1e6, 1) . ' MB';

$reads = ['card' => 'Card (1 value)', 'chart_24_months' => 'Chart, 24 months', 'report_30x12_compare' => 'Report 30×12 + last year', 'report_30x12_by_hash' => 'Same, by series hash',
    'chart_168_hours' => 'Chart, 168 hours', 'day_by_tenant_from_hours' => 'A day by tenant, from hours', 'compute_open_day' => 'Write an open day (all series)', 'compute_open_hour' => 'Write an open day\'s 24 hours',
    'recompute_closed_day' => 'Recompute a closed month: days', 'recompute_closed_month' => 'Recompute a closed month: the month', 'recompute_closed_hour' => 'Recompute a closed month: hours'];

foreach (['small', 'medium', 'large'] as $size) {
    foreach (['ts' => 'TimescaleDB 2.31 + Toolkit 1.26', 'plain' => 'PostgreSQL 18, plain tables', 'plain-pg10' => 'PostgreSQL 10, plain tables'] as $engine => $title) {
        $file = "$dir/$size-$engine.json";
        if (!is_file($file)) {
            continue;
        }
        $r = json_decode((string) file_get_contents($file), true);
        echo "\n#### " . ucfirst($size) . ', ' . $title . ' — ' . number_format(max($r['rows'])) . " rows\n\n";
        $sets = ['' => $r['layouts']] + (isset($r['layouts_compressed']) ? [' (compressed)' => $r['layouts_compressed']] : []);
        echo '| ms (median) |';
        foreach ($sets as $label => $layouts) {
            foreach (array_keys($layouts) as $l) {
                echo " $l$label |";
            }
        }
        echo "\n|---|" . str_repeat('---:|', array_sum(array_map('count', $sets))) . "\n";
        foreach ($reads as $key => $label) {
            $line = "| $label |";
            $any  = false;
            foreach ($sets as $layouts) {
                foreach ($layouts as $values) {
                    $any   = $any || isset($values[$key]);
                    $line .= ' ' . $ms($values[$key] ?? null) . ' |';
                }
            }
            if ($any) {
                echo $line . "\n";
            }
        }
        echo '| Size on disk |';
        foreach ($sets as $label => $layouts) {
            foreach (array_keys($layouts) as $l) {
                echo ' ' . $mb($label === '' ? $r['bytes'][$l] : $r['bytes_compressed'][$l]) . ' |';
            }
        }
        echo "\n";
    }
}

foreach (['medium', 'large'] as $size) {
    $file = "$dir/hll-$size.json";
    if (!is_file($file)) {
        continue;
    }
    $r = json_decode((string) file_get_contents($file), true);
    echo "\n#### Distinct people, " . number_format($r['events']) . ' events of ' . number_format($r['people']) . " people\n\n";
    echo "| | Exact, from the events | hyperloglog 4096 | hyperloglog 32768 |\n|---|---:|---:|---:|\n";
    $h = $r['hll'];
    echo '| A month | ' . $ms($r['exact_month_ms']) . ' | ' . $ms($h[4096]['month_from_days_ms']) . ' (from days), ' . $ms($h[4096]['month_from_monthly_ms']) . ' (monthly) | ' . $ms($h[32768]['month_from_days_ms']) . ', ' . $ms($h[32768]['month_from_monthly_ms']) . " |\n";
    echo '| A month by region | ' . $ms($r['exact_month_by_region_ms']) . ' | ' . $ms($h[4096]['month_by_region_ms']) . ' | ' . $ms($h[32768]['month_by_region_ms']) . " |\n";
    echo '| A year | ' . $ms($r['exact_year_ms']) . ' | ' . $ms($h[4096]['year_from_monthly_ms']) . ' | ' . $ms($h[32768]['year_from_monthly_ms']) . " |\n";
    echo '| Error, month / year | — | ' . $h[4096]['month_error_pct'] . '% / ' . $h[4096]['year_error_pct'] . '% | ' . $h[32768]['month_error_pct'] . '% / ' . $h[32768]['year_error_pct'] . "% |\n";
    echo '| Sketch size | — | ' . number_format($h[4096]['sketch_bytes']) . ' B | ' . number_format($h[32768]['sketch_bytes']) . " B |\n";
    echo '| Building the daily aggregate | — | ' . $ms($h[4096]['build_daily_ms']) . ' | ' . $ms($h[32768]['build_daily_ms']) . " |\n";
    echo '| Refreshing one day | — | ' . $ms($h[4096]['refresh_one_day_ms']) . ' | ' . $ms($h[32768]['refresh_one_day_ms']) . " |\n";
}

if (is_file("$dir/real.json")) {
    $r = json_decode((string) file_get_contents("$dir/real.json"), true);
    echo "\n#### Real data: " . number_format($r['rows']['invoices']) . ' paid invoices, ' . number_format($r['rows']['users']) . " registrations (unix times)\n\n";
    echo "| Computing | ms |\n|---|---:|\n";
    foreach (['year_of_days_one_query_per_day_ms' => 'A year of days, one query per day (plain table)', 'year_of_days_one_time_bucket_query_ms' => 'A year of days, one `time_bucket` query (plain table)',
        'twelve_years_of_months_one_query_ms' => 'Twelve years of months, one query', 'hypertable_year_of_days_per_day_ms' => 'A year of days, one query per day (hypertable on the unix column)',
        'hypertable_year_of_days_one_query_ms' => 'A year of days, one query (hypertable)', 'cagg_year_of_days_read_ms' => 'A year of days read from its continuous aggregate'] as $key => $label) {
        echo "| $label | " . $ms($r[$key] ?? null) . " |\n";
    }
}
