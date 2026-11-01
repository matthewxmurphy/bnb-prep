<?php
/**
 * Plugin Name:       BNB Prep
 * Plugin URI:        https://github.com/matthewxmurphy/bnb-prep
 * Description:       Print-prep for layered designs: upload a PSD, get back a ZIP of one trimmed PNG per layer (pure layer names, groups flattened, hidden layers included), with an optional upscale. Built for print and wrap shops running WordPress.
 * Version:           2026.10.31
 * Author:            Built Not Begged
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       bnb-prep
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BNB_PREP_VERSION', '2026.10.31');
define('BNB_PREP_FILE', __FILE__);
define('BNB_PREP_DIR', plugin_dir_path(__FILE__));

require_once BNB_PREP_DIR . 'includes/class-bnb-prep-license.php';
require_once BNB_PREP_DIR . 'includes/class-bnb-prep-worker.php';

class BNB_Prep
{
    const OPTION_SETTINGS = 'bnb_prep_settings';
    const CAPABILITY = 'manage_options';

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_post_bnb_prep_upload', array(__CLASS__, 'handle_upload'));
        add_action('admin_post_bnb_prep_run', array(__CLASS__, 'handle_run'));
        add_action('admin_post_bnb_prep_download', array(__CLASS__, 'handle_download'));
        add_action('admin_post_bnb_prep_delete', array(__CLASS__, 'handle_delete'));
        add_action('admin_notices', array(__CLASS__, 'notices'));
    }

    public static function defaults()
    {
        return array(
            'cli_path'    => '',
            'cli_prefix'  => '',
            'magick_bin'  => '',
            'auto_run'    => 1,
            'max_sync_mb' => 60,
        );
    }

    public static function settings()
    {
        $stored = get_option(self::OPTION_SETTINGS, array());
        return array_merge(self::defaults(), is_array($stored) ? $stored : array());
    }

    public static function activate()
    {
        $dir = self::base_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        // Keep job files out of casual web reach where the server honors it.
        $guard = $dir . '/index.php';
        if (!file_exists($guard)) {
            file_put_contents($guard, "<?php // Silence is golden.\n");
        }
        BNB_Prep_License::register_activation();
    }

    public static function base_dir()
    {
        $uploads = wp_upload_dir();
        return $uploads['basedir'] . '/bnb-prep';
    }

    public static function base_url()
    {
        $uploads = wp_upload_dir();
        return $uploads['baseurl'] . '/bnb-prep';
    }

    /* ------------------------------------------------------------------ */
    /* Admin screen                                                        */
    /* ------------------------------------------------------------------ */

    public static function menu()
    {
        add_menu_page(
            'BNB Prep',
            'BNB Prep',
            self::CAPABILITY,
            'bnb-prep',
            array(__CLASS__, 'render_page'),
            'dashicons-format-image',
            58
        );
    }

    public static function register_settings()
    {
        register_setting('bnb_prep', self::OPTION_SETTINGS, array(
            'type'              => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
        ));
    }

    public static function sanitize_settings($input)
    {
        $out = self::defaults();
        if (is_array($input)) {
            $out['cli_path'] = isset($input['cli_path']) ? trim((string) $input['cli_path']) : '';
            $out['cli_prefix'] = isset($input['cli_prefix']) ? trim((string) $input['cli_prefix']) : '';
            $out['magick_bin'] = isset($input['magick_bin']) ? trim((string) $input['magick_bin']) : '';
            $out['auto_run'] = !empty($input['auto_run']) ? 1 : 0;
            $out['max_sync_mb'] = isset($input['max_sync_mb']) ? max(1, min(2048, (int) $input['max_sync_mb'])) : 60;
        }
        return $out;
    }

    public static function notices()
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'bnb-prep') {
            return;
        }
        if (isset($_GET['bnb_notice'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['bnb_notice']));
            if ($msg !== '') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
        }
        if (isset($_GET['bnb_error'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['bnb_error']));
            if ($msg !== '') {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
        }
    }

    public static function render_page()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('You do not have permission to use BNB Prep.');
        }
        $s = self::settings();
        $jobs = self::list_jobs();
        $license = BNB_Prep_License::state();
        ?>
        <div class="wrap">
            <h1>BNB Prep</h1>
            <p>Upload a layered PSD and get back a ZIP with one trimmed PNG per layer.
               Top-level layers export individually; a group exports as one flattened PNG
               named for the group; hidden top-level layers are included too.
               License status (upgrades only, the plugin never stops working):
               <strong><?php echo esc_html($license['status']); ?></strong>.</p>

            <h2>New prep job</h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('bnb_prep_upload'); ?>
                <input type="hidden" name="action" value="bnb_prep_upload">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bnb_prep_psd">PSD file</label></th>
                        <td><input type="file" id="bnb_prep_psd" name="bnb_prep_psd" accept=".psd" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bnb_prep_upscale">Upscale</label></th>
                        <td>
                            <input type="number" id="bnb_prep_upscale" name="bnb_prep_upscale" value="100" min="10" max="800" step="1"> %
                            <p class="description">100 = original size. 120 matches the common “enlarge 20% for print” prep step.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Upload PSD'); ?>
            </form>
            <p class="description">
                Files up to <?php echo esc_html((string) $s['max_sync_mb']); ?> MB run right away when
                “run after upload” is on below. Bigger files are queued for the standalone worker:
                <code>php tools/bnb-prep-worker-cli.php --job-dir=&lt;job dir&gt;</code>
                on a machine with PhotoCraft installed.
            </p>

            <h2>Jobs</h2>
            <?php if (!$jobs) : ?>
                <p>No jobs yet.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Job</th><th>Source</th><th>Upscale</th><th>State</th>
                            <th>Layers</th><th>Created</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jobs as $job) : ?>
                        <tr>
                            <td><code><?php echo esc_html($job['id']); ?></code></td>
                            <td><?php echo esc_html($job['source_name']); ?></td>
                            <td><?php echo esc_html((string) $job['upscale']); ?>%</td>
                            <td><?php echo esc_html($job['state']); ?></td>
                            <td><?php echo esc_html($job['count'] !== null ? (string) $job['count'] : ''); ?></td>
                            <td><?php echo esc_html($job['created']); ?></td>
                            <td>
                                <?php if ($job['state'] === 'queued' || $job['state'] === 'failed') : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <?php wp_nonce_field('bnb_prep_run'); ?>
                                        <input type="hidden" name="action" value="bnb_prep_run">
                                        <input type="hidden" name="job" value="<?php echo esc_attr($job['id']); ?>">
                                        <button class="button">Run prep</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($job['state'] === 'done') : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                        <?php wp_nonce_field('bnb_prep_download'); ?>
                                        <input type="hidden" name="action" value="bnb_prep_download">
                                        <input type="hidden" name="job" value="<?php echo esc_attr($job['id']); ?>">
                                        <button class="button button-primary">Download ZIP</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('Delete this job and its files?');">
                                    <?php wp_nonce_field('bnb_prep_delete'); ?>
                                    <input type="hidden" name="action" value="bnb_prep_delete">
                                    <input type="hidden" name="job" value="<?php echo esc_attr($job['id']); ?>">
                                    <button class="button">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php if (!empty($job['error'])) : ?>
                            <tr><td colspan="7"><strong>Error:</strong> <?php echo esc_html($job['error']); ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($job['layers'])) : ?>
                            <tr><td colspan="7">
                                <?php foreach ($job['layers'] as $l) : ?>
                                    <div>
                                        <code><?php echo esc_html($l['zip_name']); ?></code>
                                        — canvas <?php echo esc_html($l['canvas']); ?>, trimmed <?php echo esc_html($l['trimmed']); ?>
                                        <?php if (!empty($l['was_hidden'])) : ?> (was hidden)<?php endif; ?>
                                        <?php if ($l['kind'] === 'group') : ?> (flattened group)<?php endif; ?>
                                        <?php if (!empty($l['flags'])) : ?> [<?php echo esc_html(implode(', ', $l['flags'])); ?>]<?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td></tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2>Settings</h2>
            <form method="post" action="options.php">
                <?php settings_fields('bnb_prep'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bnb_prep_cli_path">photocraft-cli path</label></th>
                        <td>
                            <input type="text" class="regular-text" id="bnb_prep_cli_path"
                                   name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[cli_path]"
                                   value="<?php echo esc_attr($s['cli_path']); ?>">
                            <p class="description">Full path to the PhotoCraft CLI on this server. Leave empty to queue every job for the standalone worker.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bnb_prep_cli_prefix">Run-as prefix</label></th>
                        <td>
                            <input type="text" class="regular-text" id="bnb_prep_cli_prefix"
                                   name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[cli_prefix]"
                                   value="<?php echo esc_attr($s['cli_prefix']); ?>">
                            <p class="description">Optional command prefix, e.g. <code>sudo -u mmurphy</code>, when the CLI is installed under a different user than the web server.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bnb_prep_magick_bin">ImageMagick binary</label></th>
                        <td>
                            <input type="text" class="regular-text" id="bnb_prep_magick_bin"
                                   name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[magick_bin]"
                                   value="<?php echo esc_attr($s['magick_bin']); ?>">
                            <p class="description">Blank = auto-detect (<code>magick</code>, then <code>convert</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Run after upload</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[auto_run]" value="1" <?php checked(1, (int) $s['auto_run']); ?>>
                                Run the prep as soon as a PSD is uploaded (files within the size limit below)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bnb_prep_max_sync">Sync size limit (MB)</label></th>
                        <td>
                            <input type="number" id="bnb_prep_max_sync" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[max_sync_mb]"
                                   value="<?php echo esc_attr((string) $s['max_sync_mb']); ?>" min="1" max="2048">
                            <p class="description">Larger PSDs queue for the standalone worker instead of running in the web request.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Jobs                                                                */
    /* ------------------------------------------------------------------ */

    public static function list_jobs()
    {
        $base = self::base_dir();
        $jobs = array();
        if (!is_dir($base)) {
            return $jobs;
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            $job = self::read_job($dir);
            if ($job) {
                $jobs[] = $job;
            }
        }
        usort($jobs, function ($a, $b) {
            return strcmp($b['id'], $a['id']);
        });
        return $jobs;
    }

    private static function read_job($dir)
    {
        $jobFile = $dir . '/job.json';
        if (!file_exists($jobFile)) {
            return null;
        }
        $job = json_decode((string) file_get_contents($jobFile), true);
        if (!is_array($job)) {
            return null;
        }
        $status = array();
        if (file_exists($dir . '/status.json')) {
            $status = json_decode((string) file_get_contents($dir . '/status.json'), true);
            if (!is_array($status)) {
                $status = array();
            }
        }
        return array(
            'id'          => basename($dir),
            'dir'         => $dir,
            'source_name' => isset($job['source_name']) ? $job['source_name'] : 'input.psd',
            'upscale'     => isset($job['upscale']) ? (int) $job['upscale'] : 100,
            'created'     => isset($job['created']) ? $job['created'] : '',
            'state'       => isset($status['state']) ? $status['state'] : 'queued',
            'count'       => isset($status['count']) ? (int) $status['count'] : null,
            'error'       => isset($status['error']) ? $status['error'] : '',
            'layers'      => isset($status['layers']) && is_array($status['layers']) ? $status['layers'] : array(),
        );
    }

    private static function job_dir_from_request()
    {
        $id = isset($_POST['job']) ? sanitize_file_name(wp_unslash($_POST['job'])) : '';
        if ($id === '') {
            return null;
        }
        $dir = self::base_dir() . '/' . $id;
        return is_dir($dir) ? $dir : null;
    }

    private static function redirect($args)
    {
        $url = add_query_arg(array_merge(array('page' => 'bnb-prep'), $args), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public static function handle_upload()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('No permission.');
        }
        check_admin_referer('bnb_prep_upload');
        if (!isset($_FILES['bnb_prep_psd']) || !isset($_FILES['bnb_prep_psd']['tmp_name']) ||
            !is_uploaded_file($_FILES['bnb_prep_psd']['tmp_name'])) {
            self::redirect(array('bnb_error' => 'No file was uploaded.'));
        }
        $file = $_FILES['bnb_prep_psd'];
        if (!empty($file['error'])) {
            self::redirect(array('bnb_error' => 'Upload failed with error code ' . (int) $file['error'] . '.'));
        }
        $name = isset($file['name']) ? sanitize_file_name($file['name']) : 'design.psd';
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'psd') {
            self::redirect(array('bnb_error' => 'Only .psd files are accepted.'));
        }
        $upscale = isset($_POST['bnb_prep_upscale']) ? max(10, min(800, (int) $_POST['bnb_prep_upscale'])) : 100;
        $settings = self::settings();

        $id = gmdate('Ymd-His') . '-' . substr(wp_generate_password(8, false, false), 0, 8);
        $dir = self::base_dir() . '/' . $id;
        wp_mkdir_p($dir);
        // The prep engine may run as a different user than the web server;
        // it needs to write its export folders inside the job directory.
        @chmod($dir, 0777);
        if (!move_uploaded_file($file['tmp_name'], $dir . '/input.psd')) {
            self::redirect(array('bnb_error' => 'Could not store the uploaded file.'));
        }
        @chmod($dir . '/input.psd', 0666);

        $job = array(
            'source_name' => $name,
            'upscale'     => $upscale,
            'created'     => gmdate('c'),
        );
        file_put_contents($dir . '/job.json', json_encode($job, JSON_PRETTY_PRINT));
        // Snapshot the worker settings so the standalone CLI worker can run
        // this job later with the exact same configuration.
        file_put_contents($dir . '/settings.json', json_encode(self::worker_settings($settings), JSON_PRETTY_PRINT));
        file_put_contents($dir . '/status.json', json_encode(array('state' => 'queued'), JSON_PRETTY_PRINT));

        $tooBig = $file['size'] > (int) $settings['max_sync_mb'] * 1024 * 1024;
        if (!empty($settings['auto_run']) && !$tooBig && trim((string) $settings['cli_path']) !== '') {
            self::run_worker($dir);
            $after = self::read_job($dir);
            if ($after && $after['state'] === 'done') {
                self::redirect(array('bnb_notice' => 'Prep finished: ' . $after['count'] . ' layer PNG(s) in the ZIP.'));
            }
            self::redirect(array('bnb_error' => 'Prep failed: ' . ($after ? $after['error'] : 'unknown error')));
        }
        if ($tooBig) {
            self::redirect(array('bnb_notice' => 'Job queued: the file is over the sync limit, so run it with the standalone worker.'));
        }
        self::redirect(array('bnb_notice' => 'Job queued. Use “Run prep” to process it.'));
    }

    public static function handle_run()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('No permission.');
        }
        check_admin_referer('bnb_prep_run');
        $dir = self::job_dir_from_request();
        if (!$dir) {
            self::redirect(array('bnb_error' => 'Job not found.'));
        }
        self::run_worker($dir);
        $after = self::read_job($dir);
        if ($after && $after['state'] === 'done') {
            self::redirect(array('bnb_notice' => 'Prep finished: ' . $after['count'] . ' layer PNG(s) in the ZIP.'));
        }
        self::redirect(array('bnb_error' => 'Prep failed: ' . ($after ? $after['error'] : 'unknown error')));
    }

    public static function handle_download()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('No permission.');
        }
        check_admin_referer('bnb_prep_download');
        $dir = self::job_dir_from_request();
        if (!$dir) {
            wp_die('Job not found.');
        }
        $zip = $dir . '/result.zip';
        if (!file_exists($zip)) {
            wp_die('This job has no ZIP yet.');
        }
        $job = self::read_job($dir);
        $downloadName = 'bnb-prep-' . basename($dir) . '.zip';
        if ($job && $job['source_name'] !== '') {
            $downloadName = pathinfo($job['source_name'], PATHINFO_FILENAME) . '-prep.zip';
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($zip));
        readfile($zip);
        exit;
    }

    public static function handle_delete()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('No permission.');
        }
        check_admin_referer('bnb_prep_delete');
        $dir = self::job_dir_from_request();
        if (!$dir) {
            self::redirect(array('bnb_error' => 'Job not found.'));
        }
        self::delete_tree($dir);
        self::redirect(array('bnb_notice' => 'Job deleted.'));
    }

    private static function delete_tree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /* ------------------------------------------------------------------ */
    /* Worker glue                                                         */
    /* ------------------------------------------------------------------ */

    private static function worker_settings(array $settings)
    {
        $prefix = array();
        $raw = trim((string) $settings['cli_prefix']);
        if ($raw !== '') {
            $prefix = preg_split('/\s+/', $raw);
        }
        return array(
            'cli_path'   => trim((string) $settings['cli_path']),
            'cli_prefix' => $prefix,
            'magick_bin' => trim((string) $settings['magick_bin']),
        );
    }

    private static function run_worker($dir)
    {
        $settings = self::settings();
        if (trim((string) $settings['cli_path']) === '') {
            file_put_contents($dir . '/status.json', json_encode(array(
                'state' => 'failed',
                'error' => 'No photocraft-cli path is configured in BNB Prep settings. ' .
                    'Either set it, or run this job with the standalone worker: ' .
                    'php tools/bnb-prep-worker-cli.php --job-dir=' . $dir,
            ), JSON_PRETTY_PRINT));
            return;
        }
        @set_time_limit(0);
        $worker = new BNB_Prep_Worker(self::worker_settings($settings));
        $worker->run_job($dir);
    }
}

add_action('plugins_loaded', array('BNB_Prep', 'init'));
register_activation_hook(__FILE__, array('BNB_Prep', 'activate'));
