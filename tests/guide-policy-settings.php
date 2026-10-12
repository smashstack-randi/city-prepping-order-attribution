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
$options[CP_Order_Attribution_Settings::OPTION_NAME] = CP_Order_Attribution_Settings::sanitize_settings(['guide_capture_failure_policy' => 'retry_error']);
policy_check(CP_Guide_Submission::failure_policy() === 'retry_error', 'Capture reads the saved admin policy.');
$saved = CP_Order_Attribution_Settings::sanitize_settings([]);
policy_check($saved['guide_capture_failure_policy'] === 'retry_error', 'Unrelated saves preserve the existing policy.');
$saved = CP_Order_Attribution_Settings::sanitize_settings(['guide_capture_failure_policy' => ['invalid']]);
policy_check($saved['guide_capture_failure_policy'] === 'continue', 'Invalid values use the safe default.');
$can_manage = false;
$saved = CP_Order_Attribution_Settings::sanitize_settings(['guide_capture_failure_policy' => 'continue']);
policy_check($saved['guide_capture_failure_policy'] === 'retry_error', 'Unauthorized saves cannot change policy.');
define('CP_GUIDE_CAPTURE_FAILURE_POLICY', 'continue');
policy_check(CP_Guide_Submission::failure_policy() === 'continue', 'Server override takes precedence over admin setting.');
echo "Guide policy settings checks passed.\n";
