<?php
/* On-demand Script Writer + Story ideas. Reads only the customer's OWN scraped
   corpus (their reels + their competitors' reels), never the live web. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/anthropic.php';
require_once __DIR__ . '/billing.php';

class Writer {
    const MAX_REFS = 6;
    const PER_DAY  = 20;   // on-demand scripts per customer per day

    /** Find reference reels in the customer's corpus that match the topic. */
    public static function search($customerId, $topic) {
        $kw = array_slice(Engine::keywords($topic), 0, 8);
        $refs = [];
        if ($kw) {
            $where = []; $args = [$customerId];
            foreach ($kw as $w) { $where[] = '(hook LIKE ? OR transcript LIKE ? OR handle LIKE ?)'; $like = '%' . $w . '%'; array_push($args, $like, $like, $like); }
            $rows = all("SELECT handle, code, url, hook, transcript, velocity, is_own FROM reels
                         WHERE customer_id=? AND (" . implode(' OR ', $where) . ")
                         ORDER BY velocity DESC LIMIT 60", $args);
            $seen = [];
            foreach ($rows as $r) {                                 // one row per reel, highest velocity kept
                if (isset($seen[$r['code']])) continue;
                $seen[$r['code']] = 1; $refs[] = $r;
                if (count($refs) >= self::MAX_REFS) break;
            }
        }
        /* Top reels in the niche, any topic — style reference when nothing matched. */
        $style = []; $seenS = [];
        foreach (all("SELECT handle, code, hook, velocity FROM reels WHERE customer_id=? AND hook<>'' ORDER BY velocity DESC LIMIT 40", [$customerId]) as $r) {
            if (isset($seenS[$r['code']])) continue; $seenS[$r['code']] = 1; $style[] = $r;
            if (count($style) >= 5) break;
        }
        return ['refs' => $refs, 'style' => $style, 'keywords' => $kw];
    }

    /** Generate one script. Returns the stored row id and the script array. */
    public static function generate(array $customer, $topic, array $refs, $notes, $style = []) {
        $cfg = outlier_config();
        $ctx = [
            'handle' => $customer['ig_handle'], 'niche' => $customer['niche'],
            'language' => $customer['language'] ?: 'English',
            'duration' => $customer['duration_pref'] ?: '30-40 sec',
            'voice' => (string)$customer['voice_profile'],
            'topic' => $topic, 'refs' => $refs, 'notes' => $notes, 'style_refs' => $style,
        ];
        $claude = new Claude($cfg['anthropic_key'] ?? '');
        $r = $claude->json(Claude::writerSystem(), Claude::writerPrompt($ctx), 6000);
        $s = $r['data'];
        if (empty($s['body'])) throw new RuntimeException('The model did not return a script. Try rephrasing the topic.');

        $refBlob = json_encode(array_map(fn($x) => ['handle' => $x['handle'], 'hook' => $x['hook'] ?? '', 'url' => $x['url'] ?? ''], $refs), JSON_UNESCAPED_UNICODE);
        q("INSERT INTO writer_requests (customer_id,user_id,topic,had_reference,notes,refs,hook_pattern,hook_line,body,duration_label,caption,cta_keyword,source_insight)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            $customer['id'], $customer['user_id'], mb_substr($topic, 0, 300), $refs ? 1 : 0,
            mb_substr((string)$notes, 0, 8000), $refBlob,
            mb_substr((string)($s['hook_pattern'] ?? ''), 0, 60), (string)($s['hook_line'] ?? ''),
            (string)$s['body'], mb_substr((string)($s['duration_label'] ?? ''), 0, 30),
            (string)($s['caption'] ?? ''), mb_substr(strtoupper((string)($s['cta_keyword'] ?? '')), 0, 60),
            (string)($s['source_insight'] ?? '')]);
        return ['id' => lastId(), 'script' => $s];
    }

    /** Story ideas for today, cached one row per customer per day. */
    public static function storyIdeas(array $customer, $force = false) {
        $today = gmdate('Y-m-d');
        $row = one("SELECT * FROM story_ideas WHERE customer_id=? AND day=?", [$customer['id'], $today]);
        /* A plain dashboard load never calls the model — it only reads what is cached.
           Generation happens solely when the customer presses the button ($force). */
        if (!$force) return $row ? (json_decode((string)$row['ideas'], true) ?: []) : [];

        $run = one("SELECT * FROM runs WHERE customer_id=? AND kind='full' AND status='done' ORDER BY id DESC LIMIT 1", [$customer['id']]);
        if (!$run) return [];
        $f = json_decode((string)$run['findings'], true) ?: [];
        $repeated = array_map(fn($x) => $x['example'] ?? '', array_slice($f['mined']['repeated'] ?? [], 0, 6));
        $hooks = array_column(all("SELECT hook FROM reels WHERE run_id=? AND hook<>'' ORDER BY velocity DESC LIMIT 6", [$run['id']]), 'hook');
        if (!$repeated && !$hooks) return [];

        $cfg = outlier_config();
        $claude = new Claude($cfg['anthropic_key'] ?? '');
        try {
            $r = $claude->json(Claude::storySystem(), Claude::storyPrompt([
                'handle' => $customer['ig_handle'], 'niche' => $customer['niche'],
                'language' => $customer['language'] ?: 'English',
                'repeated' => array_filter($repeated), 'hooks' => $hooks]), 2000);
            $ideas = $r['data']['ideas'] ?? [];
        } catch (Throwable $e) { $ideas = []; }
        if (!$ideas) return [];
        q("INSERT INTO story_ideas (customer_id,day,ideas) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE ideas=VALUES(ideas)", [$customer['id'], $today, json_encode($ideas, JSON_UNESCAPED_UNICODE)]);
        return $ideas;
    }

    /** Best-effort: audience questions on the creator's OWN posts, to reply to or turn into videos. */
    public static function leads($customerId, $limit = 15) {
        return all("SELECT * FROM comment_leads WHERE customer_id=? AND is_question=1 AND answered=0 ORDER BY id DESC LIMIT " . (int)$limit, [$customerId]);
    }
}
