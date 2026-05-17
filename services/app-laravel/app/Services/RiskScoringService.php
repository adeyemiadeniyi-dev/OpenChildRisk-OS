<?php

namespace App\Services;

use App\Models\District;
use App\Models\DistrictRiskAssessment;
use App\Models\ClimateObservation;
use App\Models\ConflictEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Risk Scoring Service
 * 
 * Explainable compound risk scoring engine.
 * 
 * Philosophy:
 * - Interpretable over complex
 * - Auditable over black-box
 * - Human-readable over ML hype
 * 
 * Scoring Components:
 * 1. Climate Score (rainfall anomalies, drought indicators)
 * 2. Conflict Score (ACLED events, fatalities, displacement)
 * 3. Vulnerability Score (WASH, health infrastructure, population)
 * 4. Access Score (operational constraints, security)
 * 
 * Output: Explainable risk assessment with recommendations
 */
class RiskScoringService
{
    // Scoring weights (must sum to 1.0)
    const DEFAULT_WEIGHTS = [
        'climate' => 0.30,
        'conflict' => 0.25,
        'vulnerability' => 0.30,
        'access' => 0.15,
    ];

    // Risk level thresholds
    const RISK_THRESHOLDS = [
        'critical' => 7.5,
        'high' => 5.5,
        'medium' => 3.5,
        'low' => 0,
    ];

    /**
     * Calculate comprehensive risk assessment for a district
     */
    public function assessDistrict(
        District $district,
        ?Carbon $assessmentDate = null,
        int $daysAnalyzed = 30
    ): DistrictRiskAssessment {

        $assessmentDate = $assessmentDate ?? Carbon::today();

        Log::info("Calculating risk assessment for {$district->name}", [
            'district_id' => $district->id,
            'date' => $assessmentDate->format('Y-m-d'),
        ]);

        // Calculate component scores
        $climateScore = $this->calculateClimateScore($district, $assessmentDate, $daysAnalyzed);
        $conflictScore = $this->calculateConflictScore($district, $assessmentDate, $daysAnalyzed);
        $vulnerabilityScore = $this->calculateVulnerabilityScore($district);
        $accessScore = $this->calculateAccessScore($district);

        // Calculate composite
        $composite = $this->calculateCompositeScore([
            'climate' => $climateScore['score'],
            'conflict' => $conflictScore['score'],
            'vulnerability' => $vulnerabilityScore['score'],
            'access' => $accessScore['score'],
        ]);

        // Determine risk level
        $riskLevel = $this->determineRiskLevel($composite['score']);

        // Generate explanation
        $explanation = $this->generateExplanation(
            $district,
            $climateScore,
            $conflictScore,
            $vulnerabilityScore,
            $accessScore,
            $composite
        );

        // Determine recommendation level
        $recommendationLevel = $this->determineRecommendationLevel($composite['score'], $accessScore['score']);

        // Create or update assessment
        $assessment = DistrictRiskAssessment::updateOrCreate(
            [
                'district_id' => $district->id,
                'assessment_date' => $assessmentDate,
            ],
            [
                'climate_score' => $climateScore['score'],
                'climate_factors' => $climateScore['factors'],
                'conflict_score' => $conflictScore['score'],
                'conflict_factors' => $conflictScore['factors'],
                'health_score' => null, // TODO: Implement
                'health_factors' => null,
                'vulnerability_score' => $vulnerabilityScore['score'],
                'vulnerability_factors' => $vulnerabilityScore['factors'],
                'access_score' => $accessScore['score'],
                'access_factors' => $accessScore['factors'],
                'composite_score' => $composite['score'],
                'risk_level' => $riskLevel,
                'confidence_score' => $composite['confidence'],
                'primary_drivers' => $composite['drivers'],
                'explanation' => $explanation,
                'recommendation_level' => $recommendationLevel,
                'recommended_interventions' => $this->recommendInterventions($composite, $vulnerabilityScore, $climateScore),
                'population_at_risk' => $district->population ?? 0,
                'calculation_method' => 'v1.0',
                'scoring_weights' => self::DEFAULT_WEIGHTS,
                'data_sources' => $composite['sources'],
                'data_completeness' => $composite['completeness'],
                'days_analyzed' => $daysAnalyzed,
                'calculated_at' => now(),
                'calculated_by' => 'system',
            ]
        );

        Log::info("Risk assessment complete", [
            'district' => $district->name,
            'risk_level' => $riskLevel,
            'composite_score' => $composite['score'],
            'climate_score' => $climateScore['score'],
        ]);

        return $assessment;
    }

    /**
     * Calculate climate risk score from CHIRPS rainfall data
     */
    protected function calculateClimateScore(District $district, Carbon $date, int $days): array
    {
        $startDate = $date->copy()->subDays($days);

        // Get rainfall observations for the analysis period
        $observations = ClimateObservation::where('district_id', $district->id)
            ->whereBetween('observation_date', [$startDate, $date])
            ->orderBy('observation_date')
            ->get();

        if ($observations->isEmpty()) {
            return [
                'score' => 0.0,
                'factors' => [
                    'rainfall_anomaly_pct' => 0,
                    'days_analyzed' => $days,
                    'data_available' => false,
                    'message' => 'No climate data available for this period',
                ],
            ];
        }

        // Calculate total rainfall for period
        $totalRainfall = $observations->sum('rainfall_mm');
        $avgDailyRainfall = $totalRainfall / $observations->count();

        // Get district climate zone for baseline comparison
        $climaticBaseline = $this->getClimaticBaseline($district, $date);

        // Calculate anomaly percentage
        $expectedRainfall = $climaticBaseline['expected_mm_per_day'] * $days;
        $anomalyPct = $expectedRainfall > 0
            ? (($totalRainfall - $expectedRainfall) / $expectedRainfall) * 100
            : 0;

        // Scoring logic:
        // Extreme drought (< -50%): 9-10 points
        // Severe drought (-30% to -50%): 7-9 points
        // Moderate drought (-15% to -30%): 5-7 points
        // Normal (-15% to +15%): 0-2 points
        // Above normal (+15% to +30%): 2-4 points
        // Heavy rainfall (+30% to +50%): 4-6 points
        // Extreme rainfall (> +50%): 6-8 points

        $score = 0;

        if ($anomalyPct < -50) {
            $score = 9 + min(abs($anomalyPct - 50) / 20, 1); // 9-10
        } elseif ($anomalyPct < -30) {
            $score = 7 + (abs($anomalyPct + 30) / 20) * 2; // 7-9
        } elseif ($anomalyPct < -15) {
            $score = 5 + (abs($anomalyPct + 15) / 15) * 2; // 5-7
        } elseif ($anomalyPct <= 15) {
            $score = abs($anomalyPct) / 15 * 2; // 0-2 (near normal)
        } elseif ($anomalyPct <= 30) {
            $score = 2 + ($anomalyPct - 15) / 15 * 2; // 2-4
        } elseif ($anomalyPct <= 50) {
            $score = 4 + ($anomalyPct - 30) / 20 * 2; // 4-6
        } else {
            $score = 6 + min(($anomalyPct - 50) / 30, 2); // 6-8
        }

        // Cap at 10
        $score = min($score, 10.0);

        // Identify heavy rainfall events (potential flooding)
        $heavyRainfallDays = $observations->filter(function ($obs) {
            return $obs->rainfall_mm > 20; // >20mm in a day
        })->count();

        // Check for consecutive dry days (drought indicator)
        $maxConsecutiveDryDays = $this->calculateMaxConsecutiveDryDays($observations);

        return [
            'score' => round($score, 2),
            'factors' => [
                'total_rainfall_mm' => round($totalRainfall, 2),
                'average_daily_mm' => round($avgDailyRainfall, 2),
                'expected_rainfall_mm' => round($expectedRainfall, 2),
                'rainfall_anomaly_pct' => round($anomalyPct, 2),
                'days_analyzed' => $observations->count(),
                'days_requested' => $days,
                'data_available' => true,
                'heavy_rainfall_days' => $heavyRainfallDays,
                'max_consecutive_dry_days' => $maxConsecutiveDryDays,
                'climate_zone' => $climaticBaseline['zone'],
                'condition' => $this->getClimateCondition($anomalyPct),
            ],
        ];
    }

    /**
     * Get climatic baseline for district
     */
    protected function getClimaticBaseline(District $district, Carbon $date): array
    {
        $month = $date->month;

        // Far North (Sahel)
        $sahelDistricts = ['Mora', 'Makary', 'Kousseri', 'Maroua', 'Kolofata', 'Logone-Birni', 'Yagoua'];

        if (in_array($district->name, $sahelDistricts)) {
            // Sahel: Rainy season July-September, dry season November-March
            if ($month >= 7 && $month <= 9) {
                return ['zone' => 'Sahel', 'expected_mm_per_day' => 4.0]; // ~120mm/month
            } elseif ($month >= 11 || $month <= 3) {
                return ['zone' => 'Sahel', 'expected_mm_per_day' => 0.3]; // ~10mm/month (dry)
            } else {
                return ['zone' => 'Sahel', 'expected_mm_per_day' => 2.0]; // ~60mm/month
            }
        }

        // East Region (Rainforest)
        if ($month >= 9 && $month <= 11) {
            return ['zone' => 'Rainforest', 'expected_mm_per_day' => 6.0]; // ~180mm/month
        } elseif ($month >= 12 || $month <= 2) {
            return ['zone' => 'Rainforest', 'expected_mm_per_day' => 2.5]; // ~75mm/month
        } else {
            return ['zone' => 'Rainforest', 'expected_mm_per_day' => 4.5]; // ~135mm/month
        }
    }

    /**
     * Calculate maximum consecutive dry days
     */
    protected function calculateMaxConsecutiveDryDays($observations): int
    {
        $maxDry = 0;
        $currentDry = 0;

        foreach ($observations as $obs) {
            if ($obs->rainfall_mm < 1.0) {
                $currentDry++;
                $maxDry = max($maxDry, $currentDry);
            } else {
                $currentDry = 0;
            }
        }

        return $maxDry;
    }

    /**
     * Get human-readable climate condition
     */
    protected function getClimateCondition(float $anomalyPct): string
    {
        if ($anomalyPct < -50) return 'extreme_drought';
        if ($anomalyPct < -30) return 'severe_drought';
        if ($anomalyPct < -15) return 'moderate_drought';
        if ($anomalyPct <= 15) return 'normal';
        if ($anomalyPct <= 30) return 'above_normal';
        if ($anomalyPct <= 50) return 'heavy_rainfall';
        return 'extreme_rainfall';
    }

    /**
     * Calculate conflict score for a district
     * Uses real conflict event data with signal-type weighting
     */
    protected function calculateConflictScore(District $district, Carbon $date, int $days): array
    {
        $startDate = $date->copy()->subDays($days);

        // Get recent conflict events
        $recentEvents = ConflictEvent::where('district_id', $district->id)
            ->whereBetween('event_date', [$startDate, $date])
            ->where('status', 'active')
            ->get();

        if ($recentEvents->isEmpty()) {
            return [
                'score' => 0.0,
                'factors' => [
                    'event_count' => 0,
                    'days_analyzed' => $days,
                    'data_available' => false,
                    'message' => 'No conflict events in this period',
                ],
            ];
        }

        // Weight events by signal type
        $signalWeights = [
            'operational' => 1.0,    // ACLED: highest weight
            'predictive' => 0.7,     // ICEWS: medium weight
            'weak_signal' => 0.4,    // GDELT: lower weight
            'contextual' => 0.3,     // Other sources
        ];

        $totalWeightedScore = 0;
        $totalWeight = 0;
        $totalFatalities = 0;
        $eventsByType = [];
        $eventsByProvider = [];

        foreach ($recentEvents as $event) {
            $signalType = $event->getSignalType() ?? 'contextual';
            $weight = $signalWeights[$signalType] ?? 0.5;

            // Get composite confidence (includes cross-source boost)
            $dedup = new \App\Services\Conflict\EventDeduplicationService();
            $confidence = $dedup->getCompositeConfidence($event);

            // Time decay (more recent = higher weight)
            $daysAgo = $date->diffInDays($event->event_date);
            $timeDecay = max(0, 1 - ($daysAgo / $days));

            // Weighted score
            $eventScore = (
                $event->severity_score / 10 *  // Normalize to 0-1
                $confidence *                   // Source confidence
                $weight *                       // Signal type weight
                $timeDecay                      // Recency
            );

            $totalWeightedScore += $eventScore;
            $totalWeight += ($weight * $timeDecay);
            $totalFatalities += $event->fatalities ?? 0;

            // Track event types and providers
            $category = $event->conflictCategory->code ?? 'UNKNOWN';
            $eventsByType[$category] = ($eventsByType[$category] ?? 0) + 1;
            $eventsByProvider[$event->source_provider] = ($eventsByProvider[$event->source_provider] ?? 0) + 1;
        }

        // Average weighted score, scaled to 0-10
        $conflictScore = $totalWeight > 0
            ? ($totalWeightedScore / $totalWeight) * 10
            : 0;

        return [
            'score' => min(10.0, round($conflictScore, 2)),
            'factors' => [
                'event_count' => $recentEvents->count(),
                'total_fatalities' => $totalFatalities,
                'days_analyzed' => $days,
                'data_available' => true,
                'events_by_type' => $eventsByType,
                'events_by_provider' => $eventsByProvider,
                'signal_weighted' => true,
            ],
        ];
    }

    /**
     * Calculate vulnerability score (WASH, health, population)
     */
    protected function calculateVulnerabilityScore(District $district): array
    {
        $score = 0.0;

        $washCoverage = $district->wash_coverage ?? 0;
        $washScore = 10 - ($washCoverage * 10);
        $score += $washScore * 0.4;

        $sanitationCoverage = $district->sanitation_coverage ?? 0;
        $sanitationScore = 10 - ($sanitationCoverage * 10);
        $score += $sanitationScore * 0.3;

        $population = $district->population ?? 0;
        $area = $district->area_sq_km ?? 1;
        $density = $population / $area;
        $densityScore = min($density / 50, 10);
        $score += $densityScore * 0.3;

        return [
            'score' => round(min($score, 10.0), 2),
            'factors' => [
                'wash_coverage' => $washCoverage,
                'sanitation_coverage' => $sanitationCoverage,
                'population' => $population,
                'population_density' => round($density, 2),
            ],
        ];
    }

    /**
     * Calculate access score (operational feasibility)
     */
    protected function calculateAccessScore(District $district): array
    {
        $constraint = DB::table('district_access_constraints')
            ->where('district_id', $district->id)
            ->where('assessed_at', '>=', now()->subDays(90))
            ->orderBy('assessed_at', 'desc')
            ->first();

        if (!$constraint) {
            return [
                'score' => 0.0,
                'factors' => [
                    'access_level' => 'full',
                    'assessment_available' => false,
                ],
            ];
        }

        $accessLevelScores = [
            'full' => 0,
            'partial' => 3,
            'limited' => 6,
            'no_access' => 10,
        ];

        $score = $accessLevelScores[$constraint->access_level] ?? 0;

        if (!$constraint->road_access) $score += 2;
        if (!$constraint->humanitarian_access) $score += 3;

        return [
            'score' => min($score, 10.0),
            'factors' => [
                'access_level' => $constraint->access_level,
                'road_access' => $constraint->road_access,
                'humanitarian_access' => $constraint->humanitarian_access,
                'security_risk_level' => $constraint->security_risk_level,
            ],
        ];
    }

    /**
     * Calculate weighted composite score
     */
    protected function calculateCompositeScore(array $componentScores): array
    {
        $weights = self::DEFAULT_WEIGHTS;
        $score = 0.0;
        $dataSources = [];
        $dataPresent = [];

        foreach ($componentScores as $component => $value) {
            if ($value !== null) {
                $score += $value * $weights[$component];
                $dataPresent[$component] = true;
                $dataSources[] = $component;
            } else {
                $dataPresent[$component] = false;
            }
        }

        $completeness = count(array_filter($dataPresent)) / count($dataPresent);

        $weightedScores = [];
        foreach ($componentScores as $component => $value) {
            if ($value !== null) {
                $weightedScores[$component] = $value * $weights[$component];
            }
        }
        arsort($weightedScores);
        $drivers = array_keys(array_slice($weightedScores, 0, 3));

        return [
            'score' => round($score, 2),
            'confidence' => $completeness,
            'drivers' => $drivers,
            'sources' => $dataSources,
            'completeness' => $completeness,
        ];
    }

    protected function determineRiskLevel(float $score): string
    {
        foreach (self::RISK_THRESHOLDS as $level => $threshold) {
            if ($score >= $threshold) {
                return $level;
            }
        }
        return 'low';
    }

    protected function determineRecommendationLevel(float $compositeScore, float $accessScore): string
    {
        if ($accessScore >= 8 && $compositeScore >= 7) {
            return 'monitor';
        }

        if ($compositeScore >= 7.5) return 'emergency';
        if ($compositeScore >= 5.5) return 'respond';
        if ($compositeScore >= 3.5) return 'prepare';
        return 'monitor';
    }

    protected function generateExplanation(
        District $district,
        array $climate,
        array $conflict,
        array $vulnerability,
        array $access,
        array $composite
    ): string {

        $riskLevel = $this->determineRiskLevel($composite['score']);
        $riskLevelText = strtoupper($riskLevel);

        $lines = ["Why {$district->name} is {$riskLevelText} (Score: {$composite['score']}/10):"];

        // Climate factors
        if ($climate['score'] > 5 && isset($climate['factors']['data_available']) && $climate['factors']['data_available']) {
            $anomaly = $climate['factors']['rainfall_anomaly_pct'];
            $condition = $climate['factors']['condition'];
            $lines[] = "- Climate: {$condition} (anomaly: " . round($anomaly, 1) . "%)";
        }

        // Conflict factors
        if ($conflict['score'] > 5) {
            $lines[] = "- Conflict escalation: {$conflict['factors']['event_count']} events in last {$conflict['factors']['days_analyzed']} days";
            if ($conflict['factors']['total_fatalities'] > 0) {
                $lines[] = "  → {$conflict['factors']['total_fatalities']} fatalities reported";
            }
        } elseif ($conflict['factors']['event_count'] > 0) {
            $lines[] = "- Conflict activity: {$conflict['factors']['event_count']} events (monitored)";
        }

        // Vulnerability factors
        if ($vulnerability['score'] > 5) {
            $wash = round($vulnerability['factors']['wash_coverage'] * 100);
            $sanitation = round($vulnerability['factors']['sanitation_coverage'] * 100);
            $lines[] = "- Infrastructure vulnerability:";
            $lines[] = "  → WASH coverage: {$wash}%";
            $lines[] = "  → Sanitation coverage: {$sanitation}%";
        }

        // Access constraints
        if ($access['score'] > 5) {
            $accessLevel = $access['factors']['access_level'];
            $lines[] = "- Operational constraints: {$accessLevel} access";
            if (isset($access['factors']['road_access']) && !$access['factors']['road_access']) {
                $lines[] = "  → Road access limited";
            }
            if (isset($access['factors']['humanitarian_access']) && !$access['factors']['humanitarian_access']) {
                $lines[] = "  → Humanitarian access restricted";
            }
        }

        $population = number_format($district->population ?? 0);
        $lines[] = "- Population at risk: {$population}";

        return implode("\n", $lines);
    }

    protected function recommendInterventions(array $composite, array $vulnerability, array $climate): array
    {
        $interventions = [];

        if ($vulnerability['factors']['wash_coverage'] < 0.5) {
            $interventions[] = 'WASH';
        }

        if ($vulnerability['factors']['sanitation_coverage'] < 0.5) {
            $interventions[] = 'Sanitation';
        }

        if ($composite['score'] > 7) {
            $interventions[] = 'Health screening';
            $interventions[] = 'Nutrition assessment';
        }

        // Climate-specific interventions
        if (isset($climate['factors']['condition'])) {
            if (in_array($climate['factors']['condition'], ['severe_drought', 'extreme_drought'])) {
                $interventions[] = 'Water trucking';
            }
            if (in_array($climate['factors']['condition'], ['heavy_rainfall', 'extreme_rainfall'])) {
                $interventions[] = 'Flood preparedness';
            }
        }

        return array_unique($interventions);
    }
}
