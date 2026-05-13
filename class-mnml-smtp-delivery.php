<?php

abstract class MnmlSMTP_Transport {
    abstract public function send($email);

    protected function normalize_email($email) {
        return MnmlSMTP::normalize_email_payload($email);
    }
}

class MnmlSMTP_Local_Transport extends MnmlSMTP_Transport {
    public function send($email) {
        return MnmlSMTP::deliver_with_wp_mail($email, false);
    }
}

class MnmlSMTP_SMTP_Transport extends MnmlSMTP_Transport {
    public function send($email) {
        return MnmlSMTP::deliver_with_wp_mail($email, true);
    }
}

class MnmlSMTP_Microsoft365_Transport extends MnmlSMTP_Transport {
    public function send($email) {
        $provider = MnmlSMTP::get_oauth_provider('microsoft365');
        if (!($provider instanceof MnmlSMTP_Microsoft365_OAuth)) {
            throw new Exception('Microsoft 365 provider is unavailable.');
        }
        if (!$provider->is_configured()) {
            throw new Exception('Microsoft 365 is not fully configured.');
        }

        $message = $this->normalize_email($email);
        if (!empty($message['attachments'])) {
            throw new Exception('Microsoft 365 delivery does not support attachments yet.');
        }

        $sender = $provider->get_sender();
        if (!$sender || !is_email($sender)) {
            throw new Exception('Microsoft 365 sender email is not configured.');
        }

        $payload = [
            'message' => [
                'subject' => $message['subject'],
                'body' => [
                    'contentType' => $message['content_type'] === 'text/html' ? 'HTML' : 'Text',
                    'content' => $message['body'],
                ],
                'toRecipients' => MnmlSMTP::format_graph_recipients($message['to']),
            ],
            'saveToSentItems' => false,
        ];

        if (!empty($message['cc'])) {
            $payload['message']['ccRecipients'] = MnmlSMTP::format_graph_recipients($message['cc']);
        }
        if (!empty($message['bcc'])) {
            $payload['message']['bccRecipients'] = MnmlSMTP::format_graph_recipients($message['bcc']);
        }
        if (!empty($message['reply_to'])) {
            $payload['message']['replyTo'] = MnmlSMTP::format_graph_recipients($message['reply_to']);
        }
        if (!empty($message['custom_headers'])) {
            $payload['message']['internetMessageHeaders'] = $message['custom_headers'];
        }

        $response = wp_remote_post(
            'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender) . '/sendMail',
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $provider->get_access_token(),
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            throw new Exception('Microsoft Graph request failed: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            throw new Exception('Microsoft Graph sendMail failed: ' . MnmlSMTP::extract_remote_error($response));
        }

        return true;
    }
}

class MnmlSMTP_Google_Transport extends MnmlSMTP_Transport {
    public function send($email) {
        throw new Exception('Google delivery is not implemented yet.');
    }
}

abstract class MnmlSMTP_OAuth_Provider {
    protected $slug;
    protected $label;

    public function __construct($slug, $label) {
        $this->slug = $slug;
        $this->label = $label;
    }

    public function get_slug() {
        return $this->slug;
    }

    public function get_label() {
        return $this->label;
    }

    protected function get_option_key($suffix) {
        return 'mnml_smtp_' . $this->slug . '_' . $suffix;
    }

    protected function get_constant_value($suffix) {
        $constant = 'MNML_SMTP_' . strtoupper(str_replace('-', '_', $this->slug . '_' . $suffix));
        return defined($constant) ? constant($constant) : null;
    }

    protected function get_config_value($suffix, $default = '') {
        $constant_value = $this->get_constant_value($suffix);
        return $constant_value !== null ? $constant_value : get_option($this->get_option_key($suffix), $default);
    }

    public function get_client_id() {
        return trim((string) $this->get_config_value('client_id', ''));
    }

    public function get_client_secret() {
        return trim((string) $this->get_config_value('client_secret', ''));
    }

    public function get_sender() {
        return sanitize_email((string) $this->get_config_value('sender', ''));
    }

    public function is_configured() {
        return $this->get_client_id() !== '' && $this->get_client_secret() !== '';
    }

    public function is_connected() {
        return (bool) (get_option($this->get_option_key('refresh_token')) || get_option($this->get_option_key('access_token')));
    }

    public function get_redirect_uri() {
        return admin_url('admin-post.php?action=mnml_smtp_oauth_callback&provider=' . rawurlencode($this->slug));
    }

    public function get_authorization_url($user_id) {
        if (!$this->is_configured()) {
            throw new Exception($this->get_label() . ' is not fully configured.');
        }

        $state = wp_generate_password(32, false, false);
        set_transient($this->get_state_key($user_id), $state, 10 * MINUTE_IN_SECONDS);

        $args = array_merge(
            [
                'client_id' => $this->get_client_id(),
                'response_type' => 'code',
                'redirect_uri' => $this->get_redirect_uri(),
                'scope' => implode(' ', $this->get_scopes()),
                'state' => $state,
            ],
            $this->get_authorization_query_args()
        );

        return add_query_arg($args, $this->get_authorize_url());
    }

    public function handle_callback($request, $user_id) {
        $state = isset($request['state']) ? sanitize_text_field(wp_unslash($request['state'])) : '';
        $expected_state = get_transient($this->get_state_key($user_id));
        delete_transient($this->get_state_key($user_id));

        if (!$state || !$expected_state || !hash_equals($expected_state, $state)) {
            throw new Exception('Invalid OAuth state.');
        }

        if (!empty($request['error'])) {
            $message = sanitize_text_field(wp_unslash($request['error']));
            if (!empty($request['error_description'])) {
                $message .= ': ' . sanitize_text_field(wp_unslash($request['error_description']));
            }
            throw new Exception($message);
        }

        $code = isset($request['code']) ? sanitize_text_field(wp_unslash($request['code'])) : '';
        if (!$code) {
            throw new Exception('OAuth callback did not include an authorization code.');
        }

        $token_response = $this->request_token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->get_client_id(),
            'client_secret' => $this->get_client_secret(),
            'redirect_uri' => $this->get_redirect_uri(),
        ]);

        $this->store_token_response($token_response);

        return $token_response;
    }

    public function get_access_token() {
        $access_token = get_option($this->get_option_key('access_token'), '');
        $expires_at = (int) get_option($this->get_option_key('expires_at'), 0);

        if ($access_token && $expires_at > time()) {
            return $access_token;
        }

        return $this->refresh_access_token();
    }

    public function refresh_access_token() {
        $refresh_token = get_option($this->get_option_key('refresh_token'), '');
        if (!$refresh_token) {
            throw new Exception($this->get_label() . ' is not connected.');
        }

        $token_response = $this->request_token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $this->get_client_id(),
            'client_secret' => $this->get_client_secret(),
            'redirect_uri' => $this->get_redirect_uri(),
        ]);

        $this->store_token_response($token_response);

        return get_option($this->get_option_key('access_token'), '');
    }

    public function disconnect() {
        delete_option($this->get_option_key('access_token'));
        delete_option($this->get_option_key('refresh_token'));
        delete_option($this->get_option_key('expires_at'));
        delete_option($this->get_option_key('connected_email'));
        delete_option($this->get_option_key('scope'));
    }

    public function get_connected_email() {
        return sanitize_email((string) get_option($this->get_option_key('connected_email'), ''));
    }

    public function get_status_description() {
        if (!$this->is_configured()) {
            return 'Enter the provider credentials to enable OAuth.';
        }
        if ($this->is_connected()) {
            $email = $this->get_connected_email();
            return $email ? 'Connected as ' . $email . '.' : 'Connected.';
        }

        return 'Not connected.';
    }

    protected function request_token($body) {
        $response = wp_remote_post(
            $this->get_token_url(),
            [
                'timeout' => 20,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $body,
            ]
        );

        if (is_wp_error($response)) {
            throw new Exception($this->get_label() . ' token request failed: ' . $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if ($response_code < 200 || $response_code >= 300) {
            $error = is_array($payload) ? $payload : [];
            $message = !empty($error['error_description']) ? $error['error_description'] : (!empty($error['error']) ? $error['error'] : 'Unknown OAuth error.');
            throw new Exception($this->get_label() . ' token request failed: ' . $message);
        }

        if (empty($payload['access_token'])) {
            throw new Exception($this->get_label() . ' token response did not include an access token.');
        }

        return $payload;
    }

    protected function store_token_response($token_response) {
        update_option($this->get_option_key('access_token'), sanitize_text_field($token_response['access_token']));
        if (!empty($token_response['refresh_token'])) {
            update_option($this->get_option_key('refresh_token'), sanitize_text_field($token_response['refresh_token']));
        }
        update_option($this->get_option_key('expires_at'), time() + max(60, (int) ($token_response['expires_in'] ?? 3600)) - 60);
        if (!empty($token_response['scope'])) {
            update_option($this->get_option_key('scope'), sanitize_text_field($token_response['scope']));
        }

        $connected_email = $this->resolve_connected_email($token_response);
        if ($connected_email) {
            update_option($this->get_option_key('connected_email'), $connected_email);
        }
    }

    protected function resolve_connected_email($token_response) {
        return '';
    }

    protected function get_state_key($user_id) {
        return 'mnml_smtp_oauth_state_' . $this->slug . '_' . absint($user_id);
    }

    protected function get_authorization_query_args() {
        return [];
    }

    abstract protected function get_authorize_url();
    abstract protected function get_token_url();
    abstract protected function get_scopes();
}

class MnmlSMTP_Microsoft365_OAuth extends MnmlSMTP_OAuth_Provider {
    public function __construct() {
        parent::__construct('microsoft365', 'Microsoft 365');
    }

    public function get_tenant_id() {
        return trim((string) $this->get_config_value('tenant_id', ''));
    }

    public function is_configured() {
        return parent::is_configured() && $this->get_tenant_id() !== '' && $this->get_sender() !== '';
    }

    protected function get_authorize_url() {
        return 'https://login.microsoftonline.com/' . rawurlencode($this->get_tenant_id()) . '/oauth2/v2.0/authorize';
    }

    protected function get_token_url() {
        return 'https://login.microsoftonline.com/' . rawurlencode($this->get_tenant_id()) . '/oauth2/v2.0/token';
    }

    protected function get_scopes() {
        return [
            'offline_access',
            'https://graph.microsoft.com/Mail.Send',
        ];
    }

    protected function resolve_connected_email($token_response) {
        return $this->get_sender();
    }
}

class MnmlSMTP_Google_OAuth extends MnmlSMTP_OAuth_Provider {
    public function __construct() {
        parent::__construct('google', 'Google');
    }

    public function is_configured() {
        return parent::is_configured() && $this->get_sender() !== '';
    }

    protected function get_authorize_url() {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    protected function get_token_url() {
        return 'https://oauth2.googleapis.com/token';
    }

    protected function get_scopes() {
        return [
            'https://www.googleapis.com/auth/gmail.send',
        ];
    }

    protected function get_authorization_query_args() {
        return [
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];
    }

    protected function resolve_connected_email($token_response) {
        return $this->get_sender();
    }
}
