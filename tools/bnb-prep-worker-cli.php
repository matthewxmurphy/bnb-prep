<?php
/**
 * BNB Prep — standalone worker (the local-worker contract).
 *
 * Run a queued prep job outside of WordPress, on a machine that has
 * PhotoCraft installed. This is the route for PSDs too large to process
 * inside a web request, and for hosts where the web server cannot run
 * the engine.
 *
 * Usage:
 *   php bnb-prep-worker-cli.php --job-dir=/path/to/job [--settings=/path/settings.json]
 *
 * The job directory must contain:
 *   input.psd     the uploaded design
 *   job.json      {"source_name": "design.psd", "upscale": 100}
 *   settings.json {"cli_path": "...", "cli_prefix": ["sudo","-u","mmurphy"], "magick_bin": ""}
 *                 (the plugin writes this snapshot at upload time;
 *                  --settings can point anywhere else)
 *
 * On success the job directory gains result.zip, manifest.json and a
 * status.json with state "done", which the WordPress side then offers
 * as the job's download.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool runs from the command line only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/class-bnb-prep-worker.php';

$opts = getopt('', array('job-dir:', 'settings::'));
$jobDir = isset($opts['job-dir']) ? rtrim($opts['job-dir'], '/') : '';
if ($jobDir === '' || !is_dir($jobDir)) {
    fwrite(STDERR, "Usage: php bnb-prep-worker-cli.php --job-dir=/path/to/job [--settings=...]\n");
    exit(2);
}
if (!file_exists($jobDir . '/input.psd')) {
    fwrite(STDERR, "Job directory has no input.psd.\n");
    exit(2);
}

$settingsFile = isset($opts['settings']) ? $opts['settings'] : $jobDir . '/settings.json';
$settings = array();
if (is_file($settingsFile)) {
    $decoded = json_decode((string) file_get_contents($settingsFile), true);
    if (is_array($decoded)) {
        $settings = $decoded;
    }
}
if (empty($settings['cli_path'])) {
    fwrite(STDERR, "No cli_path in settings ({$settingsFile}). Nothing to run — the job stays queued.\n");
    exit(2);
}

$worker = new BNB_Prep_Worker($settings);
$status = $worker->run_job($jobDir);
foreach ($worker->log_lines() as $line) {
    echo $line, "\n";
}
if ($status['state'] === 'done') {
    echo "DONE: {$status['count']} layer PNG(s) -> {$jobDir}/result.zip\n";
    exit(0);
}
fwrite(STDERR, 'FAILED: ' . (isset($status['error']) ? $status['error'] : 'unknown error') . "\n");
exit(1);
