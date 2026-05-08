<?php

$host = getenv('MANTICORE_HOST') ?: '127.0.0.1';
$port = getenv('MANTICORE_PORT') ?: '9308';
$base = "http://{$host}:{$port}";

function manticore_sql(string $sql, string $base): void
{
    $ch = curl_init("{$base}/sql");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'query=' . urlencode($sql),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FAILONERROR    => false,
    ]);

    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0) {
        fwrite(STDERR, "CURL error ({$errno}) for: {$sql}\n");
        exit(1);
    }

    $parsed = json_decode($raw ?: '', true);

    if (isset($parsed['error']) && !str_contains(strtolower($sql), 'drop table if exists')) {
        fwrite(STDERR, "ERROR executing SQL:\n  {$sql}\nResponse:\n  {$raw}\n");
        exit(1);
    }
}

echo "Dropping existing table...\n";
manticore_sql('DROP TABLE IF EXISTS laravel_manticore_test', $base);

echo "Creating table...\n";
manticore_sql(
    'CREATE TABLE laravel_manticore_test '
    . '(id bigint, entity_id integer, title text, status string, category string, score integer, created_at timestamp)',
    $base
);

echo "Inserting test data...\n";

$cols = 'id, entity_id, title, status, category, score, created_at';

$rows = [
    [1,  1, 'Test document one', 'active',   'tech',    100, 1700000000],
    [2,  1, 'Test document two', 'active',   'tech',    90,  1700000001],
    [3,  2, 'Test three',        'active',   'science', 80,  1700000002],
    [4,  2, 'Test four',         'pending',  'science', 70,  1700000003],
    [5,  3, 'Test five',         'active',   'tech',    60,  1700000004],
    [6,  3, 'Test six',          'inactive', 'health',  50,  1700000005],
    [7,  4, 'Test seven',        'active',   'health',  40,  1700000006],
    [8,  4, 'Test eight',        'active',   'tech',    30,  1700000007],
    [9,  5, 'Test nine',         'pending',  'science', 20,  1700000008],
    [10, 5, 'Test ten',          'active',   'tech',    10,  1700000009],
];

foreach ($rows as [$id, $entity_id, $title, $status, $category, $score, $created_at]) {
    manticore_sql(
        "INSERT INTO laravel_manticore_test ({$cols}) "
        . "VALUES ({$id}, {$entity_id}, '{$title}', '{$status}', '{$category}', {$score}, {$created_at})",
        $base
    );
}

echo "Verifying row count...\n";
manticore_sql('SELECT COUNT(*) as cnt FROM laravel_manticore_test', $base);

echo "Test data seeded successfully.\n";
