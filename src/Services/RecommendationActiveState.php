<?php

namespace Kombee\IndexAdvisor\Services;

use Illuminate\Support\Facades\DB;
use Kombee\IndexAdvisor\Contracts\SchemaIntrospectorContract;

/**
 * Reads and updates active (pending/generated) recommendation rows for a column.
 */
class RecommendationActiveState
{
    private const ACTIVE_STATUSES = ['pending', 'generated'];

    public function __construct(private SchemaIntrospectorContract $schema) {}

    public function hasActiveRecommendation(string $table, string $column, string $indexType): bool
    {
        $canonicalTable = $this->schema->canonicalTableName($table) ?? $table;

        return DB::table('index_advisor_recommendations')
            ->whereRaw('LOWER(table_name) = ?', [strtolower($canonicalTable)])
            ->whereRaw('LOWER(column_name) = ?', [strtolower($column)])
            ->where('index_type', $indexType)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();
    }

    public function dismissConflictingActiveTypes(string $table, string $column, string $keepIndexType): void
    {
        $canonicalTable = $this->schema->canonicalTableName($table) ?? $table;

        foreach (['INDEX', 'REDUNDANT_CHECK'] as $type) {
            if ($type === $keepIndexType) {
                continue;
            }

            $rows = DB::table('index_advisor_recommendations')
                ->whereRaw('LOWER(table_name) = ?', [strtolower($canonicalTable)])
                ->whereRaw('LOWER(column_name) = ?', [strtolower($column)])
                ->where('index_type', $type)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->get();

            foreach ($rows as $rec) {
                $this->dismiss(
                    $rec,
                    "Live schema sync: replacing {$type} with {$keepIndexType} for this column."
                );
            }
        }
    }

    public function dismiss(object $rec, string $reason): void
    {
        $evidence = json_decode($rec->evidence ?? '{}', true);
        $evidence['dismissed_at'] = now()->toDateTimeString();
        $evidence['dismiss_reason'] = $reason;
        $evidence['reconciled'] = true;

        DB::table('index_advisor_recommendations')
            ->where('id', $rec->id)
            ->update([
                'status' => 'dismissed',
                'evidence' => json_encode($evidence),
                'updated_at' => now(),
            ]);
    }
}
