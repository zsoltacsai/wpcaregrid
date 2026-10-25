<?php

namespace WPCareGrid\Connector\Backups;

use WPCareGrid\Connector\Core\Lock;

/**
 * Restores a database dump made by DatabaseDumper. This is not a general
 * SQL runner: every line must be one of the statement forms the dumper
 * writes, or the whole restore is refused before anything live changes.
 *
 *   SET NAMES x; SET FOREIGN_KEY_CHECKS = 0|1; SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
 *   DROP TABLE IF EXISTS `prefix_…`;
 *   CREATE TABLE `prefix_…` (…) options;      (no SELECT, file, remote-engine or cross-database parts)
 *   INSERT INTO `prefix_…` VALUES (…),(…);    (values: NULL or quoted strings only)
 *
 * Tables must carry the site's table prefix. Everything is loaded into
 * temporary tables first; the live site keeps running. swap() then renames
 * all of them into place in one atomic RENAME TABLE, and puts the
 * connector's own state (credential, job records, backup index) back, so
 * a restore never disconnects the site or makes it forget jobs.
 */
final class SqlImporter
{
    private const FORBIDDEN = '/\b(SELECT|UNION|LOAD_FILE|SLEEP|BENCHMARK|OUTFILE|DUMPFILE|CONNECTION|FEDERATED|INSERT_METHOD)\b|DATA\s+DIRECTORY|INDEX\s+DIRECTORY|`\s*\.\s*`/i';

    private const VALUE = "(?:NULL|'(?:[^'\\\\]++|\\\\.)*+')";

    private string $tmp_prefix;

    private string $old_prefix;

    /** @var array<string, string> original table => temporary table */
    private array $tables = [];

    public int $statements = 0;

    public function __construct(
        private readonly string $restore_uuid,
        private readonly string $site_prefix,
        private readonly ?RestoreFaultHooks $fault_hooks = null,
    ) {
        $tag = substr(md5($restore_uuid), 0, 6);
        // Retries for the same restore UUID must never share temporary table
        // names: a stale worker may still be unwinding when its successor stages.
        $attempt = bin2hex(random_bytes(6));
        $this->tmp_prefix = 'cgr'.$tag.$attempt.'_';
        $this->old_prefix = 'cgo'.$tag.$attempt.'_';
    }

    /** Load the dump into temporary tables. */
    public function import(string $path): void
    {
        global $wpdb;

        $handle = @gzopen($path, 'rb');
        if (! $handle) {
            throw new BackupFailure('RESTORE_DATABASE_FAILED', 'The database dump cannot be opened.');
        }

        $leaseLost = false;
        try {
            while (($line = $this->line($handle)) !== null) {
                if ($line === '' || str_starts_with($line, '-- ')) {
                    continue;
                }

                // Do not start another staged write after losing the lease.
                Lock::refreshOrFail();
                $sql = $this->translate($line);
                // Already validated; wpdb's charset re-check would mangle binary values.
                $wpdb->check_current_query = false;
                $wpdb->query($sql);
                if ($wpdb->last_error !== '') {
                    throw new BackupFailure('RESTORE_DATABASE_FAILED', 'A statement of the database dump failed.', ['statement' => $this->statements + 1]);
                }

                $this->statements++;
                $this->fault_hooks?->reach('database_statement_imported', $this->restore_uuid);
                Lock::refreshOrFail();
            }
        } catch (LeaseLost $e) {
            // A stale worker must not clean up or otherwise mutate database state.
            $leaseLost = true;
            throw $e;
        } catch (\Throwable $e) {
            try {
                $this->discard();
            } catch (LeaseLost $lost) {
                $leaseLost = true;
                throw $lost;
            }
            throw $e;
        } finally {
            gzclose($handle);
            if (! $leaseLost) {
                // A normal failure may become a lease loss while discard() is
                // unwinding. Recheck before the final SQL statement too.
                Lock::refreshOrFail();
                $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
            }
        }

        if ($this->tables === []) {
            throw new BackupFailure('RESTORE_SQL_REJECTED', 'The database dump contains no tables.');
        }
    }

    /** A validated statement, rewritten to the temporary table names. */
    public function translate(string $line): string
    {
        if (preg_match("/^SET (NAMES [a-z0-9_]+|FOREIGN_KEY_CHECKS = [01]|SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO');$/D", $line)) {
            return $line;
        }

        if (preg_match('/^DROP TABLE IF EXISTS `([A-Za-z0-9_$]+)`;$/D', $line, $m)) {
            return 'DROP TABLE IF EXISTS `'.$this->temp($m[1]).'`';
        }

        if (preg_match('/^CREATE TABLE `([A-Za-z0-9_$]+)` \((.*)\)([^;()]*);$/Ds', $line, $m)) {
            if (preg_match(self::FORBIDDEN, $m[2].$m[3]) || ! preg_match('/ENGINE=(InnoDB|MyISAM|Aria)\b/i', $m[3])) {
                throw new BackupFailure('RESTORE_SQL_REJECTED', 'The database dump contains a table definition that is not allowed.');
            }

            // Foreign keys point at the temporary tables too; they follow the rename.
            $body = preg_replace_callback('/REFERENCES `([A-Za-z0-9_$]+)`/', fn ($r) => 'REFERENCES `'.$this->temp($r[1]).'`', $m[2]);

            return 'CREATE TABLE `'.$this->temp($m[1]).'` ('.$body.')'.$m[3];
        }

        if (preg_match('/^INSERT INTO `([A-Za-z0-9_$]+)` VALUES (.*);$/Ds', $line, $m) && $this->literal_values($m[2])) {
            return 'INSERT INTO `'.$this->temp($m[1]).'` VALUES '.$m[2];
        }

        throw new BackupFailure('RESTORE_SQL_REJECTED', 'The database dump contains a statement that is not allowed.', ['statement' => $this->statements + 1]);
    }

    /** Put the restored tables live in one atomic rename, then restore the connector's own state. */
    public function swap(?callable $beforeSwap = null): void
    {
        global $wpdb;

        $options = $this->site_prefix.'options';

        // Persist the caller's destructive-boundary marker immediately before
        // snapshotting Connector state and installing the prepared tables. The
        // snapshot therefore carries the marker across RENAME TABLE, including
        // the crash window between MySQL's atomic swap and subsequent PHP code.
        if ($beforeSwap !== null) {
            $beforeSwap();
        }
        $this->fault_hooks?->reach('database_swap_ready', $this->restore_uuid);
        Lock::refreshOrFail();

        $renames = [];
        foreach ($this->tables as $table => $temp) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
                $renames[] = "`{$table}` TO `".$this->old($table).'`';
            }
            $renames[] = "`{$temp}` TO `{$table}`";
        }

        $options_temp = $this->tables[$options] ?? null;
        if (! is_string($options_temp)) {
            throw new BackupFailure('RESTORE_SQL_REJECTED', 'The database dump does not contain the WordPress options table.');
        }

        // The restored snapshot may contain an old poll lease. Carry the
        // current owner into the prepared table so the atomic rename never
        // exposes a missing or expired lease to another poller.
        Lock::preserveInOptionsTable($options_temp);
        $connector_state = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value, autoload FROM `{$options}` WHERE option_name LIKE %s AND option_name <> %s",
            $wpdb->esc_like('wp_caregrid_').'%',
            Lock::optionName()
        ), ARRAY_A);
        Lock::refreshOrFail();
        $wpdb->query('RENAME TABLE '.implode(', ', $renames));
        if ($wpdb->last_error !== '') {
            // Keep the failure injection seam before cleanup so tests can
            // replace the lease in the exact rename-error/cleanup window.
            $this->fault_hooks?->reach('database_swap_failed', $this->restore_uuid);
            $this->discard();
            throw new BackupFailure('RESTORE_DATABASE_FAILED', 'The restored tables could not be put in place.');
        }

        Lock::refreshOrFail();
        foreach (array_keys($this->tables) as $table) {
            Lock::refreshOrFail();
            $wpdb->query('DROP TABLE IF EXISTS `'.$this->old($table).'`');
        }

        Lock::refreshOrFail();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$options}` WHERE option_name LIKE %s AND option_name <> %s",
            $wpdb->esc_like('wp_caregrid_').'%',
            Lock::optionName()
        ));
        foreach ((array) $connector_state as $row) {
            Lock::refreshOrFail();
            $wpdb->insert($options, $row);
        }

        wp_cache_flush();
    }

    /**
     * Remove only this attempt's temporary tables while lease ownership is
     * still valid. Attempt-scoped names prevent a check-to-DROP race from
     * deleting a successor's staged tables.
     */
    public function discard(): void
    {
        global $wpdb;

        foreach ($this->tables as $temp) {
            Lock::refreshOrFail();
            $wpdb->query("DROP TABLE IF EXISTS `{$temp}`");
        }
    }

    private function temp(string $table): string
    {
        if (! str_starts_with($table, $this->site_prefix)) {
            throw new BackupFailure('RESTORE_SQL_REJECTED', 'The database dump refers to a table outside this site.');
        }

        $temp = $this->tmp_prefix.substr($table, strlen($this->site_prefix));
        if (strlen($temp) > 64 || strlen($this->old($table)) > 64) {
            throw new BackupFailure('RESTORE_SQL_REJECTED', 'A table name is too long to restore safely.');
        }

        return $this->tables[$table] = $temp;
    }

    private function old(string $table): string
    {
        return $this->old_prefix.substr($table, strlen($this->site_prefix));
    }

    /** "(…),(…)" where every value is NULL or a quoted string with backslash escapes. */
    private function literal_values(string $values): bool
    {
        $tuple = '\G\('.self::VALUE.'(?:,'.self::VALUE.')*+\)';
        $offset = 0;
        $length = strlen($values);

        while ($offset < $length) {
            if (preg_match('/'.$tuple.'/s', $values, $m, 0, $offset) !== 1) {
                return false;
            }
            $offset += strlen($m[0]);

            if ($offset < $length) {
                if ($values[$offset] !== ',') {
                    return false;
                }
                $offset++;
                if ($offset === $length) {
                    return false;
                }
            }
        }

        return $length > 0;
    }

    /** One line of any length, without its newline; null at the end. */
    private function line($handle): ?string
    {
        $line = '';
        while (! gzeof($handle)) {
            $piece = gzgets($handle, 1048576);
            if ($piece === false) {
                break;
            }
            $line .= $piece;
            if (str_ends_with($line, "\n")) {
                return substr($line, 0, -1);
            }
        }

        return $line === '' ? null : $line;
    }
}
