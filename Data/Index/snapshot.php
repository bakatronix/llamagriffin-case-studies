<?php
/**
 * The Play Money Index — Daily Snapshot Generator
 * Run once per day via cPanel cron:
 *   php /home/bakamtko/llamagriffin.com/Data/Index/snapshot.php
 *
 * What it does:
 *   1. Reads prices.json (static price dictionary)
 *   2. Fetches today's FX rates from Frankfurter
 *   3. Calculates implied USD, per-unit, per-100-unit, and premium/discount for every market
 *   4. Appends one row per (date, currency, market) to snapshots.jsonl
 *   5. Writes a today.json summary for the homepage to read
 *
 * Skips duplicate (date, currency, market) entries — safe to run multiple times per day.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

define('BASE_DIR',      __DIR__);
define('PRICES_FILE',   BASE_DIR . '/prices.json');
define('SNAPSHOTS_FILE',BASE_DIR . '/snapshots.jsonl');
define('TODAY_FILE',    BASE_DIR . '/today.json');
define('LOG_FILE',      BASE_DIR . '/snapshot.log');
define('METHODOLOGY_V', '1.0');
define('FX_API',        'https://api.frankfurter.dev/v1/latest?base=USD');
define('VOLATILITY_THRESHOLDS', ['official' => 2.5, 'confirmed' => 2.5, 'third-party-provisional' => 5.0, 'contested' => 5.0]);

function log_msg(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

function fetch_fx(): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'user_agent' => 'PlayMoneyIndex/1.0']]);
    $raw = @file_get_contents(FX_API, false, $ctx);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    if (!isset($data['rates'])) return null;
    $data['rates']['USD'] = 1.0; // base currency
    return $data;
}

function load_existing_snapshots(): array {
    if (!file_exists(SNAPSHOTS_FILE)) return [];
    $keys = [];
    $lines = file(SNAPSHOTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if ($row) $keys[$row['date'] . '|' . $row['currency'] . '|' . $row['market']] = true;
    }
    return $keys;
}

function load_previous_snapshot(string $currency, string $market): ?array {
    if (!file_exists(SNAPSHOTS_FILE)) return null;
    $last = null;
    $lines = file(SNAPSHOTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if ($row && $row['currency'] === $currency && $row['market'] === $market) {
            $last = $row;
        }
    }
    return $last;
}

// ── MAIN ──────────────────────────────────────────────────────────────────────

log_msg('Snapshot run started');

// 1. Load prices
if (!file_exists(PRICES_FILE)) { log_msg('ERROR: prices.json not found'); exit(1); }
$prices = json_decode(file_get_contents(PRICES_FILE), true);
if (!$prices) { log_msg('ERROR: prices.json is invalid JSON'); exit(1); }

// 2. Fetch FX
$fx_data = fetch_fx();
if (!$fx_data) {
    log_msg('ERROR: Frankfurter fetch failed — no snapshot written for today');
    exit(1);
}
$rates    = $fx_data['rates'];
$fx_date  = $fx_data['date'] ?? date('Y-m-d');
$today    = date('Y-m-d');
log_msg("FX rates fetched. Date: $fx_date. Rates for: " . implode(', ', array_keys($rates)));

// 3. Load existing snapshot keys to avoid duplicates
$existing_keys = load_existing_snapshots();

// 4. Process each live index
$new_rows     = [];
$today_summary = ['date' => $today, 'fx_date' => $fx_date, 'indexes' => []];
$moves        = [];

foreach ($prices['indexes'] as $index) {
    if (($index['status'] ?? '') !== 'live') continue;

    $currency = $index['currency'];
    $bundle   = (int)$index['benchmark_bundle'];
    $slug     = $index['slug'];

    // Find US baseline implied per-100-units
    $us_per_100 = null;
    foreach ($index['markets'] as $m) {
        if ($m['is_baseline'] && isset($rates[$m['local_currency']])) {
            $us_implied_usd = $m['local_price'] / $rates[$m['local_currency']];
            $us_per_100     = ($us_implied_usd / $bundle) * 100;
            break;
        }
    }
    if ($us_per_100 === null) { log_msg("WARN: No US baseline for $currency — skipping"); continue; }

    $index_rows = [];

    foreach ($index['markets'] as $m) {
        $market = $m['market'];
        $cur    = $m['local_currency'];

        // Skip pegged currencies from drift calculations but still snapshot them
        $pegged = $m['pegged'] ?? false;

        if (!isset($rates[$cur])) {
            log_msg("WARN: No FX rate for $cur ($market) — skipping");
            continue;
        }

        $key = "$today|$currency|$market";
        if (isset($existing_keys[$key])) {
            log_msg("SKIP: Duplicate key $key");
            continue;
        }

        $fx_rate         = $rates[$cur];
        $implied_usd     = $m['local_price'] / $fx_rate;
        $implied_per_unit= $implied_usd / $bundle;
        $implied_per_100 = $implied_per_unit * 100;
        $prem_disc_pct   = (($implied_per_100 / $us_per_100) - 1) * 100;

        $row = [
            'date'                => $today,
            'fx_date'             => $fx_date,
            'game'                => $index['game'],
            'currency'            => $currency,
            'slug'                => $slug,
            'market'              => $market,
            'local_currency'      => $cur,
            'local_price'         => $m['local_price'],
            'fx_rate'             => round($fx_rate, 6),
            'implied_usd'         => round($implied_usd, 4),
            'implied_usd_per_unit'=> round($implied_per_unit, 6),
            'implied_usd_per_100' => round($implied_per_100, 4),
            'premium_discount_pct'=> round($prem_disc_pct, 2),
            'is_baseline'         => $m['is_baseline'] ?? false,
            'source_confidence'   => $m['source_confidence'],
            'pegged'              => $pegged,
            'methodology_version' => METHODOLOGY_V,
        ];

        // Check for volatility move vs yesterday
        if (!$pegged && !($m['is_baseline'] ?? false)) {
            $prev = load_previous_snapshot($currency, $market);
            if ($prev) {
                $delta = abs($prem_disc_pct - $prev['premium_discount_pct']);
                $threshold = VOLATILITY_THRESHOLDS[$m['source_confidence']] ?? 5.0;
                if ($delta >= $threshold) {
                    $dir = $prem_disc_pct < $prev['premium_discount_pct'] ? 'cheaper' : 'more expensive';
                    $moves[] = [
                        'currency'  => $currency,
                        'market'    => $market,
                        'delta'     => round($delta, 1),
                        'direction' => $dir,
                        'new_pct'   => round($prem_disc_pct, 1),
                        'prev_pct'  => round($prev['premium_discount_pct'], 1),
                        'confidence'=> $m['source_confidence'],
                        'note'      => "$market moved $dir on the $currency Index by {$delta} percentage points. Causal interpretation requires editorial review.",
                    ];
                }
            }
        }

        $new_rows[] = $row;
        $index_rows[] = $row;
        log_msg("  $currency · $market → \${$row['implied_usd']} ({$row['premium_discount_pct']}%)");
    }

    // Build today summary for this index
    if (!empty($index_rows)) {
        usort($index_rows, fn($a,$b) => $a['premium_discount_pct'] <=> $b['premium_discount_pct']);
        $non_baseline = array_filter($index_rows, fn($r) => !$r['is_baseline']);
        $cheapest = !empty($non_baseline) ? reset($non_baseline) : null;
        $dearest  = !empty($non_baseline) ? end($non_baseline) : null;
        $spread   = ($cheapest && $dearest) ? round($dearest['premium_discount_pct'] - $cheapest['premium_discount_pct'], 1) : 0;
        $today_summary['indexes'][$slug] = [
            'currency'    => $currency,
            'game'        => $index['game'],
            'markets'     => $index_rows,
            'cheapest'    => $cheapest,
            'dearest'     => $dearest,
            'spread_pct'  => $spread,
            'us_per_100'  => round($us_per_100, 4),
        ];
    }
}

// 5. Append new rows to snapshots.jsonl
if (!empty($new_rows)) {
    $lines = array_map('json_encode', $new_rows);
    file_put_contents(SNAPSHOTS_FILE, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
    log_msg(count($new_rows) . ' new rows appended to snapshots.jsonl');
} else {
    log_msg('No new rows to append (all already present)');
}

// 6. Write today.json for fast homepage reads
$today_summary['moves']      = $moves;
$today_summary['generated']  = date('c');
file_put_contents(TODAY_FILE, json_encode($today_summary, JSON_PRETTY_PRINT));
log_msg('today.json written');

log_msg('Snapshot run complete');
