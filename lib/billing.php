<?php
require_once __DIR__ . '/db.php';

/* Plans, subscriptions, promo/affiliate codes, script quotas. Payment is not
   wired to a processor yet: a customer requests a plan, admin activates it
   after the money lands. Admins themselves are never metered. */
class Billing {
    const TRIAL_MIN_POSTS = 10;   // videos the creator must post…
    const TRIAL_WINDOW    = 7;    // …in this many days before the first analysis
    const MIN_BATCH       = 3;    // never generate a batch smaller than this

    public static function plans() { return all("SELECT * FROM plans WHERE active=1 ORDER BY sort"); }
    public static function plan($id) { return one("SELECT * FROM plans WHERE id=?", [$id]); }

    public static function current($userId) {
        return one("SELECT s.*, p.name plan_name, p.scripts_month, p.runs_month, p.price_usd
                    FROM subscriptions s JOIN plans p ON p.id=s.plan_id
                    WHERE s.user_id=? AND s.status IN ('trial','active','past_due')
                    ORDER BY s.id DESC LIMIT 1", [$userId]);
    }

    public static function startTrial($userId) {
        if (self::current($userId)) return;
        if (one("SELECT id FROM subscriptions WHERE user_id=? LIMIT 1", [$userId])) return;   // one trial per account, ever
        q("INSERT INTO subscriptions (user_id,plan_id,status,started_at,renews_at,period_start)
           VALUES (?,'trial','trial',UTC_TIMESTAMP(),DATE_ADD(CURDATE(), INTERVAL 30 DAY),CURDATE())", [$userId]);
    }

    /** Admin activates after a payment is confirmed. */
    public static function activate($userId, $planId, $promo = null) {
        $p = self::plan($planId);
        if (!$p || $planId === 'trial') throw new RuntimeException('Unknown plan');
        $price = (float)$p['price_usd']; $code = null;
        if ($promo) {
            $d = self::applyPromo($promo, $price);
            if (isset($d['error'])) throw new RuntimeException($d['error']);
            $price = $d['price']; $code = $d['code'];
        }
        q("UPDATE subscriptions SET status='cancelled', cancelled_at=UTC_TIMESTAMP()
           WHERE user_id=? AND status IN ('trial','active','past_due')", [$userId]);
        q("INSERT INTO subscriptions (user_id,plan_id,status,promo_code,price_paid,started_at,renews_at,period_start)
           VALUES (?,?,'active',?,?,UTC_TIMESTAMP(),DATE_ADD(CURDATE(), INTERVAL 1 MONTH),CURDATE())",
          [$userId, $planId, $code, $price]);
        if ($code) q("UPDATE promo_codes SET uses=uses+1 WHERE code=?", [$code]);
        q("UPDATE plan_requests SET status='done' WHERE user_id=? AND status='pending'", [$userId]);
        q("UPDATE customers SET status='paying' WHERE user_id=?", [$userId]);
        return $price;
    }
    public static function cancel($userId) {
        q("UPDATE subscriptions SET status='cancelled', cancelled_at=UTC_TIMESTAMP()
           WHERE user_id=? AND status IN ('trial','active','past_due')", [$userId]);
        q("UPDATE customers SET status='churned' WHERE user_id=?", [$userId]);
    }
    /** Payment for the new period arrived: reopen the month. */
    public static function renew($userId) {
        q("UPDATE subscriptions SET status='active', scripts_used=0, period_start=CURDATE(),
           renews_at=DATE_ADD(CURDATE(), INTERVAL 1 MONTH) WHERE user_id=? AND status IN ('active','past_due')
           ORDER BY id DESC LIMIT 1", [$userId]);
    }

    /** Validate a code and compute the discounted price. */
    public static function applyPromo($code, $price) {
        $code = strtoupper(trim((string)$code));
        if ($code === '' || strlen($code) > 40) return ['error' => 'That code is not valid.'];
        $p = one("SELECT * FROM promo_codes WHERE code=? AND active=1", [$code]);
        if (!$p) return ['error' => 'That code is not valid.'];
        if ($p['expires_at'] && $p['expires_at'] < gmdate('Y-m-d')) return ['error' => 'That code has expired.'];
        if ($p['max_uses'] !== null && (int)$p['uses'] >= (int)$p['max_uses']) return ['error' => 'That code has been fully used.'];
        $new = $price;
        if ((int)$p['discount_pct'] > 0) $new = $price * (1 - (int)$p['discount_pct'] / 100);
        if ((float)$p['discount_usd'] > 0) $new = $new - (float)$p['discount_usd'];
        return ['code' => $code, 'price' => max(0, round($new, 2)), 'row' => $p];
    }

    /** Roll the monthly counter when a new period starts; the month is then owed. */
    public static function tickPeriod(array $sub) {
        if ($sub['status'] === 'trial') return $sub;
        if ($sub['renews_at'] && $sub['renews_at'] <= gmdate('Y-m-d') && $sub['status'] === 'active') {
            q("UPDATE subscriptions SET status='past_due' WHERE id=?", [$sub['id']]);
            $sub['status'] = 'past_due';
        }
        return $sub;
    }

    /** How many scripts this run may generate. 0 means: do not run. null means unlimited. */
    public static function allowance($userId) {
        $u = one("SELECT role FROM users WHERE id=?", [$userId]);
        if ($u && $u['role'] === 'admin') return ['n' => null, 'why' => '', 'sub' => null];
        $s = self::current($userId);
        if (!$s) return ['n' => 0, 'why' => 'No active plan.', 'sub' => null];
        $s = self::tickPeriod($s);
        if ($s['status'] === 'past_due') return ['n' => 0, 'why' => 'Payment for this month is due before the next batch.', 'sub' => $s];
        if ($s['runs_month'] !== null && $s['status'] !== 'trial') {
            $r = one("SELECT COUNT(*) n FROM runs r JOIN customers c ON c.id=r.customer_id
                      WHERE c.user_id=? AND r.kind='full' AND r.status IN ('running','done','queued') AND r.started_at >= ?",
                     [$userId, $s['period_start'] . ' 00:00:00']);
            if ((int)$r['n'] >= (int)$s['runs_month']) return ['n' => 0, 'why' => 'Monthly run limit reached.', 'sub' => $s];
        }
        if ($s['scripts_month'] === null) return ['n' => null, 'why' => '', 'sub' => $s];
        $left = (int)$s['scripts_month'] - (int)$s['scripts_used'];
        if ($left < self::MIN_BATCH) return ['n' => 0, 'why' => $s['status'] === 'trial'
            ? 'Your free trial batch has been delivered. Choose a plan to keep going.'
            : 'Monthly script allowance used up.', 'sub' => $s];
        return ['n' => $left, 'why' => '', 'sub' => $s];
    }
    public static function consume($userId, $n) {
        q("UPDATE subscriptions SET scripts_used=scripts_used+? WHERE user_id=? AND status IN ('trial','active')
           ORDER BY id DESC LIMIT 1", [(int)$n, $userId]);
    }

    /** The trial gate: how many videos has the creator posted in the window? */
    public static function recentOwnPosts($customerId) {
        $r = one("SELECT COUNT(DISTINCT code) n FROM reels WHERE customer_id=? AND is_own=1
                  AND posted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)", [$customerId, self::TRIAL_WINDOW]);
        return (int)($r['n'] ?? 0);
    }

    public static function createCode($code, $kind, $pct, $usd, $maxUses, $expires, $affiliateUser = null, $commission = 0) {
        $code = preg_replace('/[^A-Z0-9-]/', '', strtoupper((string)$code));
        if (strlen($code) < 3 || strlen($code) > 40) throw new RuntimeException('Code must be 3–40 letters or digits.');
        $pct = max(0, min(100, (int)$pct)); $usd = max(0, (float)$usd);
        if ($pct === 0 && $usd == 0) throw new RuntimeException('Give the code a discount — percent or dollars.');
        if ($expires && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) throw new RuntimeException('Expiry must be YYYY-MM-DD.');
        if (one("SELECT id FROM promo_codes WHERE code=?", [$code])) throw new RuntimeException('That code already exists.');
        q("INSERT INTO promo_codes (code,kind,discount_pct,discount_usd,max_uses,expires_at,affiliate_user,commission_pct)
           VALUES (?,?,?,?,?,?,?,?)",
          [$code, $kind === 'affiliate' ? 'affiliate' : 'promo', $pct, $usd, ($maxUses === '' || $maxUses === null) ? null : max(1, (int)$maxUses),
           $expires ?: null, $affiliateUser ?: null, max(0, min(100, (int)$commission))]);
        return $code;
    }
}
