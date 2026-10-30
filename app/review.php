<?php
/**
 * review.php — the old name of the data page. Forwards to data.php with the
 * same query string, so links shared before the rename keep working.
 *
 * @author Marc Becker
 */
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: data.php' . ($query !== '' ? '?' . $query : ''), true, 301);
exit;
