<?php
/*
Plugin Name: Mnml SMTP
Description: Lightweight SMTP email sending with async queuing and retries
Version: 1.15
Author: Andrew J Klimek
Author URI: https://mnmlweb.com
*/

class MnmlSMTP {
    public static function init() {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
        add_action('mnml_smtp_cleanup', [__CLASS__, 'cleanup_queue']);
        add_action('mnml_smtp_process_queue', [__CLASS__, 'process_queue']);
        add_action('wp', [__CLASS__, 'schedule_cleanup']);
        add_filter('pre_wp_mail', [__CLASS__, 'queue_email'], 5, 2);
        add_action('phpmailer_init', [__CLASS__, 'configure_smtp']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('wp_ajax_mnml_smtp_test_email', [__CLASS__, 'test_email']);
        add_action('wp_ajax_mnml_smtp_resend', [__CLASS__, 'ajax_resend']);
        add_action('wp_ajax_mnml_smtp_view_email', [__CLASS__, 'ajax_view_email']);
        add_action('admin_post_mnml_smtp_resume', [__CLASS__, 'resume_queue']);
        add_action('admin_notices', [__CLASS__, 'paused_notice']);
        add_filter('wp_mail_from', [__CLASS__, 'set_from_email'], 9);
        add_filter('wp_mail_from_name', [__CLASS__, 'set_from_name'], 9);
    }

    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$wpdb->prefix}mnml_smtp_queue (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            to_email VARCHAR(255) NOT NULL,
            subject TEXT NOT NULL,
            message LONGTEXT NOT NULL,
            headers LONGTEXT,
            status ENUM('pending','failed','sent') DEFAULT 'pending',
            attempts TINYINT UNSIGNED DEFAULT 0,
            next_attempt INT(10) NOT NULL DEFAULT 0,
            error TEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            INDEX next_attempt (next_attempt),
            INDEX status (status)
        ) ENGINE=InnoDB {$charset_collate};");

        self::schedule_cleanup();
    }

    public static function deactivate() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mnml_smtp_logs");
        delete_option('mnml_smtp_max_attempts');
    }

    public static function schedule_cleanup() {
        if (!wp_next_scheduled('mnml_smtp_cleanup')) {
            wp_schedule_event(time(), 'daily', 'mnml_smtp_cleanup');
        }
    }

    public static function set_from_email($email) {
        $from_email = get_option('mnml_smtp_from_email', '');
        return $from_email ? $from_email : $email;
    }

    public static function set_from_name($name) {
        $from_name = get_option('mnml_smtp_from_name', '');
        return $from_name ? $from_name : $name;
    }

    public static function process_queue() {
        if (get_transient('mnml_smtp_paused')) {
            error_log('Mnml SMTP: Queue processing skipped due to paused queue');
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'mnml_smtp_queue';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending' AND next_attempt <= " . time());

        self::send_emails(['queue' => true]);

        if ($count > 0) {
            wp_schedule_single_event(time() + 300, 'mnml_smtp_process_queue');
            error_log('Mnml SMTP: Rescheduled process_queue due to ' . $count . ' pending emails');
        }
    }

    public static function cleanup_queue() {
        global $wpdb;
        $table = $wpdb->prefix . 'mnml_smtp_queue';
        $days = get_option('mnml_smtp_queue_expiry', 7);
        if ($days > 0) {
            $wpdb->query("DELETE FROM $table WHERE status IN ('sent', 'failed') AND created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
            delete_transient('mnml_smtp_failed_count');
        }
    }

    public static function queue_email($skip, $atts) {
        if (defined('DOING_MNMLSMTP')) return $skip;

        global $wpdb;
        $table = $wpdb->prefix . 'mnml_smtp_queue';
        $wpdb->insert($table, [
            'to_email' => is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to'],
            'subject' => $atts['subject'],
            'message' => $atts['message'],
            'headers' => serialize($atts['headers']),
            'status' => 'pending',
            'next_attempt' => time(),
            'created_at' => current_time('mysql'),
        ]);
        $email_id = $wpdb->insert_id;
        if (!$email_id) {
            error_log('Mnml SMTP: Failed to queue email: ' . $wpdb->last_error);
            return $skip;
        }
        error_log('Mnml SMTP: Queued email ID ' . $email_id . (defined('DOING_CRON') ? ' (cron)' : ''));
        if (defined('DOING_CRON')) {
            self::send_emails(['id' => $email_id]);
        } else {
            self::send_async(['id' => $email_id]);
        }
        return true;
    }

    public static function configure_smtp($phpmailer) {
        $mailer_type = get_option('mnml_smtp_mailer_type', 'smtp');
        if (!in_array($mailer_type, ['smtp', 'ses', 'google', 'brevo'])) {
            return;
        }
        $phpmailer->isSMTP();
        // $phpmailer->SMTPDebug = 3;
        // $phpmailer->Debugoutput = 'error_log';
        $host = get_option('mnml_smtp_smtp_host', $mailer_type === 'google' ? 'smtp.gmail.com' : ($mailer_type === 'ses' ? 'email-smtp.us-east-1.amazonaws.com' : ($mailer_type === 'brevo' ? 'smtp-relay.brevo.com' : 'smtp.gmail.com')));
        $phpmailer->Host = $host;
        // $phpmailer->Host = gethostbyname($host);
        // $phpmailer->SMTPOptions = ['ssl' => ['verify_peer_name' => false]];
        $phpmailer->Port = get_option('mnml_smtp_smtp_port', 587);
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = get_option('mnml_smtp_smtp_username');
        $phpmailer->Password = defined('MNML_SMTP_PASSWORD') ? MNML_SMTP_PASSWORD : get_option('mnml_smtp_smtp_password');
        $enc = get_option('mnml_smtp_smtp_encryption', 'tls');
        $phpmailer->SMTPSecure = $enc === 'none' ? '' : $enc; // Map 'none' to ''
        if (defined('DOING_MNMLSMTP') && DOING_MNMLSMTP === 'queue') {
            $phpmailer->SMTPKeepAlive = true;
        }
    }

    public static function send_async($body) {
        $url = plugins_url('async.php', __FILE__);
        $siteurl = get_option('siteurl');
        if (strpos($siteurl, 'https://') === 0 && strpos($url, 'https://') !== 0) {
            $url = str_replace('http://', 'https://', $url);
        }
        $args = [
            'timeout' => 3,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body' => array_merge($body, ['secret' => wp_hash('mnml_smtp_secret')]),
        ];
        error_log('Mnml SMTP: Sending async request to ' . $url . ' with body: ' . print_r($body, true));
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            error_log('Mnml SMTP: Async request error: ' . $response->get_error_message() . '; scheduling fallback cron');
            wp_schedule_single_event(time() + 30, 'mnml_smtp_process_queue');
            return false;
        }
        if (isset($response['response']['code']) && $response['response']['code'] >= 300 && $response['response']['code'] < 400) {
            error_log('Mnml SMTP: Async request redirected (code: ' . $response['response']['code'] . ', location: ' . ($response['headers']['location'] ?? 'unknown') . ')');
            wp_schedule_single_event(time() + 30, 'mnml_smtp_process_queue');
        }
        return $response;
    }

    public static function send_emails($atts) {
        if (get_transient('mnml_smtp_paused')) {
            error_log('Mnml SMTP: Queue paused due to repeated failures');
            delete_transient('mnml_smtp_running');
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mnml_smtp_queue';
        $single_email = isset($atts['id']) ? intval($atts['id']) : 0;

        $start_time = microtime(true);
        if ($single_email) {
            $emails = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND status = 'pending' AND next_attempt <= %d", $single_email, time()));
            if (!$emails) {
                error_log("Mnml SMTP: Requested email $single_email was missing");
                return;
            }
            define('DOING_MNMLSMTP', 'single');
        } else {
            $lock = wp_generate_password(12, false);
            if (!set_transient('mnml_smtp_running', $lock, 300)) {
                error_log('Mnml SMTP: Queue already running');
                return;
            }
            $emails = $wpdb->get_results("SELECT * FROM $table WHERE status = 'pending' AND next_attempt <= " . time() . " ORDER BY next_attempt ASC LIMIT 10");
            if (!$emails) {
                delete_transient('mnml_smtp_running');
                error_log('Mnml SMTP: No pending emails to process');
                return;
            }
            define('DOING_MNMLSMTP', 'queue');
        }

        error_log('Mnml SMTP: DB query time: ' . (microtime(true) - $start_time) . ' seconds');
        error_log('Mnml SMTP: Processing started with ' . count($emails) . ' emails' . ($single_email ? " for ID $single_email" : ''));

        $failed_count = self::get_failed_count();
        $new_failures = 0;
        $next_attempts = [];

        foreach ($emails as $email) {
            if (microtime(true) - $start_time > 30) {
                error_log('Mnml SMTP: Processing timeout reached');
                break;
            }
            if (!$single_email && $email->attempts == 0 && ($email->next_attempt - 30) < time()) {
                continue;
            }

            // Check if delivering locally
            $use_local = false;
            if ( !strpos($email->to_email, ',') ) {// only applys to single recipients
                $local_domains = get_option('mnml_smtp_local_domains', '');
                if ($local_domains) {
                    $local_domains = array_map('trim', explode(',', $local_domains));
                    $email_domain = strtolower(substr(strrchr($email->to_email, '@'), 1) ?: '');
                    if ($email_domain && in_array($email_domain, $local_domains)) {
                        $use_local = true;
                        remove_action('phpmailer_init', [__CLASS__, 'configure_smtp']);
                        unset($GLOBALS['phpmailer']);
                        error_log('Mnml SMTP: Using local delivery for ' . $email->to_email);
                    }
                }
            }
            // Restore SMTP hook for non-local emails
            if (!$use_local) {
                add_action('phpmailer_init', [__CLASS__, 'configure_smtp']);
            }

            try {
                $send_time = microtime(true);
                $result = wp_mail(
                    $email->to_email,
                    $email->subject,
                    $email->message,
                    unserialize($email->headers)
                );
                error_log('Mnml SMTP: Send time for ID ' . $email->id . ': ' . (microtime(true) - $send_time) . ' seconds');

                if ($result) {
                    $update_time = microtime(true);
                    $wpdb->update($table, ['status' => 'sent'], ['id' => $email->id]);
                    error_log('Mnml SMTP: DB update time for ID ' . $email->id . ': ' . (microtime(true) - $update_time) . ' seconds');
                    error_log('Mnml SMTP: Email ID ' . $email->id . ' sent successfully');
                } else {
                    throw new Exception('SMTP failure');
                }
            } catch (Exception $e) {
                $attempts = $email->attempts + 1;
                $intervals = apply_filters('mnml_smtp_retry_intervals', [300, 3600]);
                $max_attempts = count($intervals) + 1;
                error_log('Mnml SMTP: Email ID ' . $email->id . ' failed, attempt ' . $attempts . ' of ' . $max_attempts);
                $error_msg = $e->getMessage();

                if ($attempts >= $max_attempts) {
                    $error_msg = "Max retries ($max_attempts) reached: $error_msg";
                    $wpdb->update($table, [
                        'status' => 'failed',
                        'attempts' => $attempts,
                        'next_attempt' => 0,
                        'error' => $error_msg,
                    ], ['id' => $email->id]);
                    $new_failures++;
                    delete_transient('mnml_smtp_failed_count');
                    error_log('Mnml SMTP: Email ID ' . $email->id . ' marked as failed: ' . $error_msg);

                    if ($failed_count + $new_failures >= 10) {
                        set_transient('mnml_smtp_paused', true, DAY_IN_SECONDS);
                        wp_mail(
                            get_option('admin_email'),
                            'Mnml SMTP Failure Alert',
                            "Multiple emails ($failed_count + $new_failures) failed to send. Update settings at " . admin_url('options-general.php?page=mnml-smtp') . ". View queue at " . admin_url('tools.php?page=mnml-smtp-queue'),
                            ['From: ' . get_option('mnml_smtp_from_name') . ' <' . get_option('mnml_smtp_from_email') . '>']
                        );
                        delete_transient('mnml_smtp_failed_count');
                        error_log('Mnml SMTP: Queue paused due to excessive failures');
                        break;
                    }
                } else {
                    $next_attempt = time() + $intervals[$attempts - 1];
                    $next_attempts[] = $next_attempt;
                    $wpdb->update($table, [
                        'attempts' => $attempts,
                        'next_attempt' => $next_attempt,
                        'error' => $error_msg,
                    ], ['id' => $email->id]);
                    error_log('Mnml SMTP: Email ID ' . $email->id . ' scheduled for retry at ' . date('Y-m-d H:i:s', $next_attempt));
                }
            }
        }

        if (defined('DOING_MNMLSMTP') && DOING_MNMLSMTP === 'queue') {
            global $phpmailer;
            if ($phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer) {
                error_log('Mnml SMTP: Closing SMTP connection');
                $phpmailer->smtpClose();
            }
        }

        if (!empty($next_attempts)) {
            $soonest = min($next_attempts);
            wp_clear_scheduled_hook('mnml_smtp_process_queue');
            wp_schedule_single_event($soonest, 'mnml_smtp_process_queue');
            error_log('Mnml SMTP: Scheduled retry cron for ' . date('Y-m-d H:i:s', $soonest));
        }

        if (!$single_email) {
            delete_transient('mnml_smtp_running');
        }
        error_log('Mnml SMTP: Processing completed in ' . (microtime(true) - $start_time) . ' seconds');
    }

    public static function get_failed_count() {
        $count = get_transient('mnml_smtp_failed_count');
        if ($count === false) {
            global $wpdb;
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mnml_smtp_queue WHERE status = 'failed'");
            set_transient('mnml_smtp_failed_count', $count, 300);
        }
        return (int) $count;
    }

    public static function admin_menu() {
        add_options_page('Mnml SMTP Settings', 'Mnml SMTP', 'manage_options', 'mnml-smtp', [__CLASS__, 'settings_page']);
        add_management_page('Email Queue', 'Email Queue', 'manage_options', 'mnml-smtp-queue', [__CLASS__, 'queue_page']);
    }

    public static function settings_page() {
        $options = [
            'mnml_smtp_' => [
                'mailer_type' => [
                    'type' => 'select',
                    'label' => 'Mailer Type',
                    'options' => [
                        'smtp' => 'Generic SMTP',
                        'ses' => 'Amazon SES SMTP',
                        'google' => 'Google Workspace SMTP',
                        'brevo' => 'Brevo SMTP',
                    ],
                    'desc' => 'Choose the mailer for sending emails.',
                    'sanitize' => 'sanitize_text_field',
                ],
                'from_email' => [
                    'type' => 'email',
                    'label' => 'From Email',
                    'desc' => 'Email address for outgoing emails.',
                    'sanitize' => 'sanitize_email',
                ],
                'from_name' => [
                    'type' => 'text',
                    'label' => 'From Name',
                    'desc' => 'Name for outgoing emails.',
                    'sanitize' => 'sanitize_text_field',
                ],
                'local_domains' => [
                    'type' => 'text',
                    'label' => 'Local Domains',
                    'desc' => 'Domains to deliver locally, bypassing SMTP.',
                    'sanitize' => 'sanitize_text_field',
                ],
                'queue_expiry' => [
                    'type' => 'number',
                    'label' => 'Queue Expiry',
                    'desc' => 'Days to keep sent/failed emails (0 to disable).',
                    'default' => 7,
                    'size' => 'small',
                    'sanitize' => function ($value) { return max(0, (int)$value); },
                ],
                'test_email' => [
                    'type' => 'callback',
                    'label' => 'Test Email',
                    'callback' => 'MnmlSMTP::render_test_email',
                    'desc' => 'Send a test email to verify settings.',
                ],
                'smtp_host' => [
                    'type' => 'text',
                    'label' => 'SMTP Host',
                    'desc' => 'SMTP server host (e.g., smtp.gmail.com, email-smtp.us-east-1.amazonaws.com).',
                    'show' => ['mailer_type' => ['smtp', 'ses', 'google', 'brevo']],
                    'sanitize' => 'sanitize_text_field',
                ],
                'smtp_port' => [
                    'type' => 'number',
                    'label' => 'SMTP Port',
                    'desc' => 'SMTP port (e.g., 587 for TLS).',
                    'show' => ['mailer_type' => ['smtp', 'ses', 'google', 'brevo']],
                    'size' => 'small',
                    'default' => '587',
                    'sanitize' => function ($value) { return max(1, (int)$value); },
                ],
                'smtp_encryption' => [
                    'type' => 'select',
                    'label' => 'SMTP Encryption',
                    'options' => [
                        'none' => 'None',
                        'tls' => 'TLS',
                        'ssl' => 'SSL',
                    ],
                    'default' => 'tls',
                    'desc' => 'Encryption type for SMTP.',
                    'show' => ['mailer_type' => ['smtp', 'ses', 'google', 'brevo']],
                    'sanitize' => 'sanitize_text_field',
                ],
                'smtp_username' => [
                    'type' => 'text',
                    'label' => 'SMTP Username',
                    'desc' => 'SMTP username or Google app password.',
                    'show' => ['mailer_type' => ['smtp', 'ses', 'google', 'brevo']],
                    'sanitize' => 'sanitize_text_field',
                ],
                'smtp_password' => [
                    'type' => 'password',
                    'label' => 'SMTP Password',
                    'desc' => 'SMTP password (store in wp-config.php for security).',
                    'show' => ['mailer_type' => ['smtp', 'ses', 'google', 'brevo']],
                    'sanitize' => 'sanitize_text_field',
                ],
            ],
        ];
        $title = 'Mnml SMTP Settings';
        require __DIR__ . '/settings-framework.php';
        echo "<p><a href='" . admin_url('tools.php?page=mnml-smtp-queue') . "'>View Email Log</a></p>";
    }

    public static function render_test_email($key, $value, $field) {
        $admin_email = esc_attr(get_option('admin_email'));
        echo '<button type="button" id="mnml_smtp_test" class="button">Send Test Email</button>';
        echo ' <input type="email" id="mnml_smtp_test_email" value="' . $admin_email . '" class="regular-text" placeholder="Enter email address">';
        echo '<div id="mnml_smtp_test_result"></div>';
        ?>
        <script>
            document.getElementById('mnml_smtp_test').addEventListener('click', function () {
                const email = document.getElementById('mnml_smtp_test_email').value;
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=mnml_smtp_test_email&_wpnonce=<?php echo wp_create_nonce('mnml_smtp_test'); ?>&email=' + encodeURIComponent(email)
                })
                .then(r => r.json())
                .then(r => document.getElementById('mnml_smtp_test_result').innerText = r.message);
            });
        </script>
        <?php
    }

    public static function test_email() {
        check_ajax_referer('mnml_smtp_test');
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : get_option('admin_email');
        if (!is_email($email)) {
            wp_send_json(['message' => 'Invalid email address provided.']);
        }
        try {
            wp_mail(
                $email,
                'Mnml SMTP Test Email',
                'This is a test email from Mnml SMTP.',
                []
            );
            wp_send_json(['message' => 'Test email queued successfully to ' . esc_html($email) . '.']);
        } catch (Exception $e) {
            wp_send_json(['message' => 'Test email failed: ' . $e->getMessage()]);
        }
    }

    public static function queue_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        // Handle bulk actions
        $notice = '';
        if (isset($_REQUEST['action']) && in_array($_REQUEST['action'], ['resend_all_failed', 'resend_checked', 'clear_sent', 'clear_failed']) && check_admin_referer('mnml_smtp_bulk', 'mnml_smtp_bulk_nonce')) {
            $notice = self::handle_bulk();
        }
        require_once __DIR__ . '/class-mnml-smtp-queue-table.php';
        $table = new Mnml_SMTP_Queue_Table();
        $table->prepare_items();
        ?>
        <div class='wrap'>
            <h1>Email Queue</h1>
            <?php if (get_transient('mnml_smtp_paused')): ?>
                <div class='notice notice-error is-dismissible'>
                    <p>Queue paused due to excessive failures. <a href='<?php echo esc_url(admin_url('options-general.php?page=mnml-smtp')); ?>'>Update settings</a> or <a href='<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mnml_smtp_resume'), 'mnml_smtp_resume')); ?>'>resume queue</a>.</p>
                </div>
            <?php endif; ?>
            <?php if ($notice): ?>
                <div class='notice notice-success is-dismissible'>
                    <p><?php echo esc_html($notice); ?></p>
                </div>
            <?php endif; ?>
            <form method='post' id='mnml-smtp-queue-form'>
                <?php wp_nonce_field('mnml_smtp_bulk', 'mnml_smtp_bulk_nonce'); ?>
                <?php $table->display(); ?>
            </form>
            <dialog id='mnml-smtp-dialog' class='mnml-smtp-dialog'>
                <div><div id='mnml-smtp-dialog-body'></div></div>
            </dialog>
            <style>
                .mnml-smtp-queue tr.sent .msg {text-decoration:line-through}
                dialog.mnml-smtp-dialog {width:910px;max-width:90%;border:1px solid #555;border-radius:3px}
                dialog.mnml-smtp-dialog::backdrop {background:rgba(0,0,0,.5)}
                .mnml-smtp-dialog p {margin:5px 0}
            </style>
            <script>
                document.getElementById('mnml-smtp-queue-form').addEventListener('submit', function(e) {
                    const action = this.querySelector('select[name="bulk_action"]').value;
                    const selected = document.querySelectorAll('input[name="email_ids[]"]:checked').length;
                    if (action && !confirm(
                        action === 'resend_all_failed' ? 'Resend all failed emails?' :
                        action === 'resend_checked' ? `Resend ${selected} selected email${selected > 1 ? 's' : ''}?` :
                        action === 'clear_sent' ? 'Clear all sent emails?' :
                        'Clear all failed emails?'
                    )) {
                        e.preventDefault();
                    }
                });
                document.querySelector('.wp-list-table').addEventListener('click', function(e) {
                    if (e.target.matches('a[data-action]')) {
                        e.preventDefault();
                        const action = e.target.dataset.action;
                        const emailId = e.target.dataset.id;
                        if (action === 'resend') {
                            if (confirm('Resend this email?')) {
                                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: `action=mnml_smtp_resend&email_id=${emailId}&_wpnonce=<?php echo wp_create_nonce('mnml_smtp_resend'); ?>`
                                })
                                .then(response => response.text())
                                .then(result => {
                                    if (result === 'ok') {
                                        e.target.closest('tr').querySelector('td:nth-child(5)').textContent = 'pending';
                                    }
                                })
                                .catch(error => console.error('Resend failed:', error));
                            }
                        } else if (action === 'view') {
                            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: `action=mnml_smtp_view_email&email_id=${emailId}&_wpnonce=<?php echo wp_create_nonce('mnml_smtp_queue'); ?>`
                            })
                            .then(response => response.json())
                            .then(result => {
                                const dialog = document.getElementById('mnml-smtp-dialog');
                                dialog.querySelector('#mnml-smtp-dialog-body').innerHTML = result.content;
                                dialog.showModal();
                            })
                            .catch(error => console.error('View failed:', error));
                        }
                    }
                });
                document.getElementById('mnml-smtp-dialog').addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.close();
                    }
                });
            </script>
        </div>
        <?php
    }

    public static function handle_bulk() {
        if (!current_user_can('manage_options') || !check_admin_referer('mnml_smtp_bulk', 'mnml_smtp_bulk_nonce')) {
            wp_die('Unauthorized');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'mnml_smtp_queue';
        $action = isset($_REQUEST['action']) && $_REQUEST['action'] !== '-1' ? sanitize_text_field($_REQUEST['action']) : (isset($_REQUEST['action2']) && $_REQUEST['action2'] !== '-1' ? sanitize_text_field($_REQUEST['action2']) : '');
        $email_ids = isset($_POST['email_ids']) && is_array($_POST['email_ids']) ? array_map('intval', $_POST['email_ids']) : [];

        if ($action === 'resend_all_failed') {
            $ids = $wpdb->get_col("SELECT id FROM $table WHERE status = 'failed'");
            foreach ($ids as $id) {
                $wpdb->update($table, ['status' => 'pending', 'attempts' => 0, 'next_attempt' => time(), 'error' => ''], ['id' => $id]);
                self::send_async(['id' => $id]);
            }
            error_log('Mnml SMTP: Resent all failed emails');
            return 'All failed emails have been queued for resending.';
        } elseif ($action === 'resend_checked' && $email_ids) {
            foreach ($email_ids as $id) {
                $wpdb->update($table, ['status' => 'pending', 'attempts' => 0, 'next_attempt' => time(), 'error' => ''], ['id' => $id]);
                self::send_async(['id' => $id]);
            }
            error_log('Mnml SMTP: Resent ' . count($email_ids) . ' selected emails');
            return count($email_ids) . ' selected email' . (count($email_ids) > 1 ? 's' : '') . ' have been queued for resending.';
        } elseif ($action === 'clear_sent') {
            $wpdb->delete($table, ['status' => 'sent']);
            error_log('Mnml SMTP: Cleared sent emails');
            return 'All sent emails have been cleared.';
        } elseif ($action === 'clear_failed') {
            $wpdb->delete($table, ['status' => 'failed']);
            error_log('Mnml SMTP: Cleared failed emails');
            delete_transient('mnml_smtp_failed_count');
            return 'All failed emails have been cleared.';
        }
        return '';
    }

    public static function ajax_resend() {
        check_ajax_referer('mnml_smtp_resend', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        global $wpdb;
        $table = $wpdb->prefix . 'mnml_smtp_queue';
        $email_id = isset($_POST['email_id']) ? intval($_POST['email_id']) : 0;
        if ($email_id) {
            $wpdb->update($table, ['status' => 'pending', 'attempts' => 0, 'next_attempt' => time(), 'error' => ''], ['id' => $email_id]);
            self::send_async(['id' => $email_id]);
            wp_send_json('ok');
        }
        wp_send_json_error('Invalid email ID');
    }

    public static function ajax_view_email() {
        check_ajax_referer('mnml_smtp_queue');
        global $wpdb;
        $email_id = intval($_POST['email_id']);
        $email = $wpdb->get_row($wpdb->prepare("SELECT to_email, subject, message FROM {$wpdb->prefix}mnml_smtp_queue WHERE id = %d", $email_id));
        if ($email) {
            add_filter('wp_kses_allowed_html', function ($tags, $context) {
                if ($context === 'post') {
                    $tags['style'] = [];
                }
                return $tags;
            }, 10, 2);
            $content = '<p><strong>To:</strong> ' . esc_html($email->to_email) . '</p>';
            $content .= '<p><strong>Subject:</strong> ' . esc_html($email->subject) . '</p>';
            $content .= '<p><strong>Body:</strong></p>' . wp_kses_post($email->message);
            wp_send_json(['content' => $content]);
        } else {
            wp_send_json(['content' => 'Email not found']);
        }
    }

    public static function resume_queue() {
        check_admin_referer('mnml_smtp_resume');
        delete_transient('mnml_smtp_paused');
        self::send_async(['queue' => true]);
        wp_redirect(admin_url('tools.php?page=mnml-smtp-queue'));
        exit;
    }

    public static function paused_notice() {
        if (!get_transient('mnml_smtp_paused') || !current_user_can('manage_options') || !in_array(get_current_screen()->id, ['settings_page_mnml-smtp', 'tools_page_mnml-smtp-queue'])) {
            return;
        }
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p>Mnml SMTP queue is paused due to repeated failures. <a href="' . admin_url('options-general.php?page=mnml-smtp') . '">Update settings</a> or <a href="' . wp_nonce_url(admin_url('admin-post.php?action=mnml_smtp_resume'), 'mnml_smtp_resume') . '">resume queue</a>.</p>';
        echo '</div>';
    }
}

MnmlSMTP::init();