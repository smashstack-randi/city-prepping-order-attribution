<?php
/**
 * Administrative settings for City Prepping Order Attribution.
 *
 * Secrets are encrypted before storage and are never rendered back to the
 * browser. The current and previous signing keys are reserved for the
 * forthcoming signed-attribution verification rollout.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class CP_Order_Attribution_Settings {
    const OPTION_NAME = 'cp_order_attribution_settings';
    const SETTINGS_GROUP = 'cp_order_attribution_settings_group';
    const PAGE_SLUG = 'cp-order-attribution-settings';
    const SECRET_PREFIX = 'cp-attribution-secret-v1:';

    public static function register() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_menu', [__CLASS__, 'register_settings_page']);
    }

    public static function activate() {
        if (get_option(self::OPTION_NAME, null) === null) {
            add_option(self::OPTION_NAME, self::defaults(), '', 'no');
        }
    }

    public static function defaults() {
        return [
            'signature_verification_mode' => 'disabled',
            'signature_max_age_seconds' => 900,
            'cookie_lifetime_days' => 30,
            'signing_key_current' => '',
            'signing_key_previous' => '',
        ];
    }

    public static function get() {
        $stored = get_option(self::OPTION_NAME, []);
        return wp_parse_args(is_array($stored) ? $stored : [], self::defaults());
    }

    /**
     * Returns signing secrets solely for server-side signature verification.
     * Never pass these values to rendering, REST, JavaScript, logging, or
     * diagnostic code.
     */
    public static function get_signing_keys() {
        $settings = self::get();

        return [
            'current' => self::decrypt_secret($settings['signing_key_current']),
            'previous' => self::decrypt_secret($settings['signing_key_previous']),
        ];
    }

    public static function register_settings() {
        self::ensure_option_exists();

        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
                'default' => self::defaults(),
            ]
        );
    }

    private static function ensure_option_exists() {
        if (get_option(self::OPTION_NAME, null) === null) {
            add_option(self::OPTION_NAME, self::defaults(), '', 'no');
        }
    }

    public static function register_settings_page() {
        add_options_page(
            'Order Attribution Settings',
            'Order Attribution',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function sanitize_settings($input) {
        if (!current_user_can('manage_options')) {
            add_settings_error(self::OPTION_NAME, 'cp_settings_forbidden', 'You are not allowed to change attribution settings.');
            return get_option(self::OPTION_NAME, self::defaults());
        }

        $input = is_array($input) ? $input : [];
        $current = get_option(self::OPTION_NAME, self::defaults());
        $current = wp_parse_args(is_array($current) ? $current : [], self::defaults());

        $mode = isset($input['signature_verification_mode']) ? sanitize_key($input['signature_verification_mode']) : 'disabled';
        if (!in_array($mode, ['disabled', 'audit', 'enforce'], true)) {
            $mode = 'disabled';
        }

        $max_age = isset($input['signature_max_age_seconds']) ? absint($input['signature_max_age_seconds']) : 900;
        $cookie_lifetime = isset($input['cookie_lifetime_days']) ? absint($input['cookie_lifetime_days']) : 30;

        $settings = [
            'signature_verification_mode' => $mode,
            'signature_max_age_seconds' => min(max($max_age, 60), DAY_IN_SECONDS),
            'cookie_lifetime_days' => min(max($cookie_lifetime, 1), 365),
            'signing_key_current' => $current['signing_key_current'],
            'signing_key_previous' => $current['signing_key_previous'],
        ];

        foreach (['current', 'previous'] as $slot) {
            $field = 'signing_key_' . $slot;
            $replacement = isset($input[$field]) ? trim(wp_unslash($input[$field])) : '';
            $clear = !empty($input['clear_' . $field]);

            if ($clear) {
                $settings[$field] = '';
                continue;
            }

            if ($replacement === '') {
                continue;
            }

            if (strlen($replacement) < 32 || strlen($replacement) > 512) {
                add_settings_error(self::OPTION_NAME, 'cp_settings_invalid_' . $slot, 'Signing keys must be between 32 and 512 characters.');
                continue;
            }

            $encrypted = self::encrypt_secret($replacement);
            if ($encrypted === null) {
                add_settings_error(self::OPTION_NAME, 'cp_settings_encryption_unavailable', 'Unable to save the signing key because secure encryption is unavailable on this server.');
                continue;
            }

            $settings[$field] = $encrypted;
        }

        return $settings;
    }

    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You are not allowed to manage attribution settings.');
        }

        $settings = self::get();
        $current_key_configured = is_string($settings['signing_key_current']) && $settings['signing_key_current'] !== '';
        $previous_key_configured = is_string($settings['signing_key_previous']) && $settings['signing_key_previous'] !== '';
        ?>
        <div class="wrap">
            <h1>Order Attribution Settings</h1>
            <p>Signing-key values are encrypted at rest and are never shown after saving. Leave a replacement field blank to keep its existing value.</p>
            <form action="options.php" method="post">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cp-signature-verification-mode">Signature verification</label></th>
                        <td>
                            <select id="cp-signature-verification-mode" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signature_verification_mode]">
                                <option value="disabled" <?php selected($settings['signature_verification_mode'], 'disabled'); ?>>Disabled</option>
                                <option value="audit" <?php selected($settings['signature_verification_mode'], 'audit'); ?>>Audit only</option>
                                <option value="enforce" <?php selected($settings['signature_verification_mode'], 'enforce'); ?>>Enforce</option>
                            </select>
                            <p class="description">Keep this disabled until the Cloudflare Worker sends signed attribution. Use audit only before enforcement.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cp-signature-max-age">Signature maximum age (seconds)</label></th>
                        <td><input id="cp-signature-max-age" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signature_max_age_seconds]" type="number" min="60" max="86400" value="<?php echo esc_attr($settings['signature_max_age_seconds']); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cp-cookie-lifetime">Attribution cookie lifetime (days)</label></th>
                        <td><input id="cp-cookie-lifetime" name="<?php echo esc_attr(self::OPTION_NAME); ?>[cookie_lifetime_days]" type="number" min="1" max="365" value="<?php echo esc_attr($settings['cookie_lifetime_days']); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cp-signing-key-current">Current signing key</label></th>
                        <td>
                            <input id="cp-signing-key-current" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signing_key_current]" type="password" value="" autocomplete="new-password" class="regular-text" />
                            <p class="description">Status: <?php echo $current_key_configured ? 'configured' : 'not configured'; ?>. Enter a replacement only when rotating or initially setting the key.</p>
                            <label><input name="<?php echo esc_attr(self::OPTION_NAME); ?>[clear_signing_key_current]" type="checkbox" value="1" /> Clear current signing key</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cp-signing-key-previous">Previous signing key</label></th>
                        <td>
                            <input id="cp-signing-key-previous" name="<?php echo esc_attr(self::OPTION_NAME); ?>[signing_key_previous]" type="password" value="" autocomplete="new-password" class="regular-text" />
                            <p class="description">Status: <?php echo $previous_key_configured ? 'configured' : 'not configured'; ?>. Use only during a controlled key rotation, then clear it.</p>
                            <label><input name="<?php echo esc_attr(self::OPTION_NAME); ?>[clear_signing_key_previous]" type="checkbox" value="1" /> Clear previous signing key</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save attribution settings'); ?>
            </form>
        </div>
        <?php
    }

    private static function encryption_key() {
        if (!function_exists('sodium_crypto_secretbox') || !function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }

        return hash('sha256', wp_salt('auth') . "\0cityprepping-order-attribution-settings-v1", true);
    }

    private static function encrypt_secret($value) {
        $key = self::encryption_key();
        if ($key === null) {
            return null;
        }

        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($value, $nonce, $key);
            return self::SECRET_PREFIX . base64_encode($nonce . $ciphertext);
        } catch (Exception $exception) {
            return null;
        }
    }

    private static function decrypt_secret($value) {
        if (!is_string($value) || $value === '') {
            return '';
        }

        if (strpos($value, self::SECRET_PREFIX) !== 0) {
            return '';
        }

        $key = self::encryption_key();
        if ($key === null) {
            return '';
        }

        $encoded = substr($value, strlen(self::SECRET_PREFIX));
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        return $plaintext === false ? '' : $plaintext;
    }
}
