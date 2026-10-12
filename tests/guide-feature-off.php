<?php
/** Default-off must not read a form, create tables, or send requests. */
define('ABSPATH', __DIR__ . '/');
define('CP_GUIDE_CAPTURE_ENABLED', true); // Legacy constant must not bypass admin Disabled.
function add_filter($hook, $callback, $priority, $args) {}
function add_action($hook, $callback) {}
final class CP_Order_Attribution_Settings { public static function get() { return []; } }
require_once dirname(__DIR__) . '/includes/class-cp-guide-submission.php';
require_once dirname(__DIR__) . '/includes/class-cp-guide-outbox.php';
$record = new stdClass();
if (CP_Guide_Submission::enabled() || CP_Guide_Submission::capture($record, new stdClass()) !== $record) {
    throw new RuntimeException('Default-off capture changed form behavior.');
}
CP_Guide_Outbox::boot();
echo "Guide feature-off contract checks passed.\n";
