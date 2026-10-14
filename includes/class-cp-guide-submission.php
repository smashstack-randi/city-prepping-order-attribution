<?php
/** Server-approved guide milestones; purchase attribution is deliberately absent. */
if (!defined('ABSPATH')) { exit; }

final class CP_Guide_Submission {
    public static function register() {
        add_filter('elementor_pro/forms/record/actions_before', [__CLASS__, 'capture'], 10, 2);
    }

    public static function enabled() {
        return self::mode() !== 'disabled';
    }

    public static function mode() {
        $mode = CP_Order_Attribution_Settings::get()['guide_capture_mode'] ?? 'disabled';
        return in_array($mode, ['disabled', 'test_emails_only', 'all'], true) ? $mode : 'disabled';
    }

    public static function is_test_email($email) {
        return is_string($email) && preg_match('/^[^@\s]+@cityprepping\.com$/iD', trim($email)) === 1;
    }

    public static function form_map() {
        // Overrides are server configuration, never posted form fields.
        return defined('CP_GUIDE_FORM_MAP') ? CP_GUIDE_FORM_MAP : [
            ['post_id' => 67835, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Bugout Guide', 'guide_key' => 'bug-out-bag-guide', 'kit_form_id' => '7489636'],
            ['post_id' => 67835, 'widget_id' => '684413d', 'email_field' => 'email', 'form_name' => 'Bugout Guide', 'guide_key' => 'bug-out-bag-guide', 'kit_form_id' => '7489636'],
            ['post_id' => 67838, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Cyber Warfare Survival Guide', 'guide_key' => 'cyber-attack-guide', 'kit_form_id' => '7486355'],
            ['post_id' => 67838, 'widget_id' => '5ae28c0', 'email_field' => 'email', 'form_name' => 'Cyber Warfare Survival Guide', 'guide_key' => 'cyber-attack-guide', 'kit_form_id' => '7486355'],
            ['post_id' => 67840, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Disaster Guide', 'guide_key' => 'disaster-guide', 'kit_form_id' => '7489640'],
            ['post_id' => 67840, 'widget_id' => '6df1468', 'email_field' => 'email', 'form_name' => 'Disaster Guide', 'guide_key' => 'disaster-guide', 'kit_form_id' => '7489640'],
            ['post_id' => 67917, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Extreme Weather Guide', 'guide_key' => 'extreme-weather-guide', 'kit_form_id' => '7486371'],
            ['post_id' => 67917, 'widget_id' => '3cd04e7', 'email_field' => 'email', 'form_name' => 'Extreme Weather Guide', 'guide_key' => 'extreme-weather-guide', 'kit_form_id' => '7486371'],
            ['post_id' => 67820, 'widget_id' => '4bec153', 'email_field' => 'email', 'form_name' => 'Get started guide', 'guide_key' => 'getting-started-guide', 'kit_form_id' => '7486364'],
            ['post_id' => 67820, 'widget_id' => 'ef38ef5', 'email_field' => 'email', 'form_name' => 'Get started guide', 'guide_key' => 'getting-started-guide', 'kit_form_id' => '7486364'],
            ['post_id' => 67893, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Physical Fitness Guide', 'guide_key' => 'physical-fitness-guide', 'kit_form_id' => '7634661'],
            ['post_id' => 67893, 'widget_id' => '5dc1b1a', 'email_field' => 'email', 'form_name' => 'Physical Fitness Guide', 'guide_key' => 'physical-fitness-guide', 'kit_form_id' => '7634661'],
            ['post_id' => 67898, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Recession Proof Guide', 'guide_key' => 'recession-proof-guide', 'kit_form_id' => '7489642'],
            ['post_id' => 67898, 'widget_id' => 'af0350e', 'email_field' => 'email', 'form_name' => 'Recession Proof Guide', 'guide_key' => 'recession-proof-guide', 'kit_form_id' => '7489642'],
            ['post_id' => 67911, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Risk Matrix Guide', 'guide_key' => 'risk-matrix-guide', 'kit_form_id' => '7486374'],
            ['post_id' => 67911, 'widget_id' => 'b8665ea', 'email_field' => 'email', 'form_name' => 'Risk Matrix Guide', 'guide_key' => 'risk-matrix-guide', 'kit_form_id' => '7486374'],
            ['post_id' => 67915, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Solar Generator Spreadsheet', 'guide_key' => 'solar-generator-guide', 'kit_form_id' => '7489645'],
            ['post_id' => 67915, 'widget_id' => 'bceba76', 'email_field' => 'email', 'form_name' => 'Solar Generator Spreadsheet', 'guide_key' => 'solar-generator-guide', 'kit_form_id' => '7489645'],
            ['post_id' => 75556, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Ultimate Food Storage Guide', 'guide_key' => 'ultimate-food-storage-guide', 'kit_form_id' => '9842678'],
            ['post_id' => 75556, 'widget_id' => 'ba488b6', 'email_field' => 'email', 'form_name' => 'Ultimate Food Storage Guide', 'guide_key' => 'ultimate-food-storage-guide', 'kit_form_id' => '9842678'],
            ['post_id' => 67920, 'widget_id' => '9d63a0d', 'email_field' => 'email', 'form_name' => 'Water Guide', 'guide_key' => 'water-guide', 'kit_form_id' => '8741827'],
            ['post_id' => 67920, 'widget_id' => 'b9d3237', 'email_field' => 'email', 'form_name' => 'Water Guide', 'guide_key' => 'water-guide', 'kit_form_id' => '8741827'],
        ];
    }

    public static function build_event($record, $handler) {
        $form = $handler->get_current_form();
        $settings = $record->get('form_settings');
        $fields = $record->get('fields');
        $map = self::form_map();
        if (!is_array($form) || !is_array($settings) || !is_array($fields) || !is_array($map)) { return null; }
        foreach ($map as $entry) {
            if (!is_array($entry) || !isset($entry['post_id'], $entry['widget_id'], $entry['email_field'], $entry['form_name'], $entry['guide_key'], $entry['kit_form_id'])) { continue; }
            // Check both the resolved saved widget and the processed record.
            if (($form['id'] ?? '') !== $entry['widget_id']
                || ($settings['id'] ?? '') !== $entry['widget_id']
                || (string) ($settings['form_post_id'] ?? '') !== (string) $entry['post_id']
                || ($settings['form_name'] ?? '') !== $entry['form_name']
                || (string) ($form['settings']['form_post_id'] ?? '') !== (string) $entry['post_id']) { continue; }
            $field_id = $entry['email_field'];
            $definition = null;
            foreach (($form['settings']['form_fields'] ?? []) as $saved_field) {
                if (($saved_field['custom_id'] ?? '') === $field_id) { $definition = $saved_field; break; }
            }
            if (($definition['field_type'] ?? '') !== 'email' || empty($definition['required'])
                || ($fields[$field_id]['type'] ?? '') !== 'email') { return null; }
            $email = $fields[$field_id]['value'] ?? null;
            if (!is_string($email) || strlen($email) > 320 || !is_email(trim($email))) { return null; }
            $is_test_capture = self::mode() === 'test_emails_only';
            if ($is_test_capture && !self::is_test_email($email)) { return null; }
            if (!is_string($entry['guide_key']) || !preg_match('/^[a-z0-9-]{1,100}$/D', $entry['guide_key'])) { return null; }
            $scope = wp_get_environment_type();
            if (!in_array($scope, ['local', 'development', 'staging', 'production'], true)) { return null; }
            return [
                'schema_version' => 1,
                'signup_event_id' => wp_generate_uuid4(),
                'source_system' => 'wordpress',
                'scope' => $scope,
                'submitted_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'event_type' => 'guide_submission',
                'is_test_capture' => $is_test_capture,
                'form' => [
                    'post_id' => (int) $entry['post_id'], 'widget_id' => $entry['widget_id'],
                    'form_key' => $entry['post_id'] . ':' . $entry['widget_id'],
                    'guide_key' => $entry['guide_key'], 'kit_form_id' => (string) $entry['kit_form_id'],
                    'map_version' => defined('CP_GUIDE_FORM_MAP_VERSION') ? (string) CP_GUIDE_FORM_MAP_VERSION : '2',
                ],
                'email' => trim($email),
            ];
        }
        return null;
    }

    public static function failure_policy() {
        $policy = CP_Order_Attribution_Settings::get()['guide_capture_failure_policy'] ?? 'continue';
        // Malformed settings cannot silently stop capture or enrollment.
        return in_array($policy, ['continue', 'retry_error'], true) ? $policy : 'continue';
    }

    public static function capture($record, $handler) {
        if (!self::enabled()) { return $record; }
        $event = self::build_event($record, $handler);
        if ($event === null) { return $record; }
        try { $saved = CP_Guide_Outbox::enqueue($event); }
        catch (\Throwable $error) { $saved = false; }
        if (!$saved) {
            self::count_failure();
            if (self::failure_policy() === 'retry_error') {
                $handler->add_error_message('Your submission could not be saved. Please try again.');
                // Elementor does not recheck errors after actions_before. send() exits
                // before native actions, preventing Kit enrollment on this failure.
                $handler->send();
            }
        }
        return $record;
    }

    private static function count_failure() {
        $count = (int) get_option('cp_guide_capture_failure_count', 0);
        update_option('cp_guide_capture_failure_count', $count + 1, false);
    }
}
