<?php
if (! defined('ABSPATH')) exit;

class Nublyx_Hub_Backup
{

    private $backup_dir;
    private $max_backups = 3;

    public function __construct()
    {
        $this->backup_dir = NSU_BACKUP_DIR;
    }

    public function run()
    {
        $timestamp   = date('Y-m-d_H-i-s');
        $backup_name = 'backup-' . sanitize_title(get_bloginfo('name')) . '-' . $timestamp;
        $backup_path = $this->backup_dir . '/' . $backup_name;

        // Rotación PREVIA: si ya hay 3 backups, borra los más viejos para dejar lugar al nuevo.
        $this->rotate_backups($this->max_backups - 1);

        if (! wp_mkdir_p($backup_path)) {
            return new WP_Error('nublyx_backup_dir', 'No se pudo crear el directorio de backup.');
        }

        $db_result = $this->backup_database($backup_path, $timestamp);
        if (is_wp_error($db_result)) return $db_result;

        $files_result = $this->backup_files($backup_path, $timestamp);
        if (is_wp_error($files_result)) return $files_result;

        return [
            'status'      => 'ok',
            'backup_name' => $backup_name,
            'db_file'     => basename($db_result),
            'files_zip'   => basename($files_result),
            'created_at'  => $timestamp,
            'size_mb'     => $this->get_dir_size_mb($backup_path),
        ];
    }

    private function backup_database($backup_path, $timestamp)
    {
        global $wpdb;

        $tables = $wpdb->get_col('SHOW TABLES');
        $sql    = "-- Nublyx Hub Backup\n-- Fecha: {$timestamp}\n-- Sitio: " . get_bloginfo('name') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            $sql   .= "\n\nDROP TABLE IF EXISTS `{$table}`;\n";
            $sql   .= $create[1] . ";\n\n";

            $offset = 0;
            $limit  = 100;
            do {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $limit, $offset),
                    ARRAY_A
                );
                if (empty($rows)) break;

                $columns     = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $values_list = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($val) use ($wpdb) {
                        return is_null($val) ? 'NULL' : "'" . $wpdb->_real_escape($val) . "'";
                    }, array_values($row));
                    $values_list[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql   .= "INSERT INTO `{$table}` ({$columns}) VALUES\n" . implode(",\n", $values_list) . ";\n";
                $offset += $limit;
            } while (count($rows) === $limit);
        }

        $sql    .= "\n\nSET FOREIGN_KEY_CHECKS=1;\n";
        $db_file = $backup_path . '/database-' . $timestamp . '.sql';

        if (file_put_contents($db_file, $sql) === false) {
            return new WP_Error('nublyx_db_write', 'No se pudo escribir el archivo SQL.');
        }

        return $db_file;
    }

    private function backup_files($backup_path, $timestamp)
    {
        if (! class_exists('ZipArchive')) {
            return new WP_Error('nublyx_no_zip', 'ZipArchive no está disponible en este servidor.');
        }

        $zip      = new ZipArchive();
        $zip_file = $backup_path . '/files-' . $timestamp . '.zip';

        if ($zip->open($zip_file, ZipArchive::CREATE) !== true) {
            return new WP_Error('nublyx_zip_open', 'No se pudo crear el archivo ZIP.');
        }

        $source  = WP_CONTENT_DIR;
        $exclude = [
            realpath($this->backup_dir),
            realpath($source . '/cache'),
            realpath($source . '/uploads/cache'),
        ];

        $this->add_dir_to_zip($zip, $source, $source, $exclude);
        $zip->close();

        return $zip_file;
    }

    private function add_dir_to_zip(ZipArchive $zip, $dir, $base, $exclude = [])
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $real_path = realpath($file->getPathname());

            foreach ($exclude as $ex) {
                if ($ex && strpos($real_path, $ex) === 0) continue 2;
            }

            $relative = substr($real_path, strlen($base) + 1);

            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
            } elseif ($file->getSize() < 100 * 1024 * 1024) {
                $zip->addFile($real_path, $relative);
            }
        }
    }

    /**
     * Conserva solo los $keep backups más recientes (por defecto el máximo permitido).
     * El orden se toma del nombre (lleva timestamp), que es más confiable que el mtime.
     */
    public function rotate_backups($keep = null)
    {
        $keep    = $keep === null ? $this->max_backups : max(0, (int) $keep);
        $backups = $this->list_backups();
        if (count($backups) <= $keep) return;

        usort($backups, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $to_delete = array_slice($backups, 0, count($backups) - $keep);
        foreach ($to_delete as $backup) {
            $this->delete_backup_dir($this->backup_dir . '/' . $backup['name']);
        }
    }

    public function list_backups()
    {
        $backups = [];
        if (! is_dir($this->backup_dir)) return $backups;

        foreach (scandir($this->backup_dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $this->backup_dir . '/' . $item;
            if (! is_dir($path)) continue;

            $files = [];
            foreach (scandir($path) as $file) {
                if ($file === '.' || $file === '..') continue;
                $file_path = $path . '/' . $file;
                $files[]   = [
                    'name'    => $file,
                    'size_mb' => round(filesize($file_path) / 1024 / 1024, 2),
                ];
            }

            $backups[] = [
                'name'       => $item,
                'created_at' => filemtime($path),
                'size_mb'    => $this->get_dir_size_mb($path),
                'files'      => $files,
            ];
        }

        return $backups;
    }

    public function get_file_base64($backup_name, $filename)
    {
        $backup_name = basename($backup_name);
        $filename    = basename($filename);
        $path        = $this->backup_dir . '/' . $backup_name . '/' . $filename;

        if (! file_exists($path)) {
            return new WP_Error('nublyx_file_not_found', 'Archivo no encontrado.', ['status' => 500]);
        }

        $real_path = realpath($path);
        $real_dir  = realpath($this->backup_dir);

        if ($real_path === false || $real_dir === false || strpos($real_path, $real_dir) !== 0) {
            return new WP_Error('nublyx_file_invalid', 'Ruta de archivo inválida.', ['status' => 403]);
        }

        return base64_encode(file_get_contents($path));
    }

    private function delete_backup_dir($path)
    {
        if (! is_dir($path)) return;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($path);
    }

    private function get_dir_size_mb($path)
    {
        $size = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return round($size / 1024 / 1024, 2);
    }
}