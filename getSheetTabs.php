<?php
// getSheetTabs.php

require 'vendor/autoload.php';

if (isset($_GET['spreadsheet_id'])) {
    $spreadsheetId = $_GET['spreadsheet_id'];

    $client = new Google_Client();
    $client->setApplicationName('RUC Bulk Mailer');
    $client->setScopes(['https://www.googleapis.com/auth/spreadsheets.readonly']);
    
    if (!file_exists(__DIR__ . '/credentials.json')) {
        http_response_code(500);
        echo json_encode(['error' => 'credentials.json introuvable']);
        exit;
    }
    $client->setAuthConfig(__DIR__ . '/credentials.json');

    $service = new Google_Service_Sheets($client);
    try {
        $spreadsheet = $service->spreadsheets->get($spreadsheetId);
        $sheets = $spreadsheet->getSheets();
        $tabs = [];
        foreach ($sheets as $sheet) {
            $tabs[] = $sheet->getProperties()->getTitle();
        }
        header('Content-Type: application/json');
        echo json_encode($tabs);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'spreadsheet_id non fourni']);
    exit;
}
?>
