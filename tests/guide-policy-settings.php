<?php
/** Admin policy persistence/validation. Run: php tests/guide-policy-settings.php */
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
$options = [];
$can_manage = true;
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function current_user_can($capability) { global $can_manage; return $can_manage; }
function add_settings_error($name, $code, $message) {}
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value)); }
function absint($value) { return abs((int) $value); }
require_once dirname(__DIR__) . '/includes/class-cp-settings.php';
require_once dirname(__DIR__) . '/includes/class-cp-guide-submission.php';
function policy_check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
policy_check(CP_Order_Attribution_Settings::get()['guide_capture_failure_policy'] === 'continue', 'Existing installations receive the continue default.');
policy_check(CP_Guide_Submission::mode() === 'disabled', 'Existing installations default to Disabled.');
foreach (['test_emails_only', 'all', 'disabled'] as $mode) {
    $options[CP_Order_Attribution_Settings::OPTION_NAME] = CP_Order_Attribution_Settings::sanitize_settings(['guide_capture_mode' => $mode]);
    policy_check(CP_Guide_Submission::mode() === $mode, 'Admin mode is persisted and read by capture.');
    policy_check(CP_Order_Attribution_Settings::sanitize_settings([])['guide_capture_mode'] === $mode, 'Unrelated saves preserve capture mode.');
}
policy_check(CP_Order_Attribution_Settings::sanitize_settings(['guide_capture_mode' => ['invalid']])['guide_capture_mode'] === 'disabled', 'Invalid capture mode fails closed.');
$options[CP_Order_Attribution_Settings::OPTION_NAME] = CP_Order_Attribution_Settings::sanitize_settings(['guide_capture_failure_policy' => 'retry_error']);
policy_check(CP_Guide_Submission::failure_policy() === 'retry_error', 'Capture reads the saved admin policy.');
$saved = CP_Order_Attribution_Settings::sanitize_settings([]);
policy_check($saved['guide_capture_failure_policy'] === 'retry_error', 'Unrelated saves preserve the existing policy.');
$saved = CP_Order_Attribution_Settings::sanitize_settings(['guide_capture_failure_policy' => ['invalid']]);
policy_check($saved['guide_capture_failure_policy'] === 'continue', 'Invalid values use the safe default.');
$can_manage = false;
$saved = CP_Order_Attribution_Settings::sanitize_settings(['guide_capture_failure_policy' => 'continue']);
policy_check($saved['guide_capture_failure_policy'] === 'retry_error', 'Unauthorized saves cannot change policy.');
policy_check(CP_Order_Attribution_Settings::sanitize_settings(['guide_capture_mode' => 'all'])['guide_capture_mode'] === 'disabled', 'Unauthorized saves cannot enable capture.');
define('CP_GUIDE_CAPTURE_FAILURE_POLICY', 'continue');
policy_check(CP_Guide_Submission::failure_policy() === 'retry_error', 'Legacy server constants cannot override the saved admin policy.');
echo "Guide policy settings checks passed.\n";
