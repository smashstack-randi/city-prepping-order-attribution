<?php
/** Guide contract and capture isolation. Run: php tests/guide-submission.php */
define('ABSPATH', __DIR__ . '/');
define('CP_GUIDE_CAPTURE_ENABLED', true);
define('CP_GUIDE_CAPTURE_FAILURE_POLICY', ($argv[1] ?? '') === 'retry_error' ? 'retry_error' : 'continue');
define('CP_GUIDE_EVENT_SCOPE', 'staging');
$counter = 0;
$options = [];
function add_filter($hook, $callback, $priority, $args) {}
function wp_generate_uuid4() { global $counter; return sprintf('00000000-0000-4000-8000-%012d', ++$counter); }
function is_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function update_option($key, $value, $autoload = null) { global $options; $options[$key] = $value; }
final class CP_Guide_Outbox {
    public static $events = [];
    public static $fail = false;
    public static function enqueue($event) { if (self::$fail) { return false; } self::$events[] = $event; return true; }
}
final class Guide_Record {
    public $settings = ['id' => '4bec153', 'form_post_id' => 67820, 'form_name' => 'Get started guide'];
    public $fields = ['email' => ['type' => 'email', 'value' => 'test@example.com']];
    public function get($key) { return $key === 'form_settings' ? $this->settings : $this->fields; }
}
final class Guide_Handler {
    public $form = ['id' => '4bec153', 'settings' => ['form_post_id' => 67820, 'form_fields' => [['custom_id' => 'email', 'field_type' => 'email', 'required' => 'true']]]];
    public function get_current_form() { return $this->form; }
    public $error = null;
    public function add_error_message($message) { $this->error = $message; }
    public function send() { throw new Guide_Exit(); }
}
final class Guide_Exit extends RuntimeException {}
require_once dirname(__DIR__) . '/includes/class-cp-guide-submission.php';
function check_guide($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$_GET = ['guide_key' => 'forged', 'cp_from' => 'forged'];
$_COOKIE = ['cp_attribution_first' => 'forged', 'cp_attribution_latest' => 'forged'];
$record = new Guide_Record();
$handler = new Guide_Handler();
check_guide(CP_Guide_Submission::capture($record, $handler) === $record, 'Filter must return the original record.');
$event = CP_Guide_Outbox::$events[0];
check_guide($event['form']['guide_key'] === 'getting-started-guide' && $event['scope'] === 'staging', 'Guide and scope come from server configuration.');
check_guide(array_keys($event) === ['schema_version', 'signup_event_id', 'source_system', 'scope', 'submitted_at', 'event_type', 'form', 'email'], 'Event contains only the approved milestone contract.');
check_guide(strpos(json_encode($event), 'forged') === false, 'Browser attribution cannot influence the guide event.');
CP_Guide_Submission::capture($record, $handler);
check_guide(CP_Guide_Outbox::$events[1]['signup_event_id'] !== $event['signup_event_id'], 'A distinct request receives a new event ID.');
$record->settings['id'] = 'ef38ef5';
$handler->form['id'] = 'ef38ef5';
check_guide(CP_Guide_Submission::build_event($record, $handler) !== null, 'Both approved widgets are supported.');
$handler->form['id'] = 'not-allowed';
check_guide(CP_Guide_Submission::build_event($record, $handler) === null, 'Resolved widget must match the record and allowlist.');
$handler->form['id'] = 'ef38ef5';
$record->settings['form_post_id'] = 1;
check_guide(CP_Guide_Submission::build_event($record, $handler) === null, 'Another page cannot impersonate the approved form.');
$record->settings['form_post_id'] = 67820;
$record->fields['email']['value'] = 'invalid';
check_guide(CP_Guide_Submission::build_event($record, $handler) === null, 'Invalid email cannot create a milestone.');
$record->fields['email']['value'] = 'test@example.com';
$handler->form['settings']['form_fields'][0]['required'] = '';
check_guide(CP_Guide_Submission::build_event($record, $handler) === null, 'Saved email field must remain required.');
$handler->form['settings']['form_fields'][0]['required'] = 'true';
CP_Guide_Outbox::$fail = true;
if (CP_GUIDE_CAPTURE_FAILURE_POLICY === 'retry_error') {
    $exited = false;
    try { CP_Guide_Submission::capture($record, $handler); }
    catch (Guide_Exit $exit) { $exited = true; }
    check_guide($exited && $handler->error !== null, 'Retry-error policy sends an error before native actions can execute.');
} else {
    check_guide(CP_Guide_Submission::capture($record, $handler) === $record && $handler->error === null, 'Continue policy preserves the native form record on persistence failure.');
}
check_guide($options['cp_guide_capture_failure_count'] === 1, 'Capture failure records only an aggregate counter.');
echo "Guide submission contract checks passed.\n";
