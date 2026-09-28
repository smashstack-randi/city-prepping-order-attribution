<?php
/**
 * Repeatable checks for server-owned first/latest attribution snapshots.
 * Run: php tests/attribution-state.php
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('COOKIEPATH', '/');
define('COOKIE_DOMAIN', '');

function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_unslash($value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function cp_get_allowed_sources() { return ['kit', 'youtube', 'website']; }

final class CP_Order_Attribution_Settings {
    public static function get() {
        return ['first_latest_touch_enabled' => true, 'cookie_lifetime_days' => 30];
    }
}

require_once dirname(__DIR__) . '/includes/class-cp-attribution-state.php';

function expect_state($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$_COOKIE = [];
CP_Attribution_State::capture([
    'cp_from' => 'kit',
    'cp_slid' => 'first-link',
    'cp_email_id' => 'broadcast-1',
]);

$first = CP_Attribution_State::read_first();
$latest = CP_Attribution_State::read_latest();
expect_state($first['cp_from'] === 'kit', 'First touch should be stored.');
expect_state($latest['cp_slid'] === 'first-link', 'Latest touch should start at the first touch.');
expect_state($first['schema_version'] === 1, 'Snapshot schema version should be present.');
expect_state(isset($first['touch_timestamp']), 'Snapshot timestamp should be present.');

CP_Attribution_State::capture([
    'cp_from' => 'youtube',
    'cp_slid' => 'latest-link',
    'cp_youtube_id' => 'video-1',
]);

$first = CP_Attribution_State::read_first();
$latest = CP_Attribution_State::read_latest();
expect_state($first['cp_from'] === 'kit', 'Later touches must not replace first touch.');
expect_state($latest['cp_from'] === 'youtube', 'Later eligible touches should replace latest touch.');
expect_state($latest['cp_slid'] === 'latest-link', 'Latest touch should carry new dimensions.');
expect_state(strpos($_COOKIE[CP_Attribution_State::FIRST_COOKIE], 'broadcast-1') === false, 'Snapshot cookies must be encoded.');

echo "Attribution state contract checks passed.\n";
