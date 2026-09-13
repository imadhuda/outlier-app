<?php
require_once __DIR__ . '/http.php';

/**
 * Apify client, async by design.
 *
 * The synchronous endpoint can run for up to 300 seconds; shared-hosting PHP is
 * killed at 120. So a scrape is started, its run id is stored, and later cron
 * ticks poll it. Nothing here ever blocks for longer than a single HTTP call.
 */
class Apify {
    const IG_SCRAPER   = 'apify~instagram-scraper';
    const IG_TRANSCRIPT= 'steadyfetch~instagram-reel-transcript-scraper';
    const YT_TRANSCRIPT= 'johnvc~YoutubeTranscripts';

    /* Published free-tier rates, used for cost estimates in the run log. */
    const COST_IG_POST   = 0.0023;
    const COST_TRANSCRIPT= 0.015;
    const COST_YT_VIDEO  = 0.0001;

    private $token;
    public function __construct($token) {
        if (!$token) throw new RuntimeException('Apify token is not set in config.php');
        $this->token = $token;
    }
    private function url($path, $q = []) {
        $q['token'] = $this->token;
        return 'https://api.apify.com/v2/' . ltrim($path, '/') . '?' . http_build_query($q);
    }

    /** Start a run. Returns the run id — never waits for it to finish. */
    public function start($actor, array $input, $memoryMb = 2048, $timeoutSecs = 600) {
        $d = Http::json('POST', $this->url("acts/$actor/runs", [
            'memory' => $memoryMb, 'timeout' => $timeoutSecs,
        ]), [], $input, 30);
        $id = $d['data']['id'] ?? null;
        if (!$id) throw new RuntimeException('Apify did not return a run id');
        return $id;
    }

    /** One small synchronous run. Only for tiny jobs (a single profile), never for scrapes. */
    public function runSync($actor, array $input, $timeoutSecs = 60) {
        return Http::json('POST', $this->url("acts/$actor/run-sync-get-dataset-items", [
            'memory' => 512, 'timeout' => $timeoutSecs,
        ]), [], $input, $timeoutSecs + 15) ?: [];
    }
    public static function igProfileInput($handle) {
        return ['directUrls' => ['https://www.instagram.com/' . $handle . '/'], 'resultsType' => 'details', 'resultsLimit' => 1];
    }

    /** ['status' => READY|RUNNING|SUCCEEDED|FAILED|ABORTED|TIMED-OUT, 'datasetId' => ...] */
    public function status($runId) {
        $d = Http::json('GET', $this->url("actor-runs/$runId"), [], null, 25);
        return [
            'status'    => $d['data']['status'] ?? 'UNKNOWN',
            'datasetId' => $d['data']['defaultDatasetId'] ?? null,
            'stats'     => $d['data']['stats'] ?? [],
        ];
    }
    public static function isTerminal($s) {
        return in_array($s, ['SUCCEEDED','FAILED','ABORTED','TIMED-OUT'], true);
    }

    /** Dataset items, paged so a big result never blows the memory limit. */
    public function items($datasetId, $limit = 200, $offset = 0) {
        return Http::json('GET', $this->url("datasets/$datasetId/items", [
            'clean' => 'true', 'limit' => $limit, 'offset' => $offset,
        ]), [], null, 40) ?: [];
    }

    /* ── input builders ─────────────────────────────────────────────────── */
    public static function igPostsInput(array $handles, $perAccount) {
        return [
            'directUrls'    => array_map(fn($h) => 'https://www.instagram.com/' . $h . '/', $handles),
            'resultsType'   => 'posts',
            'resultsLimit'  => (int)$perAccount,
            'addParentData' => false,
        ];
    }
    public static function igTranscriptInput(array $reelUrls) {
        return ['reelUrls' => array_values($reelUrls), 'includeOnScreenText' => true];
    }
    public static function ytInput($channelUrl, $maxVideos) {
        /* Hinglish auto-captions are filed under 'hi'. Omitting the languages
           array makes the actor fail with NoTranscriptFound on those channels. */
        return [
            'channel' => $channelUrl, 'max_videos' => (int)$maxVideos,
            'channel_transcripts' => true, 'include_metadata' => true,
            'languages' => ['hi', 'en', 'ur'], 'output_formats' => ['text'],
        ];
    }

    /** instagram-scraper row -> the shape Engine::scoreAccount expects. */
    public static function normalisePost(array $r) {
        $isVid = (($r['type'] ?? '') === 'Video') || (($r['productType'] ?? '') === 'clips');
        $cmts = [];
        foreach (($r['latestComments'] ?? []) as $c) {
            if (!empty($c['text'])) $cmts[] = $c['text'];
        }
        return [
            'owner' => strtolower((string)($r['ownerUsername'] ?? '')),
            'code' => (string)($r['shortCode'] ?? ''), 'url' => (string)($r['url'] ?? ''),
            'plays' => (int)($r['videoPlayCount'] ?? 0), 'views' => (int)($r['videoViewCount'] ?? 0),
            'likes' => (int)($r['likesCount'] ?? 0), 'comments' => (int)($r['commentsCount'] ?? 0),
            'duration' => (float)($r['videoDuration'] ?? 0), 'timestamp' => (string)($r['timestamp'] ?? ''),
            'is_video' => $isVid, 'caption' => (string)($r['caption'] ?? ''), 'top_comments' => $cmts,
        ];
    }
}
