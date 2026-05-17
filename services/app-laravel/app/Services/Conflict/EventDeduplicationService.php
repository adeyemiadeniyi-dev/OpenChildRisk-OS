<?php

namespace App\Services\Conflict;

use App\Models\ConflictEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Event Deduplication Service
 * 
 * Identifies duplicate conflict events across multiple providers.
 * Uses probabilistic matching: temporal + spatial + semantic similarity.
 * 
 * Philosophy: Same real-world event reported by different sources
 * should be linked, with confidence boosted by cross-validation.
 */
class EventDeduplicationService
{
    /**
     * Matching thresholds
     */
    const TEMPORAL_WINDOW_HOURS = 48;      // ±2 days
    const SPATIAL_RADIUS_KM = 50;          // 50km radius
    const FATALITY_TOLERANCE_PERCENT = 0.20; // ±20%
    const MIN_MATCH_SCORE = 0.70;          // 70% similarity required

    /**
     * Find potential duplicate events for a given event
     */
    public function findDuplicates(ConflictEvent $event): array
    {
        Log::info("Finding duplicates for event", [
            'event_id' => $event->id,
            'external_id' => $event->external_id,
            'provider' => $event->source_provider,
        ]);

        // Don't match events from same provider
        $candidates = ConflictEvent::where('id', '!=', $event->id)
            ->where('source_provider', '!=', $event->source_provider)
            ->where('conflict_category_id', $event->conflict_category_id)
            ->whereBetween('event_date', [
                $event->event_date->copy()->subHours(self::TEMPORAL_WINDOW_HOURS),
                $event->event_date->copy()->addHours(self::TEMPORAL_WINDOW_HOURS),
            ])
            ->get();

        $matches = [];

        foreach ($candidates as $candidate) {
            $score = $this->calculateMatchScore($event, $candidate);
            
            if ($score >= self::MIN_MATCH_SCORE) {
                $matches[] = [
                    'event_id' => $candidate->id,
                    'external_id' => $candidate->external_id,
                    'provider' => $candidate->source_provider,
                    'match_score' => $score,
                    'confidence_boost' => $this->calculateConfidenceBoost($event, $candidate),
                ];
                
                Log::info("Duplicate found", [
                    'event1' => $event->external_id,
                    'event2' => $candidate->external_id,
                    'score' => $score,
                ]);
            }
        }

        return $matches;
    }

    /**
     * Calculate match score between two events
     * 
     * Weighted combination of:
     * - Temporal proximity (30%)
     * - Spatial proximity (40%)
     * - Fatality similarity (20%)
     * - Category match (10%)
     */
    protected function calculateMatchScore(ConflictEvent $event1, ConflictEvent $event2): float
    {
        // Temporal score
        $hoursDiff = abs($event1->event_date->diffInHours($event2->event_date));
        $temporalScore = max(0, 1 - ($hoursDiff / self::TEMPORAL_WINDOW_HOURS));

        // Spatial score
        $distance = $this->calculateDistance(
            $event1->latitude,
            $event1->longitude,
            $event2->latitude,
            $event2->longitude
        );
        $spatialScore = max(0, 1 - ($distance / self::SPATIAL_RADIUS_KM));

        // Fatality score
        $fatalityScore = $this->calculateFatalitySimilarity(
            $event1->fatalities ?? 0,
            $event2->fatalities ?? 0
        );

        // Category score (already filtered, so always 1.0)
        $categoryScore = 1.0;

        // Weighted combination
        $matchScore = (
            $temporalScore * 0.30 +
            $spatialScore * 0.40 +
            $fatalityScore * 0.20 +
            $categoryScore * 0.10
        );

        return round($matchScore, 2);
    }

    /**
     * Calculate Haversine distance between two points
     */
    protected function calculateDistance(
        ?float $lat1,
        ?float $lng1,
        ?float $lat2,
        ?float $lng2
    ): float {
        if (!$lat1 || !$lng1 || !$lat2 || !$lng2) {
            return 999999; // Invalid coordinates
        }

        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Calculate fatality similarity
     */
    protected function calculateFatalitySimilarity(int $fatalities1, int $fatalities2): float
    {
        if ($fatalities1 === 0 && $fatalities2 === 0) {
            return 1.0; // Both zero
        }

        if ($fatalities1 === 0 || $fatalities2 === 0) {
            return 0.5; // One zero, one non-zero
        }

        $diff = abs($fatalities1 - $fatalities2);
        $avg = ($fatalities1 + $fatalities2) / 2;
        $percentDiff = $diff / $avg;

        if ($percentDiff <= self::FATALITY_TOLERANCE_PERCENT) {
            return 1.0;
        }

        // Decay score as difference increases
        return max(0, 1 - ($percentDiff - self::FATALITY_TOLERANCE_PERCENT) * 2);
    }

    /**
     * Calculate confidence boost from cross-source validation
     * 
     * When multiple independent sources report same event,
     * confidence increases (but never exceeds highest source)
     */
    protected function calculateConfidenceBoost(ConflictEvent $event1, ConflictEvent $event2): float
    {
        $confidence1 = $event1->source_confidence;
        $confidence2 = $event2->source_confidence;

        // Boost is percentage of gap to highest confidence
        $maxConfidence = max($confidence1, $confidence2);
        $avgConfidence = ($confidence1 + $confidence2) / 2;

        // 30% of the gap between average and max
        $boost = ($maxConfidence - $avgConfidence) * 0.30;

        return round($boost, 3);
    }

    /**
     * Link duplicate events
     */
    public function linkDuplicates(ConflictEvent $event, array $matches): void
    {
        if (empty($matches)) {
            return;
        }

        // Store cross-source matches in event metadata
        $existingMatches = $event->cross_source_matches ?? [];
        
        foreach ($matches as $match) {
            $existingMatches[] = [
                'event_id' => $match['event_id'],
                'provider' => $match['provider'],
                'match_score' => $match['match_score'],
                'confidence_boost' => $match['confidence_boost'],
                'matched_at' => now()->toISOString(),
            ];
        }

        $event->update([
            'cross_source_matches' => $existingMatches,
        ]);

        Log::info("Linked duplicates", [
            'event_id' => $event->id,
            'matches_count' => count($matches),
        ]);
    }

    /**
     * Get composite confidence for event (including cross-source boost)
     */
    public function getCompositeConfidence(ConflictEvent $event): float
    {
        $baseConfidence = $event->source_confidence;
        
        $matches = $event->cross_source_matches ?? [];
        
        if (empty($matches)) {
            return $baseConfidence;
        }

        // Add confidence boosts from all matches
        $totalBoost = array_sum(array_column($matches, 'confidence_boost'));
        
        // Confidence never exceeds 0.99
        return min(0.99, round($baseConfidence + $totalBoost, 2));
    }

    /**
     * Deduplicate all events in database
     * Run this periodically or after bulk imports
     */
    public function deduplicateAll(): array
    {
        Log::info("Starting full deduplication");

        $events = ConflictEvent::orderBy('event_date', 'desc')
            ->limit(1000) // Process in batches
            ->get();

        $stats = [
            'events_processed' => 0,
            'duplicates_found' => 0,
            'links_created' => 0,
        ];

        foreach ($events as $event) {
            $duplicates = $this->findDuplicates($event);
            
            if (!empty($duplicates)) {
                $this->linkDuplicates($event, $duplicates);
                $stats['duplicates_found']++;
                $stats['links_created'] += count($duplicates);
            }
            
            $stats['events_processed']++;
        }

        Log::info("Deduplication complete", $stats);

        return $stats;
    }
}