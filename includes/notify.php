<?php
/*
 * includes/notify.php
 * notify() : adds an in-app notification (the bell) for a user.
 * (send_sms() already exists in includes/sms.php, so it is not repeated here.)
 *
 * notify() cannot break the page: if the table or a column does not match, the problem is written to the
 * PHP error log and the request carries on. Column names are read from the database, so this works with
 * your notifications table as it is.
 */

if (!defined('APP_NAME')) define('APP_NAME', 'ShambaLink');

if (!function_exists('insert_flexible')) {
    /**
     * Insert one row, matching each value to the first existing column from a list of likely names.
     * $parts = [ [['message','body'], 'text...'], [['user_id'], 5], ... ]
     */
    function insert_flexible(string $table, array $parts): bool
    {
        static $columns = [];
        if (!isset($columns[$table])) {
            $columns[$table] = array_column(fetch_all("SHOW COLUMNS FROM `$table`"), 'Field');
        }
        $names = [];
        $values = [];
        foreach ($parts as [$candidates, $value]) {
            foreach ($candidates as $c) {
                if (in_array($c, $columns[$table], true)) { $names[] = "`$c`"; $values[] = $value; break; }
            }
        }
        if (!$names) return false;
        db()->prepare("INSERT INTO `$table` (" . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')')
            ->execute($values);
        return true;
    }
}

if (!function_exists('notify')) {
    function notify(int $userId, string $message, string $link = ''): void
    {
        try {
            insert_flexible('notifications', [
                [['user_id', 'recipient_id'], $userId],
                [['message', 'body', 'text', 'content'], $message],
                [['link', 'url', 'href'], $link],
            ]);
        } catch (Throwable $e) {
            error_log('notify failed: ' . $e->getMessage());
        }
    }
}