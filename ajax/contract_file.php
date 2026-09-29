<?php

/**
 * Project Flow - contract file viewer.
 * Streams a file attached to a contract linked to the project, INLINE (no download prompt), so
 * the project screen can show it in a popup. Access: the user must see the project, the contract
 * must be linked to it and the document attached to that contract.
 */

use GlpiPlugin\Projectflow\Service\ContractService;

Session::checkLoginUser();

$projectId = (int) ($_GET['project_id'] ?? 0);
$documentId = (int) ($_GET['document_id'] ?? 0);

$doc = (new ContractService())->findViewableDocument($projectId, $documentId);
$path = $doc ? GLPI_DOC_DIR . '/' . ltrim((string) ($doc->fields['filepath'] ?? ''), '/') : '';
if ($doc === null || (string) ($doc->fields['filepath'] ?? '') === '' || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Arquivo não encontrado ou sem acesso.';
    exit;
}
// The stored path must stay inside GLPI's document folder.
$real = realpath($path);
$base = realpath(GLPI_DOC_DIR);
if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit;
}

$filename = (string) ($doc->fields['filename'] ?? 'arquivo');
$mime = strtolower(trim((string) ($doc->fields['mime'] ?? '')));
if ($mime === '') {
    $mime = function_exists('mime_content_type') ? (string) @mime_content_type($real) : 'application/octet-stream';
}
// Never let the browser run active content from an uploaded file: HTML, SVG, XML and scripts
// are served as plain text (images, including SVG, are shown through <img>, which runs no script).
$activeTypes = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml', 'application/javascript', 'text/javascript'];
$asImageTag = isset($_GET['img']) && str_starts_with($mime, 'image/');
if (in_array($mime, $activeTypes, true) && !$asImageTag) {
    $mime = 'text/plain';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'arquivo';
header('Content-Type: ' . $mime . (str_starts_with($mime, 'text/') ? '; charset=UTF-8' : ''));
header('Content-Length: ' . (string) filesize($real));
header("Content-Disposition: inline; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header('X-Frame-Options: SAMEORIGIN');
if ($mime !== 'application/pdf') {
    // PDF viewers do not work inside a sandbox; everything else is inert content.
    header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\' data:; style-src \'unsafe-inline\'');
}
readfile($real);
exit;
