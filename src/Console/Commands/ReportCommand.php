<?php

namespace Kombee\IndexAdvisor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Prints a summary report to the console and optionally emails it.
 *
 * Usage:
 *   php artisan index-advisor:report
 *   php artisan index-advisor:report --email=dba@example.com
 */
class ReportCommand extends Command
{
    protected $signature = 'index-advisor:report {--email= : Override report_email config}';

    protected $description = 'Print index advisor recommendations report and optionally email it';

    public function handle(): int
    {
        $excludedTables = (array) config('index_advisor.excluded_tables', []);
        $this->table(
            ['Table', 'Rows'],
            [
                ['index_advisor_queries', number_format(DB::table('index_advisor_queries')->count())],
                ['index_advisor_query_stats', number_format(DB::table('index_advisor_query_stats')->count())],
                ['index_advisor_columns', number_format(DB::table('index_advisor_columns')->count())],
                ['index_advisor_explains', number_format(DB::table('index_advisor_explains')->count())],
                ['index_advisor_recommendations', number_format(DB::table('index_advisor_recommendations')->whereNotIn('table_name', $excludedTables)->count())],
            ]
        );
        $recs = DB::table('index_advisor_recommendations')
            ->whereNotIn('table_name', $excludedTables)
            ->orderByDesc('score')
            ->get(['table_name', 'column_name', 'index_type', 'score', 'status', 'evidence']);

        if ($recs->isEmpty()) {
            $this->warn('No recommendations found. Run `php artisan index-advisor:run` first.');

            return Command::SUCCESS;
        }

        $rows = $recs->map(fn ($r) => [
            $r->table_name,
            $this->formatColumns($r),
            $r->index_type,
            $this->scoreLabel((int) $r->score),
            $r->score,
            ucfirst($r->status),
        ])->all();

        $this->table(
            ['Table', 'Column(s)', 'Type', 'Verdict', 'Score', 'Status'],
            $rows
        );

        $thresholds = $this->thresholds();
        $critical = $recs->where('score', '>=', $thresholds['critical'])->count();
        $high = $recs->filter(fn ($r) => $r->score >= $thresholds['high'] && $r->score < $thresholds['critical'])->count();
        $medium = $recs->filter(fn ($r) => $r->score >= $thresholds['medium'] && $r->score < $thresholds['high'])->count();
        $low = $recs->where('score', '<', $thresholds['medium'])->count();

        $this->info("CRITICAL: {$critical} | HIGH: {$high} | MEDIUM: {$medium} | LOW/IGNORE: {$low}");

        $email = $this->option('email') ?? config('index_advisor.report_email');

        if ($email) {
            if (! class_exists(Mail::class)) {
                $this->warn('  Email skipped: illuminate/mail is not installed.');

                return Command::SUCCESS;
            }

            $this->sendEmail($email, $recs, $critical, $high, $medium, $low);
        }

        return Command::SUCCESS;
    }

    private function sendEmail(string $to, $recs, int $critical, int $high, int $medium, int $low): void
    {
        try {
            $html = $this->buildHtmlReport($recs, $critical, $high, $medium, $low);

            Mail::html($html, function ($message) use ($to, $critical) {
                $message->to($to)
                    ->subject("[Index Advisor] Weekly Report - {$critical} CRITICAL issue(s) - ".now()->toDateString());
            });

            $this->info("Report emailed to {$to}");
        } catch (\Throwable $e) {
            $this->warn("  Email failed: {$e->getMessage()}");
        }
    }

    private function buildHtmlReport($recs, int $critical, int $high, int $medium, int $low): string
    {
        $rows = '';
        foreach ($recs as $r) {
            $color = match (true) {
                $r->score >= $this->thresholds()['critical'] => '#ff4444',
                $r->score >= $this->thresholds()['high'] => '#ff8800',
                $r->score >= $this->thresholds()['medium'] => '#ffcc00',
                default => '#aaaaaa',
            };
            $rows .= "<tr>
                <td style='padding:6px 10px'>{$r->table_name}</td>
                <td style='padding:6px 10px'>{$this->formatColumns($r)}</td>
                <td style='padding:6px 10px'>{$r->index_type}</td>
                <td style='padding:6px 10px;color:{$color};font-weight:bold'>{$r->score}</td>
                <td style='padding:6px 10px'>".ucfirst($r->status).'</td>
            </tr>';
        }

        return <<<HTML
<html><body style='font-family:sans-serif;color:#333'>
<h2>Index Advisor - Weekly Report</h2>
<p>Generated: {$this->now()}</p>
<p>
  <strong style='color:#ff4444'>CRITICAL: {$critical}</strong> &nbsp;
  <strong style='color:#ff8800'>HIGH: {$high}</strong> &nbsp;
  <strong style='color:#ffcc00'>MEDIUM: {$medium}</strong> &nbsp;
  LOW/IGNORE: {$low}
</p>
<table border='1' cellspacing='0' style='border-collapse:collapse;width:100%'>
  <thead style='background:#f0f0f0'>
    <tr>
      <th style='padding:8px 10px'>Table</th>
      <th style='padding:8px 10px'>Column(s)</th>
      <th style='padding:8px 10px'>Type</th>
      <th style='padding:8px 10px'>Score</th>
      <th style='padding:8px 10px'>Status</th>
    </tr>
  </thead>
  <tbody>{$rows}</tbody>
</table>
<p style='color:#888;font-size:12px'>Run <code>php artisan index-advisor:generate-migrations</code> to create migration files for CRITICAL/HIGH items.</p>
</body></html>
HTML;
    }

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    private function scoreLabel(int $score): string
    {
        $thresholds = $this->thresholds();

        if ($score >= $thresholds['critical']) {
            return '🔴 CRITICAL';
        }
        if ($score >= $thresholds['high']) {
            return '🟠 HIGH';
        }
        if ($score >= $thresholds['medium']) {
            return '🟡 MEDIUM';
        }
        if ($score >= $thresholds['low']) {
            return '🟢 LOW';
        }

        return '⚪ IGNORE';
    }

    private function formatColumns(object $rec): string
    {
        if ($rec->index_type !== 'COMPOSITE') {
            return $rec->column_name;
        }

        $evidence = json_decode($rec->evidence ?? '{}', true);
        $weights = $evidence['column_weights'] ?? [];

        if (empty($weights)) {
            return $rec->column_name;
        }

        return collect($weights)
            ->map(fn ($weight) => sprintf('%s (%s%%)', $weight['column'], $weight['weight_percent']))
            ->implode(', ');
    }

    private function thresholds(): array
    {
        $thresholds = (array) config('index_advisor.scoring.verdict_thresholds', []);

        return [
            'critical' => (int) ($thresholds['critical'] ?? 80),
            'high' => (int) ($thresholds['high'] ?? 60),
            'medium' => (int) ($thresholds['medium'] ?? 40),
            'low' => (int) ($thresholds['low'] ?? 20),
        ];
    }
}
