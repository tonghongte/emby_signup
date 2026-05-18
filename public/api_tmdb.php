<?php
session_start();

if (!isset($_SESSION['emby_user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$config_path = __DIR__ . '/../config/config.php';
if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration not found']);
    exit;
}

$config = require $config_path;
$tmdb_api_key = $config['tmdb']['api_key'] ?? '';
$tmdb_language = $config['tmdb']['language'] ?? 'zh-CN';

if (empty($tmdb_api_key)) {
    http_response_code(500);
    echo json_encode(['error' => 'TMDB API Key is not configured. Please contact the administrator.']);
    exit;
}

$query = $_GET['q'] ?? '';
$page = (int)($_GET['page'] ?? 1);

if (empty($query)) {
    echo json_encode(['results' => [], 'total_pages' => 0]);
    exit;
}

$url = "https://api.themoviedb.org/3/search/multi?api_key={$tmdb_api_key}&language={$tmdb_language}&query=" . urlencode($query) . "&page={$page}";

$options = [
    'http' => [
        'method' => 'GET',
        'header' => "Accept: application/json\r\n",
        'ignore_errors' => true
    ]
];

$tmdb_proxy = trim($config['tmdb']['proxy'] ?? '');
if (!empty($tmdb_proxy)) {
    // PHP's stream context proxy expects tcp:// format
    $tmdb_proxy = str_replace(['http://', 'https://'], 'tcp://', $tmdb_proxy);
    $options['http']['proxy'] = $tmdb_proxy;
    $options['http']['request_fulluri'] = true;
}

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

if ($response === FALSE) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to connect to TMDB API']);
    exit;
}

header('Content-Type: application/json');
echo $response;
