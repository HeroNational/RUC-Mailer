<?php
// index.php

// Inclusion de l'autoload de Composer et du fichier de templates
require 'vendor/autoload.php';
require './templates.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/* -----------------------------------------------------------------------
   CLASSES POUR LA GESTION DES DONNÉES, EMAILS ET GOOGLE SHEETS
------------------------------------------------------------------------ */

// Classe pour parser un fichier CSV
class CsvParser {
    private $file;
    private $headers = [];
    private $rows = [];
    private $delimiter;
    private $parsed = false;

    public function __construct(string $file, string $delimiter = ',') {
        $this->file = $file;
        $this->delimiter = $delimiter;
    }

    public function parse(): array {
        if ($this->parsed) return $this->rows;
        if (($handle = fopen($this->file, 'r')) !== false) {
            $firstLine = fgetcsv($handle, 1000, $this->delimiter);
            if ($firstLine !== false) {
                if (isset($firstLine[0])) {
                    $firstLine[0] = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine[0]);
                }
                $this->headers = array_map('trim', $firstLine);
            }
            while (($data = fgetcsv($handle, 1000, $this->delimiter)) !== false) {
                if (count($data) === 1 && $data[0] === null) continue;
                $this->rows[] = $data;
            }
            fclose($handle);
            $this->parsed = true;
        }
        return $this->rows;
    }

    public function getHeaders(): array {
        if (!$this->parsed) $this->parse();
        return $this->headers;
    }
}

// Classe pour parser un Google Sheet (un onglet ou une plage)
class GoogleSheetParser {
    private $spreadsheetId;
    private $range;
    private $client;
    private $service;
    private $headers = [];
    private $rows = [];
    private $parsed = false;
    private $debugMessages = [];

    public function __construct(string $spreadsheetId, string $range = 'A1:Z1000') {
        $this->spreadsheetId = $spreadsheetId;
        $this->range = $range;
        try {
            $this->client = new Google_Client();
            $this->client->setApplicationName('RUC Bulk Mailer');
            $this->client->setScopes(['https://www.googleapis.com/auth/spreadsheets.readonly']);
            if (!file_exists(__DIR__ . '/credentials.json')) {
                throw new Exception('credentials.json introuvable dans : ' . __DIR__);
            }
            $credentialsContent = file_get_contents(__DIR__ . '/credentials.json');
            $credentials = json_decode($credentialsContent, true);
            if (!isset($credentials['type']) || $credentials['type'] !== 'service_account') {
                throw new Exception('Le fichier credentials.json n\'est pas un compte de service valide');
            }
            $this->client->setAuthConfig(__DIR__ . '/credentials.json');
            $this->service = new Google_Service_Sheets($this->client);
            $this->debugMessages[] = "Configuration initiale réussie";
            $this->debugMessages[] = "Spreadsheet ID: " . $this->spreadsheetId;
            $this->debugMessages[] = "Range: " . $this->range;
            $this->debugMessages[] = "Client email: " . $credentials['client_email'];
        } catch (Exception $e) {
            die('Erreur d\'initialisation: ' . $e->getMessage());
        }
    }

    public function parse(): array {
        if ($this->parsed) return $this->rows;
        try {
            $this->debugMessages[] = "Tentative de lecture du Google Sheet...";
            try {
                $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId);
                $this->debugMessages[] = "Spreadsheet trouvé : " . $spreadsheet->getProperties()->getTitle();
            } catch (Exception $e) {
                throw new Exception("Impossible d'accéder au spreadsheet. Vérifiez l'ID et les permissions. Erreur: " . $e->getMessage());
            }
            $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $this->range);
            $values = $response->getValues();
            if (empty($values)) {
                throw new Exception('Aucune donnée trouvée dans la plage spécifiée : ' . $this->range);
            }
            $this->debugMessages[] = "Nombre de lignes trouvées : " . count($values);
            $this->headers = array_map('trim', array_shift($values));
            $this->debugMessages[] = "En-têtes trouvés : " . implode(", ", $this->headers);
            $this->rows = $values;
            $this->parsed = true;
            return $this->rows;
        } catch (Google_Service_Exception $e) {
            $error = json_decode($e->getMessage());
            die('Erreur Google Sheets API : ' . ($error->error->message ?? $e->getMessage()));
        } catch (Exception $e) {
            die('Erreur de lecture : ' . $e->getMessage());
        }
    }

    public function getHeaders(): array {
        if (!$this->parsed) $this->parse();
        return $this->headers;
    }

    public function getDebugHtml(): string {
        if (empty($this->debugMessages)) return "";
        $html = '<div class="card glass-card mb-3 shadow-sm">';
        $html .= '<div class="card-header bg-info text-white">Debug - Propriétés de Google Sheet</div>';
        $html .= '<div class="card-body p-0">';
        $html .= '<table class="table table-bordered table-hover mb-0">';
        $html .= '<thead class="table-light"><tr><th style="width:5%;">#</th><th>Message</th></tr></thead>';
        $html .= '<tbody>';
        foreach ($this->debugMessages as $index => $message) {
            $html .= '<tr>';
            $html .= '<th scope="row">' . ($index + 1) . '</th>';
            $html .= '<td>' . htmlspecialchars($message) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div></div>';
        return $html;
    }
}

// Classe pour lister les Google Sheets via l'API Drive
class GoogleSheetsLister {
    private $client;
    private $service;
    public function __construct() {
        $this->client = new Google_Client();
        $this->client->setApplicationName('RUC Bulk Mailer');
        $this->client->setScopes(['https://www.googleapis.com/auth/drive.metadata.readonly']);
        if (!file_exists(__DIR__ . '/credentials.json')) {
            throw new Exception('credentials.json introuvable dans : ' . __DIR__);
        }
        $this->client->setAuthConfig(__DIR__ . '/credentials.json');
        $this->service = new Google_Service_Drive($this->client);
    }
    public function listSheets(): array {
        $query = "mimeType = 'application/vnd.google-apps.spreadsheet'";
        $optParams = ['q' => $query, 'fields' => 'files(id, name)'];
        $results = $this->service->files->listFiles($optParams);
        return $results->getFiles();
    }
}

// Classe pour envoyer un e-mail via PHPMailer
class EmailSender {
    private $smtpConfig;
    private $emailFrom;
    private $senderName;
    private $subject;
    private $replyToAddresses = [];
    public function __construct(array $smtpConfig, string $emailFrom, string $senderName, string $subject, string $replyTo) {
        $this->smtpConfig = $smtpConfig;
        $this->emailFrom  = $emailFrom;
        $this->senderName = $senderName;
        $this->subject    = $subject;
        $this->replyToAddresses = preg_split('/[\s,;]+/', $replyTo);
    }
    public function sendEmail(string $toEmail, string $toName, string $message) {
        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $this->smtpConfig['host'];
            $mail->Port = $this->smtpConfig['port'];
            if (!empty($this->smtpConfig['encryption'])) {
                $mail->SMTPSecure = $this->smtpConfig['encryption'];
            }
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpConfig['user'];
            $mail->Password = $this->smtpConfig['password'];
            $mail->setFrom($this->emailFrom, $this->senderName);
            $mail->addAddress($toEmail, $toName);
            foreach ($this->replyToAddresses as $reply) {
                $reply = trim($reply);
                if (!empty($reply) && filter_var($reply, FILTER_VALIDATE_EMAIL)) {
                    $mail->addReplyTo($reply);
                }
            }
            $mail->isHTML(true);
            $mail->Subject = $this->subject;
            $mail->Body = $message;
            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            return $mail->ErrorInfo;
        }
    }
}

// Classe pour envoyer en masse des e-mails personnalisés
class BulkMailer {
    private $dataParser;
    private $emailSender;
    private $indexPrenom;
    private $indexEmail;
    private $messageTemplate;
    private $baseTemplate;
    private $senderSuffix;
    private $results = [];
    public function __construct(
        $dataParser,
        EmailSender $emailSender,
        int $indexPrenom,
        int $indexEmail,
        string $messageTemplate,
        string $baseTemplate,
        string $senderSuffix
    ) {
        $this->dataParser = $dataParser;
        $this->emailSender = $emailSender;
        $this->indexPrenom = $indexPrenom;
        $this->indexEmail = $indexEmail;
        $this->messageTemplate = $messageTemplate;
        $this->baseTemplate = $baseTemplate;
        $this->senderSuffix = $senderSuffix;
    }
    private function formatEmailContent(string $prenom): string {
        $name = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
        $content = str_replace('{{name}}', $name, $this->messageTemplate);
        $formatted = str_replace('{{content}}', $content, $this->baseTemplate);
        $formatted = str_replace('{{zone}}', $this->senderSuffix ? ' | ' . $this->senderSuffix : '', $formatted);
        $formatted = str_replace('{{annee}}', date('Y'), $formatted);
        return $formatted;
    }
    public function process(): array {
        $rows = $this->dataParser->parse();
        $sentCount = 0;
        $failedCount = 0;
        foreach ($rows as $data) {
            $prenom = isset($data[$this->indexPrenom]) ? trim($data[$this->indexPrenom]) : "";
            $email  = isset($data[$this->indexEmail]) ? trim($data[$this->indexEmail]) : "";
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $message = $this->formatEmailContent($prenom);
            $result = $this->emailSender->sendEmail($email, $prenom, $message);
            if ($result === true) {
                $sentCount++;
                $this->results[] = "Email envoyé à <strong>$prenom ($email)</strong>.";
            } else {
                $failedCount++;
                $this->results[] = "Erreur pour <strong>$email</strong> : " . $result;
            }
        }
        $this->results[] = "<br>Envoi terminé : <strong>$sentCount</strong> email(s) envoyé(s), <strong>$failedCount</strong> échec(s).";
        return $this->results;
    }
}

/* -----------------------------------------------------------------------
   CHARGEMENT DES LISTES POUR LES DROPDOWNS (Google Sheets et onglets)
------------------------------------------------------------------------ */
$sheetsDropdownHtml = "";
try {
    $lister = new GoogleSheetsLister();
    $sheets = $lister->listSheets();
    if (!empty($sheets)) {
        $sheetsDropdownHtml .= '<select name="selected_sheet" id="selected_sheet" class="form-select">';
        $sheetsDropdownHtml .= '<option value="">-- Choisir un Google Sheet --</option>';
        foreach ($sheets as $sheet) {
            $sheetsDropdownHtml .= '<option value="' . htmlspecialchars($sheet->getId()) . '">' . htmlspecialchars($sheet->getName()) . '</option>';
        }
        $sheetsDropdownHtml .= '</select>';
    } else {
        $sheetsDropdownHtml = '<p class="text-danger">Aucun Google Sheet trouvé.</p>';
    }
} catch (Exception $e) {
    $sheetsDropdownHtml = '<p class="text-danger">Erreur lors du chargement des Google Sheets : ' . htmlspecialchars($e->getMessage()) . '</p>';
}

/* -----------------------------------------------------------------------
   TRAITEMENT DU FORMULAIRE
------------------------------------------------------------------------ */
$error = "";
$results = "";
$googleSheetDebugHtml = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // On détermine l'ID du Google Sheet : priorité à la dropdown, sinon le champ
    $spreadsheetId = "";
    if (!empty($_POST['selected_sheet'])) {
        $spreadsheetId = trim($_POST['selected_sheet']);
    } elseif (!empty($_POST['spreadsheet_id'])) {
        $spreadsheetId = trim($_POST['spreadsheet_id']);
    }
    if (!empty($spreadsheetId)) {
        $sheetRange = !empty($_POST['sheet_range']) ? trim($_POST['sheet_range']) : 'Tableau1';
        try {
            $dataParser = new GoogleSheetParser($spreadsheetId, $sheetRange);
            $headers = $dataParser->getHeaders();
            $googleSheetDebugHtml = $dataParser->getDebugHtml();
        } catch (Exception $e) {
            $error = "Erreur lors de la récupération du Google Sheet : " . $e->getMessage();
            $headers = [];
        }
        if (empty($headers)) {
            $error = "Le Google Sheet est vide ou invalide.";
        } else {
            $fieldname  = $_POST['fieldname'] ?? '';
            $fieldemail = $_POST['fieldemail'] ?? '';
            $indexPrenom = array_search($fieldname, $headers);
            $indexEmail  = array_search($fieldemail, $headers);
            $messageTemplate = $_POST['message'] ?? '';
            if ((strpos($messageTemplate, '{{name}}') !== false && $indexPrenom === false) || $indexEmail === false) {
                $error = "La colonne 'Email' ou le champ indiquant le prénom n'a pas été trouvée dans le Google Sheet.";
            } else {
                $smtpConfig = [
                    'host'       => $_POST['smtp_host'] ?? '',
                    'port'       => $_POST['smtp_port'] ?? '',
                    'encryption' => $_POST['smtp_encryption'] ?? '',
                    'user'       => $_POST['smtp_user'] ?? '',
                    'password'   => $_POST['smtp_password'] ?? '',
                ];
                $emailFrom = $_POST['email_from'] ?? $smtpConfig['user'];
                $subject   = $_POST['subject'] ?? '';
                $replyTo   = $_POST['reply_to'] ?? '';
                $senderSuffix = $_POST['sender_suffix'] ?? '';
                $senderName = 'Raise-Up Cameroon' . ($senderSuffix ? ' | ' . $senderSuffix : '');
                $baseTemplate = $templates['template1']['description'] ?? '';
                $emailSender = new EmailSender($smtpConfig, $emailFrom, $senderName, $subject, $replyTo);
                $bulkMailer = new BulkMailer($dataParser, $emailSender, $indexPrenom, $indexEmail, $messageTemplate, $baseTemplate, $senderSuffix);
                $results = $bulkMailer->process();
            }
        }
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $dataParser = new CsvParser($_FILES['csv_file']['tmp_name'], ',');
        $headers = $dataParser->getHeaders();
        if (empty($headers)) {
            $error = "Le fichier CSV est vide ou invalide.";
        } else {
            $fieldname  = $_POST['fieldname'] ?? '';
            $fieldemail = $_POST['fieldemail'] ?? '';
            $indexPrenom = array_search($fieldname, $headers);
            $indexEmail  = array_search($fieldemail, $headers);
            $messageTemplate = $_POST['message'] ?? '';
            if ((strpos($messageTemplate, '{{name}}') !== false && $indexPrenom === false) || $indexEmail === false) {
                $error = "La colonne 'Email' ou le champ indiquant le prénom n'a pas été trouvée dans le fichier CSV.";
            } else {
                $smtpConfig = [
                    'host'       => $_POST['smtp_host'] ?? '',
                    'port'       => $_POST['smtp_port'] ?? '',
                    'encryption' => $_POST['smtp_encryption'] ?? '',
                    'user'       => $_POST['smtp_user'] ?? '',
                    'password'   => $_POST['smtp_password'] ?? '',
                ];
                $emailFrom = $_POST['email_from'] ?? $smtpConfig['user'];
                $subject   = $_POST['subject'] ?? '';
                $replyTo   = $_POST['reply_to'] ?? '';
                $senderSuffix = $_POST['sender_suffix'] ?? '';
                $senderName = 'Raise-Up Cameroon' . ($senderSuffix ? ' | ' . $senderSuffix : '');
                $baseTemplate = $templates['template1']['description'] ?? '';
                $emailSender = new EmailSender($smtpConfig, $emailFrom, $senderName, $subject, $replyTo);
                $bulkMailer = new BulkMailer($dataParser, $emailSender, $indexPrenom, $indexEmail, $messageTemplate, $baseTemplate, $senderSuffix);
                $results = $bulkMailer->process();
            }
        }
    } else {
        $error = "Aucune source de données n'a été renseignée.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RUC Bulk Mail - Interface Moderne</title>
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="./css/all.min.css">
  <style>
    /* Arrière-plan avec dégradé subtil */
    body {
        background: linear-gradient(135deg, #f5f7fa, #c3cfe2);
        transition: background-color 0.3s, color 0.3s;
        background-attachment: fixed;
    }
    body.dark-mode {
        background: linear-gradient(135deg, #2e2e2e, #1a1a1a);
    }
    /* Mode clair/sombre */
    body {
        color: #212529;
    }
    body.dark-mode {
        color: #e0e0e0;
    }
    /* Navbar personnalisée */
    .navbar-custom {
        background-color: #ffffff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    body.dark-mode .navbar-custom {
        background-color: #1e1e1e;
    }
    /* Conteneur principal */
    .container-main {
        max-width: 1200px;
        margin: auto;
        padding: 20px;
    }
    /* Carte Glassmorphisme */
    .glass-card {
        background: rgba(255, 255, 255, 0.2);
        box-shadow: 0 4px 30px rgba(0,0,0,0.1);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 15px;
    }
    /* Champs et boutons */
    .form-control:focus, .form-select:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 0.2rem rgba(79,70,229,0.25);
    }
    .btn:hover {
        transform: scale(1.02);
        transition: transform 0.2s;
    }
    /* Indicateur pour les champs obligatoires */
    .required::after {
        content: " *";
        color: #dc3545;
    }
    /* CKEditor en pleine largeur */
    .ck-editor__editable {
        width: 100% !important;
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-light navbar-custom mb-4">
    <div class="container-fluid">
      <a class="navbar-brand" href="#">RUC Bulk Mail</a>
      <button class="btn btn-outline-secondary" id="themeToggle">Toggle Dark Mode</button>
    </div>
  </nav>
  
  <div class="container container-main">
    <div class="card glass-card p-4 mb-4 shadow-sm">
      <h2 class="card-title mb-4">Envoyer des mails en masse</h2>
      
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= $error; ?></div>
      <?php endif; ?>
      <?php if (!empty($results)): ?>
        <div class="alert alert-success">
          <?php foreach ($results as $res): ?>
            <p><?= $res; ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data">
      <!-- Système d'onglets pour choisir la source -->
      <ul class="nav nav-tabs mb-3" id="dataSourceTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="csv-tab-btn" data-bs-toggle="tab" data-bs-target="#csv-tab" type="button" role="tab" aria-controls="csv-tab" aria-selected="true">Fichier CSV</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="gsheet-tab-btn" data-bs-toggle="tab" data-bs-target="#gsheet-tab" type="button" role="tab" aria-controls="gsheet-tab" aria-selected="false">Google Sheet</button>
        </li>
      </ul>
      <div class="tab-content mb-4" id="dataSourceTabsContent">
        <!-- Onglet CSV -->
        <div class="tab-pane fade show active" id="csv-tab" role="tabpanel" aria-labelledby="csv-tab-btn">
          <div class="mb-3">
            <label for="csv_file" class="form-label">Fichier CSV</label>
            <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv">
          </div>
        </div>
        <!-- Onglet Google Sheet -->
        <div class="tab-pane fade" id="gsheet-tab" role="tabpanel" aria-labelledby="gsheet-tab-btn">
          <div class="row">
            <div class="col-md-6">
                <label for="selected_sheet" class="form-label">Liste des Google Sheets</label>
                <?= $sheetsDropdownHtml; ?>
            </div>
            <div class="col-md-6">
                <label for="spreadsheet_id" class="form-label">ID du Google Sheet</label>
                <input type="text" name="spreadsheet_id" id="spreadsheet_id" class="form-control" placeholder="Ex : 1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms">
            </div>
            <div class="col-md-6 ">
                <label for="selected_tab" class="form-label">Liste des onglets</label>
                <select name="selected_tab" id="selected_tab" class="form-select">
                <option value="">-- Choisir un onglet --</option>
                </select>
            </div>
            <div class="col-md-6">
                <label for="sheet_range" class="form-label">Nom de l'onglet</label>
                <input type="text" name="sheet_range" id="sheet_range" class="form-control" placeholder="Ex : Tableau1">
            </div>
            <!-- Zone de debug pour le Google Sheet -->
            <div class="col-md-6">
                <?= !empty($googleSheetDebugHtml) ? $googleSheetDebugHtml : ''; ?>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Champs communs -->
      <div class="row g-3">
        <!-- Colonnes du fichier -->
        <div class="col-md-6">
          <label for="fieldname" class="form-label required">Colonne Prénom</label>
          <input type="text" name="fieldname" id="fieldname" class="form-control" value="2. Prénom">
        </div>
        <div class="col-md-6">
          <label for="fieldemail" class="form-label required">Colonne E-mail</label>
          <input type="text" name="fieldemail" id="fieldemail" class="form-control" value="3. Email">
        </div>
        <!-- Configuration SMTP -->
        <div class="col-12">
          <h3 class="mt-4">Configuration SMTP</h3>
        </div>
        <div class="col-md-6">
          <label for="smtp_host" class="form-label required">Hôte SMTP</label>
          <input type="text" name="smtp_host" id="smtp_host" class="form-control" value="smtp.gmail.com" required>
        </div>
        <div class="col-md-6">
          <label for="smtp_port" class="form-label required">Port SMTP</label>
          <input type="text" name="smtp_port" id="smtp_port" class="form-control" value="587" required>
        </div>
        <div class="col-md-6">
          <label for="smtp_encryption" class="form-label">Encryption SMTP</label>
          <input type="text" name="smtp_encryption" id="smtp_encryption" class="form-control" value="tls">
        </div>
        <div class="col-md-6">
          <label for="smtp_user" class="form-label required">Utilisateur SMTP</label>
          <input type="text" name="smtp_user" id="smtp_user" class="form-control" value="raiseup.cameroon@gmail.com" required>
        </div>
        <div class="col-md-6">
          <label for="smtp_password" class="form-label required">Mot de passe SMTP</label>
          <input type="password" name="smtp_password" id="smtp_password" class="form-control" value="rljf iwgb mlad evlm" required>
        </div>
        <div class="col-md-6">
          <label for="email_from" class="form-label required">Email de l'expéditeur</label>
          <input type="email" name="email_from" id="email_from" class="form-control" value="raiseup.cameroon@gmail.com" required>
        </div>
        <div class="col-md-6">
          <label for="sender_suffix" class="form-label">Région/Pays RUC émettrice</label>
          <select name="sender_suffix" id="sender_suffix" class="form-select">
            <option value="">Aucun suffixe</option>
            <option value="Adamaoua">Adamaoua</option>
            <option value="Centre">Centre</option>
            <option value="Est">Est</option>
            <option value="Extrême-Nord">Extrême-Nord</option>
            <option value="Littoral">Littoral</option>
            <option value="Nord">Nord</option>
            <option value="Nord-Ouest">Nord-Ouest</option>
            <option value="Ouest">Ouest</option>
            <option value="Sud">Sud</option>
            <option value="Sud-Ouest">Sud-Ouest</option>
          </select>
        </div>
        <div class="col-12">
          <label for="reply_to" class="form-label required">Adresse de réponse</label>
          <input type="text" name="reply_to" id="reply_to" class="form-control" value="raiseup.cameroon@gmail.com;danieluokof@gmail.com,kengnemanuella24@gmail.com;ngonojuly254@gmail.com" required>
        </div>
        <!-- Contenu du mail -->
        <div class="col-12">
          <h3 class="mt-4">Contenu du mail</h3>
        </div>
        <div class="col-12">
          <label for="subject" class="form-label required">Sujet</label>
          <input type="text" name="subject" id="subject" class="form-control" value="🔔 Rappel – Événement de lancement de Raise-Up Cameroon en France 🎉" required>
        </div>
        <div class="col-12">
          <label for="message" class="form-label required">Template du mail (HTML)</label>
          <textarea name="message" id="message" class="form-control" rows="10" required>
<p>Bonjour {{name}},</p>
<p>Nous tenons à vous remercier pour votre implication lors du lancement de <i>Raise-Up Cameroon</i> en France !</p>
<p>L’événement a été un véritable succès, marqué par des échanges enrichissants et une belle énergie collective.</p>
<p>Restez connectés pour découvrir nos prochaines activités.</p>
<p>À très bientôt !</p>
<p><strong>L’équipe RUC</strong></p>
          </textarea>
        </div>
        <div class="col-12">
          <button type="submit" class="btn btn-primary btn-lg w-100 mt-3">Envoyer les mails</button>
        </div>
      </div>
      <!-- Fin des champs communs -->
      </form>
    </div>
  </div>
  <div class="toast-custom" id="toast"></div>
  
  <!-- Bootstrap JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="./js/ckeditor.js"></script>
  <script>
    // Initialisation de CKEditor pour le textarea "message"
    ClassicEditor.create(document.querySelector('#message'), {
      toolbar: [
        'undo', 'redo', '|',
        'heading', '|',
        'bold', 'italic', 'underline', 'strikethrough', '|',
        'fontSize', 'fontFamily', 'fontColor', 'fontBackgroundColor', '|',
        'alignment', '|',
        'bulletedList', 'numberedList', '|',
        'link', 'imageUpload'
      ],
      language: 'fr'
    }).then(editor => {
      editor.ui.view.editable.element.style.height = '400px';
      console.log('CKEditor chargé', editor);
    }).catch(error => console.error('Erreur CKEditor :', error));

    // Fonction de copie d'un champ
    function copyField(fieldId) {
      const field = document.getElementById(fieldId);
      if (field) {
        field.select();
        field.setSelectionRange(0, 99999);
        document.execCommand("copy");
        showToast("Contenu copié !");
      }
    }

    // Basculement du mot de passe
    function togglePassword() {
      const passwordField = document.getElementById("smtp_password");
      const eyeIcon = document.getElementById("eyeIcon");
      if (passwordField.type === "password") {
        passwordField.type = "text";
        eyeIcon.classList.replace("fa-eye", "fa-eye-slash");
      } else {
        passwordField.type = "password";
        eyeIcon.classList.replace("fa-eye-slash", "fa-eye");
      }
    }

    // Affichage d'un toast
    function showToast(message) {
      const toast = document.getElementById("toast");
      toast.textContent = message;
      toast.style.display = "block";
      setTimeout(() => toast.style.display = "none", 3000);
    }

    // Mise à jour automatique du champ d'ID et chargement des onglets
    document.addEventListener("DOMContentLoaded", function() {
      const selectedSheetDropdown = document.getElementById("selected_sheet");
      const spreadsheetIdField = document.getElementById("spreadsheet_id");
      const selectedTabDropdown = document.getElementById("selected_tab");
      const sheetRangeField = document.getElementById("sheet_range");

      if (selectedSheetDropdown && spreadsheetIdField) {
        selectedSheetDropdown.addEventListener("change", function() {
          const selectedId = this.value;
          spreadsheetIdField.value = selectedId;
          // Requête AJAX pour récupérer les onglets
          fetch("getSheetTabs.php?spreadsheet_id=" + encodeURIComponent(selectedId))
            .then(response => response.json())
            .then(data => {
              if (selectedTabDropdown) {
                selectedTabDropdown.innerHTML = "";
                if (Array.isArray(data) && data.length > 0) {
                  data.forEach(function(tab) {
                    const option = document.createElement("option");
                    option.value = tab;
                    option.textContent = tab;
                    selectedTabDropdown.appendChild(option);
                  });
                  // Sélection automatique du premier onglet
                  sheetRangeField.value = data[0];
                } else {
                  selectedTabDropdown.innerHTML = '<option value="">Aucun onglet trouvé</option>';
                }
              }
            })
            .catch(error => {
              console.error("Erreur lors du chargement des onglets : ", error);
            });
        });
      }
      if (selectedTabDropdown && sheetRangeField) {
        selectedTabDropdown.addEventListener("change", function() {
          sheetRangeField.value = this.value;
        });
      }
    });

    // Mode sombre / clair
    const themeToggle = document.getElementById("themeToggle");
    themeToggle.addEventListener("click", function() {
      document.body.classList.toggle("dark-mode");
    });
  </script>
</body>
</html>
