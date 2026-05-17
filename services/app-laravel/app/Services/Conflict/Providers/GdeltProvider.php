<?php

namespace App\Services\Conflict\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * GDELT Provider Service
 * 
 * Global Database of Events, Language, and Tone
 * Source: GDELT Project (realtime global media monitoring)
 * 
 * Coverage: Global, all countries
 * Update: Every 15 minutes (realtime)
 * Access: Open (no restrictions)
 * 
 * Use Cases:
 * - Media amplification tracking
 * - Weak signal detection
 * - Contextual monitoring
 * - Event volume analysis
 * 
 * Signal Type: WEAK_SIGNAL
 */
class GdeltProvider
{
    protected string $apiBase = 'https://api.gdeltproject.org/api/v2';
    
    /**
     * GDELT uses CAMEO codes similar to ICEWS
     * Focus on conflict-related codes
     */
    const CONFLICT_CAMEO_CODES = [
        '13' => 'Threaten',      // Threaten
        '14' => 'Protest',       // Protest
        '17' => 'Coerce',        // Coerce
        '18' => 'Assault',       // Assault
        '19' => 'Fight',         // Fight
        '20' => 'Unconventional', // Use unconventional violence
    ];

    /**
     * Fetch events from GDELT API
     * 
     * GDELT API parameters:
     * - query: search terms
     * - mode: artlist (articles) or timeline
     * - format: json
     * - maxrecords: limit results
     * - startdatetime: YYYYMMDDHHMMSS
     * - enddatetime: YYYYMMDDHHMMSS
     */
    public function fetchEvents(
        string $countryName,
        Carbon $startDate,
        Carbon $endDate,
        int $maxRecords = 250
    ): array {
        Log::info("Fetching GDELT events", [
            'country' => $countryName,
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d'),
        ]);

        // GDELT query format
        $query = "sourcelang:eng AND {$countryName}";
        
        $url = "{$this->apiBase}/doc/doc";
        
        try {
            $response = Http::timeout(60)->get($url, [
                'query' => $query,
                'mode' => 'artlist',
                'format' => 'json',
                'maxrecords' => $maxRecords,
                'startdatetime' => $startDate->format('YmdHis'),
                'enddatetime' => $endDate->format('YmdHis'),
                'sort' => 'datedesc',
            ]);

            if (!$response->successful()) {
                throw new \Exception("GDELT API failed: HTTP " . $response->status());
            }

            $data = $response->json();
            
            if (empty($data['articles'])) {
                Log::info("No GDELT articles found for query");
                return [];
            }

            // Parse articles into events
            $events = $this->parseArticles($data['articles']);
            
            Log::info("GDELT events fetched", [
                'articles' => count($data['articles']),
                'events_extracted' => count($events),
            ]);

            return $events;

        } catch (\Exception $e) {
            Log::error("GDELT fetch failed", [
                'error' => $e->getMessage(),
                'country' => $countryName,
            ]);
            
            throw $e;
        }
    }

    /**
     * Parse GDELT articles into event format
     */
    protected function parseArticles(array $articles): array
    {
        $events = [];

        foreach ($articles as $article) {
            // Extract event data from article
            // GDELT V2 provides rich metadata
            
            if (empty($article['url'])) {
                continue;
            }

            // Extract location if available
            $latitude = null;
            $longitude = null;
            $location = null;

            if (!empty($article['locations'])) {
                $firstLocation = $article['locations'][0] ?? null;
                if ($firstLocation) {
                    $latitude = $firstLocation['lat'] ?? null;
                    $longitude = $firstLocation['lon'] ?? null;
                    $location = $firstLocation['name'] ?? null;
                }
            }

            // Extract themes (GDELT's event classification)
            $themes = $article['themes'] ?? [];
            
            // Determine if this is a conflict event
            $isConflictRelated = $this->isConflictRelated($themes, $article['title'] ?? '');
            
            if (!$isConflictRelated) {
                continue;
            }

            // Extract CAMEO codes if available
            $cameoCode = $this->extractCameoCode($article);

            // Goldstein scale (-10 to +10, negative = conflict)
            $goldsteinScale = $article['goldstein'] ?? 0;

            $events[] = [
                'event_id' => $article['url_hash'] ?? md5($article['url']),
                'event_date' => $this->parseGdeltDate($article['seendate'] ?? $article['publishdate'] ?? ''),
                'event_text' => $article['title'] ?? '',
                'source_url' => $article['url'],
                'source_name' => $article['domain'] ?? 'Unknown',
                'cameo_code' => $cameoCode,
                'goldstein_scale' => $goldsteinScale,
                'tone' => $article['tone'] ?? null,
                'themes' => $themes,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location_name' => $location,
                'language' => $article['language'] ?? 'eng',
                'mentions' => $article['socialimage'] ?? null, // Social media mentions
            ];
        }

        return $events;
    }

    /**
     * Check if article is conflict-related
     */
    protected function isConflictRelated(array $themes, string $title): bool
    {
        $conflictKeywords = [
            'violence', 'attack', 'assault', 'clash', 'protest',
            'conflict', 'fight', 'battle', 'kill', 'death',
            'armed', 'militant', 'rebel', 'crisis', 'unrest',
        ];

        // Check themes
        foreach ($themes as $theme) {
            foreach ($conflictKeywords as $keyword) {
                if (stripos($theme, $keyword) !== false) {
                    return true;
                }
            }
        }

        // Check title
        $titleLower = strtolower($title);
        foreach ($conflictKeywords as $keyword) {
            if (stripos($titleLower, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract CAMEO code from article metadata
     */
    protected function extractCameoCode(array $article): ?string
    {
        // GDELT V2 doesn't always provide CAMEO codes directly
        // Infer from themes or use generic codes
        
        $themes = $article['themes'] ?? [];
        
        foreach ($themes as $theme) {
            if (stripos($theme, 'protest') !== false) return '14';
            if (stripos($theme, 'assault') !== false) return '18';
            if (stripos($theme, 'fight') !== false) return '19';
            if (stripos($theme, 'threat') !== false) return '13';
        }

        // Default to generic conflict code
        return '19'; // Fight
    }

    /**
     * Parse GDELT date format (YYYYMMDDHHMMSS)
     */
    protected function parseGdeltDate(string $dateStr): string
    {
        if (strlen($dateStr) >= 8) {
            $year = substr($dateStr, 0, 4);
            $month = substr($dateStr, 4, 2);
            $day = substr($dateStr, 6, 2);
            
            return "{$year}-{$month}-{$day}";
        }

        return now()->format('Y-m-d');
    }

    /**
     * Get event type from CAMEO code
     */
    public function getEventTypeFromCameo(string $cameoCode): ?string
    {
        $rootCode = substr($cameoCode, 0, 2);
        return self::CONFLICT_CAMEO_CODES[$rootCode] ?? null;
    }
}