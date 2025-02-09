<?php
// index.php

// Inclusion de l'autoload de Composer et du fichier de templates
require 'vendor/autoload.php';
require './templates.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Classe pour lire et parser un fichier CSV.
 * Cette version lit le fichier une seule fois et stocke à la fois les en-têtes et les lignes.
 */
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

    /**
     * Analyse le fichier CSV et stocke les en-têtes et lignes.
     *
     * @return array Les lignes du CSV (chaque ligne est un tableau)
     */
    public function parse(): array {
        if ($this->parsed) {
            return $this->rows;
        }
        if (($handle = fopen($this->file, 'r')) !== false) {
            // Lecture de la première ligne (les en-têtes)
            $firstLine = fgetcsv($handle, 1000, $this->delimiter);
            if ($firstLine !== false) {
                // Suppression du BOM dans le premier champ s'il est présent
                if (isset($firstLine[0])) {
                    $firstLine[0] = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine[0]);
                }
                $this->headers = array_map('trim', $firstLine);
            }
            // Lecture des lignes suivantes
            while (($data = fgetcsv($handle, 1000, $this->delimiter)) !== false) {
                // Ignorer les lignes vides
                if(count($data) === 1 && $data[0] === null) {
                    continue;
                }
                $this->rows[] = $data;
            }
            fclose($handle);
            $this->parsed = true;
        }
        return $this->rows;
    }

    /**
     * Retourne le tableau des en-têtes.
     */
    public function getHeaders(): array {
        if (!$this->parsed) {
            $this->parse();
        }
        return $this->headers;
    }
}

/**
 * Classe pour envoyer un e-mail en utilisant PHPMailer.
 */
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
        // Sépare les adresses reply-to (séparées par espace, virgule ou point-virgule)
        $this->replyToAddresses = preg_split('/[\s,;]+/', $replyTo);
    }

    /**
     * Envoie un e-mail à un destinataire donné.
     *
     * @param string $toEmail Adresse e-mail du destinataire
     * @param string $toName  Nom du destinataire
     * @param string $message Contenu HTML du message
     *
     * @return bool|string Retourne true en cas de succès, sinon le message d'erreur
     */
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

            // Ajout des adresses reply-to
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

/**
 * Classe pour gérer l'envoi en masse d'e-mails.
 */
class BulkMailer {
    private $csvParser;
    private $emailSender;
    private $indexPrenom;
    private $indexEmail;
    private $messageTemplate;
    private $baseTemplate;
    private $senderSuffix;
    private $results = [];

    public function __construct(
        CsvParser $csvParser,
        EmailSender $emailSender,
        int $indexPrenom,
        int $indexEmail,
        string $messageTemplate,
        string $baseTemplate,
        string $senderSuffix
    ) {
        $this->csvParser       = $csvParser;
        $this->emailSender     = $emailSender;
        $this->indexPrenom     = $indexPrenom;
        $this->indexEmail      = $indexEmail;
        $this->messageTemplate = $messageTemplate;
        $this->baseTemplate    = $baseTemplate;
        $this->senderSuffix    = $senderSuffix;
    }

    /**
     * Formate le contenu du mail en insérant le prénom et d’autres marqueurs.
     */
    private function formatEmailContent(string $prenom): string {
        $name = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
        // Remplacement du marqueur {{name}} dans le message utilisateur
        $content = str_replace('{{name}}', $name, $this->messageTemplate);
        // Insertion du contenu dans le template global
        $formatted = str_replace('{{content}}', $content, $this->baseTemplate);
        $formatted = str_replace('{{zone}}', $this->senderSuffix ? ' | ' . $this->senderSuffix : '', $formatted);
        $formatted = str_replace('{{annee}}', date('Y'), $formatted);
        return $formatted;
    }

    /**
     * Parcourt le CSV et envoie les e-mails personnalisés.
     *
     * @return array Tableau des messages de résultat pour chaque envoi
     */
    public function process(): array {
        $rows = $this->csvParser->parse();
        $sentCount = 0;
        $failedCount = 0;

        foreach ($rows as $data) {
            $prenom = isset($data[$this->indexPrenom]) ? trim($data[$this->indexPrenom]) : "";
            $email  = isset($data[$this->indexEmail]) ? trim($data[$this->indexEmail]) : "";
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
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

// --- Traitement du formulaire ---
$error = "";
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "Erreur lors du téléchargement du fichier CSV.";
    } else {
        // Instanciation du parser CSV avec le délimiteur approprié (modifiez ici si votre CSV utilise ';')
        $csvParser = new CsvParser($_FILES['csv_file']['tmp_name'], ',');
        $headers = $csvParser->getHeaders();
        if (empty($headers)) {
            $error = "Le fichier CSV est vide ou invalide.";
        } else {
            // Récupération des colonnes souhaitées (les espaces sont supprimés grâce au trim)
            $fieldname  = $_POST['fieldname'] ?? '';
            $fieldemail = $_POST['fieldemail'] ?? '';
            $indexPrenom = array_search($fieldname, $headers);
            $indexEmail  = array_search($fieldemail, $headers);
            $messageTemplate = $_POST['message'] ?? '';
            if ((strpos($messageTemplate, '{{name}}') !== false && $indexPrenom === false) || $indexEmail === false) {
                $error = "La colonne 'Email' ou le champ indiquant le prénom n'a pas été trouvée dans le fichier CSV.";
            } else {
                $smtpConfig = [
                    'host'       => $_POST['smtp_host']       ?? '',
                    'port'       => $_POST['smtp_port']       ?? '',
                    'encryption' => $_POST['smtp_encryption'] ?? '',
                    'user'       => $_POST['smtp_user']       ?? '',
                    'password'   => $_POST['smtp_password']   ?? '',
                ];
                $emailFrom = $_POST['email_from'] ?? $smtpConfig['user'];
                $subject   = $_POST['subject'] ?? '';
                $replyTo   = $_POST['reply_to'] ?? '';
                $senderSuffix = $_POST['sender_suffix'] ?? '';
                $senderName = 'Raise-Up Cameroon' . ($senderSuffix ? ' | ' . $senderSuffix : '');
                // Utilisation du template par défaut depuis templates.php (par exemple template1)
                $baseTemplate = $templates['template1']['description'] ?? '';
                $emailSender = new EmailSender($smtpConfig, $emailFrom, $senderName, $subject, $replyTo);
                $bulkMailer = new BulkMailer($csvParser, $emailSender, $indexPrenom, $indexEmail, $messageTemplate, $baseTemplate, $senderSuffix);
                $results = $bulkMailer->process();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>📨 RUC Bulk Mail - Responsive (Bootstrap)</title>
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome pour les icônes -->
  <link rel="stylesheet" href="./css/all.min.css">
  <style>
    /* Personnalisation de CKEditor : fond blanc et texte noir */
    .ck-editor__editable {
      background-color: #fff !important;
      color: #000 !important;
      min-height: 250px;
    }
    /* Style personnalisé pour le toast */
    .toast-custom {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%);
      background: #333;
      color: #fff;
      padding: 16px;
      border-radius: 8px;
      z-index: 1055;
      display: none;
    }
    /* Dropdown des zones : fond clair et texte noir */
    #sender_suffix {
      background-color: rgba(255, 255, 255, 0.8) !important;
      color: #000 !important;
    }
  </style>
  <script src="./js/ckeditor.js"></script>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
      <a class="navbar-brand" href="#">Mail Sender - Tool</a>
    </div>
  </nav>
  <div class="container">
    <h1 class="text-center mb-4">Envoyer des mails via CSV</h1>
    <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><?= $error; ?></div>
    <?php endif; ?>
    <?php if (!empty($results)): ?>
      <div class="alert alert-success">
        <?php if (!empty($_POST['sender_suffix'])): ?>
          <div>E-mails envoyés pour <?= htmlspecialchars($_POST['sender_suffix'], ENT_QUOTES, 'UTF-8'); ?></div>
          <hr/>
        <?php endif; ?>
        <?php foreach ($results as $res): ?>
          <p><?= $res; ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" class="p-2">
      <!-- Section Fichier CSV -->
      <div class="mb-4">
        <label for="csv_file" class="form-label">Fichier CSV <span class="text-danger">*</span></label>
        <div class="input-group">
          <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
          <button type="button" class="btn btn-outline-secondary" onclick="copyField('csv_file')">
            <i class="fa fa-copy"></i>
          </button>
        </div>
      </div>
      <!-- Section Colonnes CSV -->
      <div class="row mb-4">
        <div class="col-md-6">
          <label for="fieldname" class="form-label">Colonne prénom du CSV</label>
          <div class="input-group">
            <input type="text" name="fieldname" id="fieldname" class="form-control" value="2. Prénom">
            <button type="button" class="btn btn-outline-secondary" onclick="copyField('fieldname')">
              <i class="fa fa-copy"></i>
            </button>
          </div>
        </div>
        <div class="col-md-6">
          <label for="fieldemail" class="form-label">Colonne e-mail du CSV <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="text" name="fieldemail" id="fieldemail" class="form-control" value="3. Email" required>
            <button type="button" class="btn btn-outline-secondary" onclick="copyField('fieldemail')">
              <i class="fa fa-copy"></i>
            </button>
          </div>
        </div>
      </div>
      <!-- Section Configuration SMTP -->
      <h2 class="h4 mb-3">Configuration SMTP</h2>
      <div class="row mb-4">
        <div class="col-md-6">
          <label for="smtp_host" class="form-label">Hôte SMTP <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="text" name="smtp_host" id="smtp_host" class="form-control" value="smtp.gmail.com" required>
            <button type="button" class="btn btn-outline-secondary" onclick="copyField('smtp_host')">
              <i class="fa fa-copy"></i>
            </button>
          </div>
        </div>
        <div class="col-md-6">
          <label for="smtp_port" class="form-label">Port SMTP <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="text" name="smtp_port" id="smtp_port" class="form-control" value="587" required>
            <button type="button" class="btn btn-outline-secondary" onclick="copyField('smtp_port')">
              <i class="fa fa-copy"></i>
            </button>
          </div>
        </div>
        <div class="col-md-6 mt-3">
          <label for="smtp_encryption" class="form-label">Encryption SMTP (tls, ssl ou vide) <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="text" name="smtp_encryption" id="smtp_encryption" class="form-control" value="tls">
            <button type="button" class="btn btn-outline-secondary" onclick="copyField('smtp_encryption')">
              <i class="fa fa-copy"></i>
            </button>
          </div>
        </div>
        <div class="col-md-6 mt-3">
          <label for="smtp_user" class="form-label">Utilisateur SMTP (Email) <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="text" name="smtp_user" id="smtp_user" class="form-control" value="raiseup.cameroon@gmail.com" required>
            <button type="button" class="btn btn-outline-secondary" onclick="copyField('smtp_user')">
              <i class="fa fa-copy"></i>
            </button>
          </div>
        </div>
        <div class="col-md-6 mt-3">
          <label for="smtp_password" class="form-label">Mot de passe SMTP <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="password" name="smtp_password" id="smtp_password" class="form-control" value="rljf iwgb mlad evlm" required>
            <button type="button" class="btn btn-outline-secondary" onclick="togglePassword()">
              <i id="eyeIcon" class="fa fa-eye"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="copyField('smtp_password')">
              <i class="fa fa-copy"></i>
            </button>
          </div>
        </div>
        <div class="col-md-6 mt-3">
          <label for="email_from" class="form-label">Email de l'expéditeur <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="email" name="email_from" id="email_from" class="form-control" value="raiseup.cameroon@gmail.com" required>
            <button type="button" class="btn btn-outline-secondary" onclick="copyField('email_from')">
              <i class="fa fa-copy"></i>
            </button>
          </div>
        </div>
        <div class="col-md-6 mt-3">
          <label for="sender_suffix" class="form-label">Région/Pays RUC émettrice</label>
          <select name="sender_suffix" id="sender_suffix" class="form-select">
            <option value="">Aucun suffixe</option>
            <optgroup label="Régions du Cameroun">
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
            </optgroup>
            <optgroup label="Pays">
              <option value="Canada">Canada</option>
              <option value="France">France</option>
              <option value="USA">USA</option>
            </optgroup>
          </select>
        </div>
        <div class="col-md-6 mt-3">
          <label for="reply_to" class="form-label">Adresse de réponse</label>
          <div class="input-group">
            <input type="text" name="reply_to" id="reply_to" class="form-control" value="raiseup.cameroon@gmail.com;danieluokof@gmail.com,kengnemanuella24@gmail.com;ngonojuly254@gmail.com" required>
            <button type="button" class="btn btn-outline-secondary" onclick="copyField('reply_to')">
              <i class="fa fa-copy"></i>
            </button>
          </div>
        </div>
      </div>
      <!-- Section Contenu du mail -->
      <h2 class="h4 mb-3">Contenu du mail</h2>
      <div class="mb-4">
        <label for="subject" class="form-label">Sujet <span class="text-danger">*</span></label>
        <div class="input-group">
          <input type="text" name="subject" id="subject" class="form-control" value="🔔 Rappel – Événement de lancement de Raise-Up Cameroon en France 🎉" required>
          <button type="button" class="btn btn-outline-secondary" onclick="copyField('subject')">
            <i class="fa fa-copy"></i>
          </button>
        </div>
      </div>
      <div class="mb-4">
        <label for="message" class="form-label">Template du mail (HTML) – utilisez <code>{{name}}</code> pour personnaliser le prénom <span class="text-danger">*</span></label>
        <div class="input-group">
          <textarea name="message" id="message" class="form-control" required rows="10">
<p>Bonjour {{name}},</p>
<p>Nous tenons à vous remercier pour votre implication et votre engagement lors du lancement de <i>Raise-Up Cameroon</i> en France !</p>
<p>L’événement a été un véritable succès, marqué par des échanges riches, des idées innovantes et une belle énergie collective.</p>
<p><strong>La suite ?</strong></p>
<p>Nous préparons déjà nos prochaines activités. Restez connectés et rejoignez-nous pour continuer à bâtir ensemble l’avenir du Cameroun.</p>
<p>📸 Revivez l’événement en images sur toutes nos pages.</p>
<p>À très bientôt !</p>
<p><strong>L’équipe RUC de France 🇨🇲</strong></p>
          </textarea>
        </div>
      </div>
      <div class="d-grid">
        <button type="submit" class="btn btn-primary btn-lg">Envoyer les mails</button>
      </div>
    </form>
  </div>
  <div class="toast-custom" id="toast"></div>
  <!-- Bootstrap JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

    // Fonction de copie d'un champ dans le presse-papier
    function copyField(fieldId) {
      const field = document.getElementById(fieldId);
      if (field) {
        field.select();
        field.setSelectionRange(0, 99999);
        document.execCommand("copy");
        showToast("Contenu copié !");
      }
    }

    // Fonction de basculement de l'affichage du mot de passe
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

    // Fonction pour afficher un toast
    function showToast(message) {
      const toast = document.getElementById("toast");
      toast.textContent = message;
      toast.style.display = "block";
      setTimeout(() => toast.style.display = "none", 3000);
    }
  </script>
</body>
</html>
