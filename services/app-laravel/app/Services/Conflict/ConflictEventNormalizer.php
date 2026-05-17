<?php

namespace App\Services\Conflict;

use App\Models\ConflictEvent;
use App\Models\ConflictCategory;
use App\Models\ConflictProviderSource;
use App\Models\Country;
use App\Models\District;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Conflict Event Normalizer
 * 
 * Provider-agnostic event normalization service.
 * Transforms provider-specific data into canonical format.
 * 
 * Philosophy: Single normalized schema, explainable transformations.
 */
class ConflictEventNormalizer
{
    /**
     * Canonical category mapping
     * Based on CONFLICT_ONTOLOGY.md v1.0
     * Updated to match database codes
     */
    const CATEGORY_MAPPING = [
        // ACLED mappings
        'ACLED' => [
            'Battles' => 'BATTLES',
            'Explosions/Remote violence' => 'EXPLOSIONS',
            'Violence against civilians' => 'VIOLENCE_CIVILIANS',
            'Protests' => 'PROTESTS',
            'Riots' => 'RIOTS',
            'Strategic developments' => 'STRATEGIC_DEVELOPMENTS',
        ],
        // ICEWS mappings (future)
        'ICEWS' => [
            'Assault' => 'BATTLES',
            'Fight' => 'BATTLES',
            'Protest' => 'PROTESTS',
            'Threaten' => 'STRATEGIC_DEVELOPMENTS',
            'Coerce' => 'VIOLENCE_CIVILIANS',
        ],
        // GDELT mappings (future)
        'GDELT' => [
            '18*' => 'BATTLES',  // Assault
            '19*' => 'BATTLES',  // Fight
            '14*' => 'PROTESTS',    // Protest
        ],
    ];

    /**
     * Base severity scores by category
     * From CONFLICT_ONTOLOGY.md
     */
    const BASE_SEVERITY = [
        'VIOLENCE_CIVILIANS' => 9.0,
        'EXPLOSIONS' => 8.0,
        'BATTLES' => 7.0,
        'DISPLACEMENT_EVENT' => 6.0,
        'RIOTS' => 4.5,
        'PROTESTS' => 4.0,
        'STRATEGIC_DEVELOPMENTS' => 2.0,
    ];

    /**
     * Signal type by provider
     */
    const SIGNAL_TYPES = [
        'ACLED' => 'operational',
        'ICEWS' => 'predictive',
        'GDELT' => 'weak_signal',
    ];

    protected ConflictProviderSource $providerSource;

    public function __construct(string $providerCode)
    {
        $this->providerSource = ConflictProviderSource::where('code', $providerCode)->firstOrFail();
    }

    /**
     * Normalize event from provider-specific format
     */
    public function normalize(array $rawEvent, Country $country): array
    {
        $provider = $this->providerSource->code;

        // Extract common fields
        $normalized = [
            'source_provider' => $provider,
            'provider_raw_data' => $rawEvent,
            'country_id' => $country->id,
            'status' => 'active',
        ];

        // Provider-specific extraction
        switch ($provider) {
            case 'ACLED':
                $normalized = array_merge($normalized, $this->normalizeAcled($rawEvent));
                break;
            case 'ICEWS':
                $normalized = array_merge($normalized, $this->normalizeIcews($rawEvent));
                break;
            case 'GDELT':
                $normalized = array_merge($normalized, $this->normalizeGdelt($rawEvent));
                break;
            default:
                throw new \Exception("Unknown provider: {$provider}");
        }

        // Add confidence scores
        $normalized['source_confidence'] = $this->providerSource->getCompositeConfidence();

        // Add signal type
        $normalized['metadata'] = array_merge(
            $normalized['metadata'] ?? [],
            ['signal_type' => self::SIGNAL_TYPES[$provider] ?? 'contextual']
        );

        // Generate canonical hash for deduplication
        $normalized['canonical_event_hash'] = ConflictEvent::generateCanonicalHash(
            $normalized['event_date'],
            $normalized['latitude'],
            $normalized['longitude'],
            $normalized['canonical_category'],
            $normalized['fatalities'] ?? 0
        );

        // Set deduplication parameters
        $normalized['temporal_window_hours'] = 48;
        $normalized['spatial_radius_km'] = 50.0;

        return $normalized;
    }

    /**
     * Normalize ACLED event
     */
    protected function normalizeAcled(array $event): array
    {
        // Map to canonical category
        $eventType = $event['event_type'] ?? '';
        $canonicalCategory = self::CATEGORY_MAPPING['ACLED'][$eventType] ?? null;

        if (!$canonicalCategory) {
            throw new \Exception("Unknown ACLED event type: {$eventType}");
        }

        $category = ConflictCategory::where('code', $canonicalCategory)->first();
        if (!$category) {
            throw new \Exception("Canonical category not found: {$canonicalCategory}");
        }

        // Find district by coordinates
        $district = $this->findDistrictByCoordinates(
            (float) $event['latitude'],
            (float) $event['longitude']
        );

        // Calculate severity
        $severity = $this->calculateSeverity(
            $canonicalCategory,
            (int) ($event['fatalities'] ?? 0),
            null // No displacement data in ACLED
        );

        return [
            'external_id' => $event['event_id_cnty'],
            'conflict_category_id' => $category->id,
            'canonical_category' => $canonicalCategory,
            'district_id' => $district?->id,
            'event_date' => Carbon::parse($event['event_date']),
            'sub_event_type' => $event['sub_event_type'] ?? null,
            'notes' => $event['notes'] ?? null,
            'actors' => [
                'actor1' => $event['actor1'] ?? null,
                'actor2' => $event['actor2'] ?? null,
                'inter1' => $event['inter1'] ?? null,
                'inter2' => $event['inter2'] ?? null,
            ],
            'fatalities' => (int) ($event['fatalities'] ?? 0),
            'latitude' => (float) $event['latitude'],
            'longitude' => (float) $event['longitude'],
            'location_name' => $event['location'] ?? null,
            'severity_score' => $severity,
            'metadata' => [
                'admin1' => $event['admin1'] ?? null,
                'admin2' => $event['admin2'] ?? null,
                'admin3' => $event['admin3'] ?? null,
                'source' => $event['source'] ?? null,
                'source_scale' => $event['source_scale'] ?? null,
            ],
        ];
    }

    /**
     * Normalize ICEWS event
     */
    protected function normalizeIcews(array $event): array
    {
        // Map CAMEO code to canonical category
        $cameoCode = $event['cameo_code'] ?? '';
        $rootCode = substr($cameoCode, 0, 2);

        // Determine canonical category from CAMEO root code
        $canonicalCategory = null;
        switch ($rootCode) {
            case '18': // Assault
            case '19': // Fight
            case '20': // Use unconventional violence
                $canonicalCategory = 'BATTLES';
                break;
            case '14': // Protest
                $canonicalCategory = 'PROTESTS';
                break;
            case '13': // Threaten
            case '16': // Reduce relations
                $canonicalCategory = 'STRATEGIC_DEVELOPMENTS';
                break;
            case '17': // Coerce
                $canonicalCategory = 'VIOLENCE_CIVILIANS';
                break;
            default:
                throw new \Exception("Unmapped CAMEO code: {$cameoCode}");
        }

        $category = ConflictCategory::where('code', $canonicalCategory)->first();
        if (!$category) {
            throw new \Exception("Canonical category not found: {$canonicalCategory}");
        }

        // Find district by coordinates
        $district = null;
        if (!empty($event['latitude']) && !empty($event['longitude'])) {
            $district = $this->findDistrictByCoordinates(
                (float) $event['latitude'],
                (float) $event['longitude']
            );
        }

        // ICEWS doesn't provide fatality counts in standard format
        // Use intensity as proxy (negative values indicate conflict intensity)
        $intensity = (float) ($event['intensity'] ?? 0);
        $estimatedFatalities = $intensity < -5 ? abs($intensity) : 0;

        // Calculate severity
        $severity = $this->calculateSeverity(
            $canonicalCategory,
            (int) $estimatedFatalities,
            null
        );

        return [
            'external_id' => $event['event_id'],
            'conflict_category_id' => $category->id,
            'canonical_category' => $canonicalCategory,
            'district_id' => $district?->id,
            'event_date' => Carbon::parse($event['event_date']),
            'sub_event_type' => $event['event_text'] ?? null,
            'notes' => "Source: {$event['source_name']} → Target: {$event['target_name']}",
            'actors' => [
                'source' => $event['source_name'] ?? null,
                'target' => $event['target_name'] ?? null,
                'source_country' => $event['source_country'] ?? null,
                'target_country' => $event['target_country'] ?? null,
            ],
            'fatalities' => (int) $estimatedFatalities,
            'latitude' => !empty($event['latitude']) ? (float) $event['latitude'] : null,
            'longitude' => !empty($event['longitude']) ? (float) $event['longitude'] : null,
            'location_name' => $event['city'] ?? $event['province'] ?? null,
            'severity_score' => $severity,
            'metadata' => [
                'cameo_code' => $cameoCode,
                'intensity' => $intensity,
                'publisher' => $event['publisher'] ?? null,
                'story_id' => $event['story_id'] ?? null,
                'province' => $event['province'] ?? null,
            ],
        ];
    }

    /**
     * Find district by coordinates (Haversine)
     */
    protected function findDistrictByCoordinates(float $lat, float $lng): ?District
    {
        $districts = District::selectRaw('
            *,
            (
                6371 * acos(
                    cos(radians(?)) * cos(radians(centroid_lat)) *
                    cos(radians(centroid_lng) - radians(?)) +
                    sin(radians(?)) * sin(radians(centroid_lat))
                )
            ) AS distance
        ', [$lat, $lng, $lat])
            ->get();

        // Filter by distance in PHP (since HAVING doesn't work without GROUP BY in this context)
        $filtered = $districts->filter(function ($district) {
            return $district->distance < 50;
        });

        return $filtered->sortBy('distance')->first();
    }

    /**
     * Calculate severity score
     * 
     * Formula: base_severity + fatality_modifier + displacement_modifier
     * Capped at 10.0
     */
    protected function calculateSeverity(
        string $canonicalCategory,
        int $fatalities,
        ?int $displaced
    ): float {
        $baseSeverity = self::BASE_SEVERITY[$canonicalCategory] ?? 5.0;

        // Fatality modifier (max +5 points)
        $fatalityScore = min($fatalities / 2, 5.0);

        // Displacement modifier (max +3 points)
        $displacementScore = $displaced ? min($displaced / 1000, 3.0) : 0;

        $severity = $baseSeverity + $fatalityScore + $displacementScore;

        return min(round($severity, 2), 10.0);
    }

    /**
     * Normalize GDELT event
     */
    protected function normalizeGdelt(array $event): array
    {
        // Map CAMEO code to canonical category
        $cameoCode = $event['cameo_code'] ?? '19';
        $rootCode = substr($cameoCode, 0, 2);

        // Determine canonical category
        $canonicalCategory = null;
        switch ($rootCode) {
            case '18': // Assault
            case '19': // Fight
            case '20': // Use unconventional violence
                $canonicalCategory = 'BATTLES';
                break;
            case '14': // Protest
                $canonicalCategory = 'PROTESTS';
                break;
            case '13': // Threaten
                $canonicalCategory = 'STRATEGIC_DEVELOPMENTS';
                break;
            case '17': // Coerce
                $canonicalCategory = 'VIOLENCE_CIVILIANS';
                break;
            default:
                $canonicalCategory = 'BATTLES'; // Default
        }

        $category = ConflictCategory::where('code', $canonicalCategory)->first();
        if (!$category) {
            throw new \Exception("Canonical category not found: {$canonicalCategory}");
        }

        // Find district by coordinates if available
        $district = null;
        if (!empty($event['latitude']) && !empty($event['longitude'])) {
            $district = $this->findDistrictByCoordinates(
                (float) $event['latitude'],
                (float) $event['longitude']
            );
        }

        // GDELT uses Goldstein scale (-10 to +10, negative = conflict)
        // Estimate fatalities from scale (rough approximation)
        $goldsteinScale = (float) ($event['goldstein_scale'] ?? 0);
        $estimatedFatalities = $goldsteinScale < -5 ? abs($goldsteinScale) : 0;

        // Calculate severity
        $severity = $this->calculateSeverity(
            $canonicalCategory,
            (int) $estimatedFatalities,
            null
        );

        return [
            'external_id' => $event['event_id'],
            'conflict_category_id' => $category->id,
            'canonical_category' => $canonicalCategory,
            'district_id' => $district?->id,
            'event_date' => Carbon::parse($event['event_date']),
            'sub_event_type' => $event['event_text'] ?? null,
            'notes' => "Media source: {$event['source_name']}",
            'actors' => [
                'source_name' => $event['source_name'] ?? null,
                'source_url' => $event['source_url'] ?? null,
            ],
            'fatalities' => (int) $estimatedFatalities,
            'latitude' => !empty($event['latitude']) ? (float) $event['latitude'] : null,
            'longitude' => !empty($event['longitude']) ? (float) $event['longitude'] : null,
            'location_name' => $event['location_name'] ?? null,
            'severity_score' => $severity,
            'metadata' => [
                'cameo_code' => $cameoCode,
                'goldstein_scale' => $goldsteinScale,
                'tone' => $event['tone'] ?? null,
                'themes' => $event['themes'] ?? [],
                'language' => $event['language'] ?? 'eng',
                'media_mentions' => $event['mentions'] ?? null,
            ],
        ];
    }
}
