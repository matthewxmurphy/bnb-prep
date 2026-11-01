<?php
/**
 * BNB Prep — prep worker.
 *
 * Turns one layered PSD into a ZIP of per-layer, trimmed PNGs.
 *
 * Pipeline (all steps proven against PhotoCraft 0.18.0):
 *   1. Open the PSD in a headless `photocraft-cli serve` session (stdio,
 *      JSON lines) rooted at the job directory.
 *   2. Read the layer tree with doc.inspect (names, kinds, visibility,
 *      group children).
 *   3. Optional upscale: engine image.imageSize {"percent": N} before export.
 *   4. doc.exportLayers writes one full-canvas PNG per TOP-LEVEL entry.
 *      Groups export as a single flattened PNG named for the group; hidden
 *      children inside a group stay out of the render. Top-level hidden
 *      layers are reported as skipped ("hidden by visibleOnly")...
 *   5. ...so when any top-level entry is hidden, visibility is flipped and
 *      exportLayers runs a second pass; the passes are merged by layer name.
 *   6. Each PNG is trimmed with ImageMagick (-trim +repage). A layer that
 *      would trim to under 2px on either side (solid or fully transparent)
 *      keeps its full-canvas PNG and is flagged in the manifest instead.
 *   7. Outputs in the job directory: result.zip (pure layer names +
 *      manifest.json + README.txt), manifest.json, status.json.
 *
 * This class is deliberately WordPress-free so the standalone CLI worker
 * (tools/bnb-prep-worker-cli.php) can run the exact same code from cron or
 * SSH on a host that has PhotoCraft installed, for shops whose web server
 * cannot run the engine itself.
 */

if (!class_exists('BNB_Prep_Serve')) {
    /**
     * Minimal client for `photocraft-cli serve` on stdio (JSON lines).
     */
    class BNB_Prep_Serve
    {
        /** @var resource|null */
        private $proc = null;
        /** @var resource|null */
        private $in = null;
        /** @var resource|null */
        private $out = null;
        /** @var int */
        private $seq = 0;
        /** @var int */
        private $timeout;

        /**
         * @param string[] $cmd     Command tokens (already split, no shell).
         * @param string   $errLog  File to receive the engine's stderr.
         * @param int      $timeout Per-response read timeout in seconds.
         */
        public function __construct(array $cmd, $errLog, $timeout = 900)
        {
            $this->timeout = $timeout;
            $desc = array(
                0 => array('pipe', 'r'),
                1 => array('pipe', 'w'),
                2 => array('file', $errLog, 'a'),
            );
            $this->proc = proc_open($cmd, $desc, $pipes);
            if (!is_resource($this->proc)) {
                throw new RuntimeException('Could not start photocraft-cli serve.');
            }
            $this->in = $pipes[0];
            $this->out = $pipes[1];
            stream_set_timeout($this->out, $this->timeout);
        }

        /**
         * @param string $method e.g. 'doc.open', 'doc.inspect', 'engine.execute'
         * @param array  $params
         * @return mixed Decoded "result" payload.
         */
        public function call($method, array $params = array())
        {
            $this->seq++;
            $id = $this->seq;
            $line = json_encode(array('id' => $id, 'method' => $method, 'params' => $params));
            if (fwrite($this->in, $line . "\n") === false) {
                throw new RuntimeException('Failed writing to photocraft-cli serve.');
            }
            fflush($this->in);
            while (true) {
                $resp = fgets($this->out);
                if ($resp === false) {
                    $meta = stream_get_meta_data($this->out);
                    if (!empty($meta['timed_out'])) {
                        throw new RuntimeException("Timed out ({$this->timeout}s) waiting for {$method}.");
                    }
                    throw new RuntimeException("photocraft-cli serve closed the pipe during {$method}.");
                }
                $data = json_decode(trim($resp), true);
                if (!is_array($data)) {
                    continue; // Non-JSON noise line; keep reading.
                }
                if (isset($data['id']) && (int) $data['id'] !== $id) {
                    continue;
                }
                if (isset($data['error'])) {
                    $err = is_string($data['error']) ? $data['error'] : json_encode($data['error']);
                    throw new RuntimeException("{$method} failed: {$err}");
                }
                return isset($data['result']) ? $data['result'] : null;
            }
        }

        public function engine($command, array $params = array())
        {
            return $this->call('engine.execute', array('command' => $command, 'params' => $params));
        }

        public function close()
        {
            if (is_resource($this->in)) {
                fclose($this->in);
            }
            if (is_resource($this->out)) {
                fclose($this->out);
            }
            if (is_resource($this->proc)) {
                proc_terminate($this->proc);
                proc_close($this->proc);
            }
            $this->proc = null;
        }

        public function __destruct()
        {
            $this->close();
        }
    }
}

if (!class_exists('BNB_Prep_Worker')) {
    class BNB_Prep_Worker
    {
        /** @var array */
        private $settings;
        /** @var string[] */
        private $log = array();

        public function __construct(array $settings)
        {
            $this->settings = array_merge(
                array(
                    'cli_path'   => '',
                    // Extra command tokens placed before the CLI path, e.g.
                    // array('sudo', '-u', 'mmurphy') when the engine is
                    // installed under another user (Kenny dev setup).
                    'cli_prefix' => array(),
                    'magick_bin' => '', // '' = auto-detect: magick, else convert.
                ),
                $settings
            );
        }

        public function log_lines()
        {
            return $this->log;
        }

        private function log($msg)
        {
            $this->log[] = $msg;
        }

        /**
         * Run one job directory to completion.
         *
         * The job directory must contain input.psd and job.json:
         *   {"source_name": "design.psd", "upscale": 100}
         *
         * @return array Final status array (also written to status.json).
         */
        public function run_job($jobDir)
        {
            $jobDir = rtrim($jobDir, '/');
            $status = array('state' => 'running', 'started_at' => gmdate('c'));
            $this->write_status($jobDir, $status);
            try {
                $result = $this->process($jobDir);
                $status = array_merge($status, array(
                    'state'       => 'done',
                    'finished_at' => gmdate('c'),
                    'zip'         => 'result.zip',
                    'count'       => $result['count'],
                    'layers'      => $result['layers'],
                    'log'         => $this->log,
                ));
            } catch (Exception $e) {
                $status = array_merge($status, array(
                    'state'       => 'failed',
                    'finished_at' => gmdate('c'),
                    'error'       => $e->getMessage(),
                    'log'         => $this->log,
                ));
            }
            $this->write_status($jobDir, $status);
            return $status;
        }

        private function write_status($jobDir, array $status)
        {
            file_put_contents($jobDir . '/status.json', json_encode($status, JSON_PRETTY_PRINT));
        }

        /**
         * @return array{count:int,layers:array}
         */
        private function process($jobDir)
        {
            $jobFile = $jobDir . '/job.json';
            $job = is_file($jobFile) ? json_decode(file_get_contents($jobFile), true) : array();
            if (!is_array($job)) {
                $job = array();
            }
            $upscale = isset($job['upscale']) ? (int) $job['upscale'] : 100;
            $upscale = max(10, min(800, $upscale));
            $psd = $jobDir . '/input.psd';
            if (!is_file($psd)) {
                throw new RuntimeException('input.psd is missing from the job directory.');
            }
            $cli = trim((string) $this->settings['cli_path']);
            if ($cli === '') {
                throw new RuntimeException('No photocraft-cli path is configured.');
            }
            $magick = $this->magick_bin();

            // The engine writes export directories inside the job dir, and on
            // some hosts it runs as a different user than the web server, so
            // the job dir must be writable by both.
            @chmod($jobDir, 0777);

            $cmd = array_merge(
                (array) $this->settings['cli_prefix'],
                array($cli, 'serve',
                    '--automation-read-root', $jobDir,
                    '--automation-write-root', $jobDir)
            );
            $this->log('Starting engine: ' . implode(' ', $cmd));
            $serve = new BNB_Prep_Serve($cmd, $jobDir . '/serve.log');
            try {
                $open = $serve->call('doc.open', array('path' => $psd));
                $this->log('Opened PSD: ' . (isset($open['width']) ? $open['width'] : '?') . 'x' .
                    (isset($open['height']) ? $open['height'] : '?') . ', ' .
                    (isset($open['layers']) ? $open['layers'] : '?') . ' layers total.');

                $tree = $serve->call('doc.inspect', array());
                $entries = $this->top_level_entries(is_array($tree) ? $tree : array());
                if (!$entries) {
                    throw new RuntimeException('The PSD has no layers to export.');
                }

                if ($upscale !== 100) {
                    $size = $serve->engine('image.imageSize', array('percent' => $upscale));
                    $this->log("Upscaled to {$upscale}%: " . json_encode($size));
                }

                // Pass 1: whatever is visible.
                $pass1 = $jobDir . '/pass1';
                $r1 = $serve->call('doc.exportLayers', array('dir' => $pass1));
                $files = $this->collect_exports($r1, 'pass1');
                $this->log('Pass 1 exported ' . count($files) . ' file(s).');

                // Pass 2: flip visibility so top-level hidden entries export.
                $hidden = array();
                foreach ($entries as $e) {
                    if (!$e['visible']) {
                        $hidden[] = $e;
                    }
                }
                if ($hidden) {
                    foreach ($entries as $e) {
                        $serve->engine('layer.setProps', array(
                            'layer'   => $e['id'],
                            'visible' => !$e['visible'],
                        ));
                    }
                    $pass2 = $jobDir . '/pass2';
                    $r2 = $serve->call('doc.exportLayers', array('dir' => $pass2));
                    $files2 = $this->collect_exports($r2, 'pass2');
                    $this->log('Pass 2 (hidden layers) exported ' . count($files2) . ' file(s).');
                    $files = array_merge($files, $files2);
                }
                $serve->call('doc.close', array());
            } finally {
                $serve->close();
            }

            if (!$files) {
                throw new RuntimeException('The engine exported no layer files.');
            }

            // Trim + assemble.
            $usedNames = array();
            $layers = array();
            $zipPath = $jobDir . '/result.zip';
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Could not create result.zip.');
            }
            $byName = array();
            foreach ($entries as $e) {
                $byName[$e['name']] = $e;
            }
            foreach ($files as $file) {
                $layerName = $this->layer_name_from_export($file['path']);
                $entry = isset($byName[$layerName]) ? $byName[$layerName] : null;
                $trimmed = $jobDir . '/trimmed_' . basename($file['path']);
                $flags = array();
                $canvas = $this->identify($magick, $file['path']);
                $final = $this->trim($magick, $file['path'], $trimmed, $flags);
                $size = $this->identify($magick, $final);
                $zipName = $this->unique_zip_name($layerName, $usedNames);
                if ($zipName !== $layerName . '.png') {
                    $flags[] = 'name_collision_suffixed';
                }
                $zip->addFile($final, $zipName);
                $layers[] = array(
                    'name'       => $layerName,
                    'zip_name'   => $zipName,
                    'kind'       => $entry ? $entry['kind'] : 'layer',
                    'was_hidden' => $entry ? !$entry['visible'] : ($file['pass'] === 'pass2'),
                    'pass'       => $file['pass'],
                    'children'   => $entry ? $entry['children'] : array(),
                    'canvas'     => $canvas,
                    'trimmed'    => $size,
                    'flags'      => $flags,
                );
                $this->log("Layer '{$layerName}': canvas {$canvas} -> trimmed {$size}" .
                    ($flags ? ' [' . implode(',', $flags) . ']' : ''));
            }

            $manifest = array(
                'plugin'      => 'bnb-prep',
                'version'     => '2026.10.31',
                'source_name' => isset($job['source_name']) ? $job['source_name'] : 'input.psd',
                'upscale'     => $upscale,
                'created_at'  => gmdate('c'),
                'layers'      => $layers,
            );
            file_put_contents($jobDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            $zip->addFile($jobDir . '/manifest.json', 'manifest.json');
            $readme = $this->readme_text($manifest);
            file_put_contents($jobDir . '/README.txt', $readme);
            $zip->addFile($jobDir . '/README.txt', 'README.txt');
            $zip->close();
            $this->log('Wrote result.zip with ' . count($layers) . ' layer PNG(s).');

            return array('count' => count($layers), 'layers' => $layers);
        }

        /**
         * Top-level layer stack from a doc.inspect payload.
         *
         * @return array<int,array{id:mixed,name:string,kind:string,visible:bool,children:array}>
         */
        private function top_level_entries(array $tree)
        {
            $out = array();
            $layers = isset($tree['layers']) && is_array($tree['layers']) ? $tree['layers'] : array();
            foreach ($layers as $l) {
                if (!is_array($l) || !isset($l['name'])) {
                    continue;
                }
                $children = array();
                $isGroup = isset($l['children']) && is_array($l['children']);
                if ($isGroup) {
                    foreach ($l['children'] as $c) {
                        if (is_array($c) && isset($c['name'])) {
                            $children[] = array(
                                'name'    => (string) $c['name'],
                                'visible' => !empty($c['visible']),
                            );
                        }
                    }
                }
                $out[] = array(
                    'id'       => isset($l['id']) ? $l['id'] : null,
                    'name'     => (string) $l['name'],
                    'kind'     => $isGroup ? 'group' : (isset($l['kind']) ? strtolower((string) $l['kind']) : 'layer'),
                    'visible'  => !empty($l['visible']),
                    'children' => $children,
                );
            }
            return $out;
        }

        /**
         * Normalise a doc.exportLayers result into a list of files.
         *
         * @param mixed $result
         * @return array<int,array{path:string,pass:string}>
         */
        private function collect_exports($result, $pass)
        {
            $files = array();
            if (is_array($result) && isset($result['files']) && is_array($result['files'])) {
                foreach ($result['files'] as $f) {
                    if (is_string($f) && is_file($f)) {
                        $files[] = array('path' => $f, 'pass' => $pass);
                    }
                }
                if (isset($result['skipped']) && is_array($result['skipped'])) {
                    foreach ($result['skipped'] as $s) {
                        $this->log('Engine skipped a layer: ' . json_encode($s));
                    }
                }
            }
            return $files;
        }

        /**
         * Export files are named "<doc>_%04d_<Layer Name>.png".
         */
        private function layer_name_from_export($path)
        {
            $stem = pathinfo($path, PATHINFO_FILENAME);
            if (preg_match('/_\d{4}_(.+)$/', $stem, $m)) {
                return $m[1];
            }
            return $stem;
        }

        private function unique_zip_name($layerName, array &$used)
        {
            $base = preg_replace('/[\/\\\\:*?"<>|' . "\x00-\x1F" . ']/', '_', $layerName);
            $base = trim($base, " .");
            if ($base === '') {
                $base = 'Layer';
            }
            $name = $base . '.png';
            $i = 2;
            while (isset($used[$name])) {
                $name = $base . ' (' . $i . ').png';
                $i++;
            }
            $used[$name] = true;
            return $name;
        }

        private function magick_bin()
        {
            $bin = trim((string) $this->settings['magick_bin']);
            if ($bin !== '') {
                return $bin;
            }
            foreach (array('magick', 'convert') as $candidate) {
                $which = trim((string) @shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
                if ($which !== '') {
                    return $candidate;
                }
            }
            throw new RuntimeException('ImageMagick was not found (looked for magick, then convert).');
        }

        /**
         * @return string "WIDTHxHEIGHT" or "?"
         */
        private function identify($magick, $file)
        {
            // IM7: `magick identify ...`. IM6: standalone `identify` binary.
            $base = basename($magick);
            if ($base === 'magick') {
                $cmd = escapeshellarg($magick) . ' identify -format %wx%h ' . escapeshellarg($file) . ' 2>/dev/null';
            } else {
                $cmd = 'identify -format %wx%h ' . escapeshellarg($file) . ' 2>/dev/null';
            }
            $out = trim((string) @shell_exec($cmd));
            return $out !== '' ? $out : '?';
        }

        /**
         * Trim one PNG. Returns the path to use (trimmed file, or the
         * original when trimming would destroy the layer).
         */
        private function trim($magick, $src, $dest, array &$flags)
        {
            $tool = $magick === 'convert' ? 'convert' : $magick;
            $cmd = escapeshellarg($tool) . ' ' . escapeshellarg($src) .
                ' -trim +repage ' . escapeshellarg($dest) . ' 2>/dev/null';
            @shell_exec($cmd);
            if (!is_file($dest)) {
                $flags[] = 'trim_failed_kept_canvas';
                return $src;
            }
            $size = $this->identify($magick, $dest);
            if (preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
                if ((int) $m[1] < 2 || (int) $m[2] < 2) {
                    // Solid colour or fully transparent layer: trimming to a
                    // sliver would lose the layer; keep the full canvas.
                    $flags[] = 'trim_skipped_solid_or_empty';
                    unlink($dest);
                    return $src;
                }
            }
            return $dest;
        }

        private function readme_text(array $manifest)
        {
            $lines = array();
            $lines[] = 'BNB Prep — layer export';
            $lines[] = 'Source: ' . $manifest['source_name'];
            $lines[] = 'Upscale: ' . $manifest['upscale'] . '%';
            $lines[] = 'Exported: ' . $manifest['created_at'];
            $lines[] = '';
            $lines[] = 'Files (one PNG per top-level layer; groups are flattened';
            $lines[] = 'to a single PNG named for the group):';
            foreach ($manifest['layers'] as $l) {
                $line = ' - ' . $l['zip_name'] . '  (canvas ' . $l['canvas'] . ', trimmed ' . $l['trimmed'];
                if ($l['was_hidden']) {
                    $line .= ', was hidden in the PSD';
                }
                if ($l['kind'] === 'group') {
                    $names = array();
                    foreach ($l['children'] as $c) {
                        $names[] = $c['name'] . ($c['visible'] ? '' : ' (hidden, not rendered)');
                    }
                    $line .= ', group of: ' . implode(', ', $names);
                }
                $line .= ')';
                $lines[] = $line;
            }
            $lines[] = '';
            $lines[] = 'See manifest.json for the machine-readable copy of this list.';
            return implode("\n", $lines) . "\n";
        }
    }
}
