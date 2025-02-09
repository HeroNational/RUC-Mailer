<?php
// Inclusion de l'autoload de Composer
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$error = "";
$results = [];

// Traitement du formulaire d'envoi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérifier qu'un fichier CSV a été uploadé
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "Erreur lors du téléchargement du fichier CSV.";
    } else {
        $csvFile = $_FILES['csv_file']['tmp_name'];
        if (($handle = fopen($csvFile, "r")) !== FALSE) {
            // Lecture de la première ligne pour obtenir les en-têtes et suppression des espaces superflus
            $header = fgetcsv($handle, 1000, ",");
            $header = array_map('trim', $header);
            if (!$header) {
                $error = "Le fichier CSV est vide ou invalide.";
            } else {
                // Recherche des colonnes "2. Prénom" et "3. Email" dans les en-têtes
                $indexPrenom = array_search($_POST['fieldname']??'emptyvaluenotexit', $header);
                $indexEmail  = array_search($_POST['fieldemail']??'emptyvaluenotexit', $header);
                if ($indexPrenom === false || $indexEmail === false) {
                    $error = "Les colonnes 'Prénom' ou 'Email' n'ont pas été trouvées dans le fichier CSV.";
                } else {
                    // Récupérer les paramètres SMTP et le contenu du mail depuis le formulaire
                    $smtp_host       = $_POST['smtp_host'] ?? '';
                    $smtp_port       = $_POST['smtp_port'] ?? '';
                    $smtp_encryption = $_POST['smtp_encryption'] ?? ''; // Ex : tls, ssl ou vide
                    $smtp_user       = $_POST['smtp_user'] ?? '';
                    $smtp_password   = $_POST['smtp_password'] ?? '';
                    $email_from      = $_POST['email_from'] ?? $smtp_user;
                    $subject         = $_POST['subject'] ?? '';
                    $messageTemplate = $_POST['message'] ?? '';
                    $reply_to        = $_POST['reply_to'] ?? '';

                    $sentCount   = 0;
                    $failedCount = 0;

                    // Parcours de chaque ligne du CSV
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        // Récupération et nettoyage des valeurs
                        $prenom = isset($data[$indexPrenom]) ? trim($data[$indexPrenom]) : "";
                        $email  = isset($data[$indexEmail]) ? trim($data[$indexEmail]) : "";
                        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            continue; // On ignore les lignes sans adresse email ou avec une adresse invalide
                        }
                        // Personnalisation du message HTML : remplace le marqueur {{name}} par le prénom du destinataire
                        $personalizedMessage = str_replace('{{name}}', htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8'), $messageTemplate);

                        // Création d'une instance de PHPMailer pour chaque envoi
                        $mail = new PHPMailer(true);
                        try {
                            // Spécifier le charset UTF-8 pour garantir le bon encodage des caractères
                            $mail->CharSet = 'UTF-8';
                            $mail->isSMTP();
                            $mail->Host       = $smtp_host;
                            $mail->Port       = $smtp_port;
                            if (!empty($smtp_encryption)) {
                                $mail->SMTPSecure = $smtp_encryption; // 'tls' ou 'ssl'
                            }
                            $mail->SMTPAuth   = true;
                            $mail->Username   = $smtp_user;
                            $mail->Password   = $smtp_password;

                            // Paramètres de l'email
                            $mail->setFrom($email_from, 'Raise-Up Cameroon');
                            $mail->addAddress($email, $prenom);
                            $mail->isHTML(true);
                            $mail->Subject = $subject;
                            $mail->Body    = $personalizedMessage;

                            // Remplace les "; et espaces" par "," puis divise en tableau
                            $reply_to_addresses = preg_split('/[\s,;]+/', $reply_to);

                            // Parcourir et ajouter chaque adresse e-mail
                            foreach ($reply_to_addresses as $reply_email) {
                                $reply_email = trim($reply_email); // Supprimer les espaces inutiles
                                if (!empty($reply_email) && filter_var($reply_email, FILTER_VALIDATE_EMAIL)) {
                                    $mail->addReplyTo($reply_email);
                                }
                            }

                            // Envoi du mail
                            $mail->send();
                            $sentCount++;
                            $results[] = "Email envoyé à <strong>$email</strong>.";
                        } catch (PHPMailerException $e) {
                            $failedCount++;
                            $results[] = "Erreur pour <strong>$email</strong> : " . $mail->ErrorInfo;
                        }
                    }
                    $results[] = "<br>Envoi terminé : <strong>$sentCount</strong> email(s) envoyé(s), <strong>$failedCount</strong> échec(s).";
                }
            }
            fclose($handle);
        } else {
            $error = "Impossible d'ouvrir le fichier CSV.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📨 RUC Bulk Mail</title>
    <!-- Inclusion de FontAwesome pour les icônes -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Fond en dégradé, typographie moderne, glass morphism et effets neon/aero */
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e1e2f, #2a2a3c);
            color: #fff;
        }
        .navbar {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 20px;
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        .container {
            max-width: 1000px;
            margin: 40px auto;
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.37);
            backdrop-filter: blur(10px);
        }
        h1, h2 {
            text-align: center;
        }
        form {
            margin-top: 20px;
        }
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="password"],
        input[type="file"],
        input[type="email"],
        textarea {
            width: 91%;
            padding: 10px 40px 10px 10px;
            border: none;
            border-radius: 8px;
            background: rgba(255,255,255,0.2);
            color: #fff;
            outline: none;
        }
        textarea {
            resize: vertical;
            min-height: 250px;
        }
        /* Bouton de copie */
        button.copy-btn {
            position: absolute;
            right: 10px;
            top: 35px;
            background: transparent;
            border: none;
            color: #fff;
            cursor: pointer;
            font-size: 18px;
        }
        /* Bouton pour afficher/masquer le mot de passe */
        button.eye-toggle {
            position: absolute;
            right: 40px;
            top: 35px;
            background: transparent;
            border: none;
            color: #fff;
            cursor: pointer;
            font-size: 18px;
        }
        button.send-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(45deg, #6a11cb, #2575fc);
            border: none;
            border-radius: 8px;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        button.send-btn:hover {
            background: linear-gradient(45deg, #2575fc, #6a11cb);
        }
        /* Menu tabulé pour les templates */
        .tab-menu {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .tab-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            color: #fff;
            cursor: pointer;
            transition: background 0.3s;
        }
        .tab-btn:hover, .tab-btn.active {
            background: rgba(255,255,255,0.4);
        }
        /* Toast notifications */
        .toast {
            visibility: hidden;
            min-width: 250px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 8px;
            padding: 16px;
            position: fixed;
            z-index: 1000;
            left: 50%;
            bottom: 30px;
            transform: translateX(-50%);
            font-size: 17px;
        }
        .toast.show {
            visibility: visible;
            animation: fadein 0.5s, fadeout 0.5s 2.5s;
        }
        @keyframes fadein {
            from { bottom: 0; opacity: 0; }
            to { bottom: 30px; opacity: 1; }
        }
        @keyframes fadeout {
            from { bottom: 30px; opacity: 1; }
            to { bottom: 0; opacity: 0; }
        }
        .footer {
            text-align: center;
            padding: 15px;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            margin-top: 40px;
            font-size: 14px;
        }
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .message.error {
            background: rgba(255,0,0,0.3);
        }
        .message.success {
            background: rgba(0,128,0,0.3);
        }
        .form-container {
            display: flex;
            gap: 20px; /* Espacement entre les colonnes */
            justify-content: space-between;
            width: 95%;
        }

        .form-group {
            flex: 1; /* Chaque colonne prend un espace égal */
        }

    </style>
</head>
<body>
    <div class="navbar">
        Mail Sender - Jacobin Daniel
    </div>
    <div class="container">
        <h1>Envoyer des mails via CSV</h1>
        <?php if (!empty($error)): ?>
            <div class="message error"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if (!empty($results)): ?>
            <div class="message success">
                <?php foreach ($results as $res): ?>
                    <p><?php echo $res; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <h2>Charger le fichier CSV</h2>
            <div class="form-group">
                <label for="csv_file">Fichier CSV</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                <button type="button" class="copy-btn" onclick="copyField('csv_file')"><i class="fa fa-copy"></i></button>
            </div>
            <div class="form-container">
                <div class="form-group">
                    <label for="fieldname">Colonne prénon</label>
                    <input type="text" name="fieldname" placeholder="2. Prénom" value="2. Prénom" id="fieldname" accept=".csv" required>
                    <button type="button" class="copy-btn" onclick="copyField('fieldname')"><i class="fa fa-copy"></i></button>
                </div>
                <div class="form-group">
                    <label for="fieldemail">Colonne e-mail</label>
                    <input type="text" name="fieldemail" placeholder="3. Email" value="3. Email" id="fieldemail" accept=".csv" required>
                    <button type="button" class="copy-btn" onclick="copyField('fieldemail')"><i class="fa fa-copy"></i></button>
                </div>
            </div>

            <h2>Configuration SMTP</h2>
            <div class="form-group">
                <label for="smtp_host">Hôte SMTP</label>
                <input type="text" name="smtp_host" id="smtp_host" value="smtp.gmail.com" required>
                <button type="button" class="copy-btn" onclick="copyField('smtp_host')"><i class="fa fa-copy"></i></button>
            </div>
            <div class="form-group">
                <label for="smtp_port">Port SMTP</label>
                <input type="text" name="smtp_port" id="smtp_port" value="587" required>
                <button type="button" class="copy-btn" onclick="copyField('smtp_port')"><i class="fa fa-copy"></i></button>
            </div>
            <div class="form-group">
                <label for="smtp_encryption">Encryption SMTP (tls, ssl ou vide)</label>
                <input type="text" name="smtp_encryption" id="smtp_encryption" value="tls">
                <button type="button" class="copy-btn" onclick="copyField('smtp_encryption')"><i class="fa fa-copy"></i></button>
            </div>
            <div class="form-group">
                <label for="smtp_user">Utilisateur SMTP (Email)</label>
                <input type="text" name="smtp_user" id="smtp_user" value="raiseup.cameroon@gmail.com" required>
                <button type="button" class="copy-btn" onclick="copyField('smtp_user')"><i class="fa fa-copy"></i></button>
            </div>
            <div class="form-group">
                <label for="smtp_password">Mot de passe SMTP</label>
                <input type="password" name="smtp_password" id="smtp_password" value="rljf iwgb mlad evlm" required>
                <button type="button" class="eye-toggle" onclick="togglePassword()"><i id="eyeIcon" class="fa fa-eye"></i></button>
                <button type="button" class="copy-btn" onclick="copyField('smtp_password')"><i class="fa fa-copy"></i></button>
            </div>
            <div class="form-group">
                <label for="email_from">Email de l'expéditeur</label>
                <input type="email" name="email_from" id="email_from" value="raiseup.cameroon@gmail.com" required>
                <button type="button" class="copy-btn" onclick="copyField('email_from')"><i class="fa fa-copy"></i></button>
            </div>
            
            <div class="form-group">
                <label for="smtp_user">Adresse de reponse</label>
                <input type="text" name="reply_to" id="reply_to" value="raiseup.cameroon@gmail.com;danieluokof@gmail.com" required>
                <button type="button" class="copy-btn" onclick="copyField('reply_to')"><i class="fa fa-copy"></i></button>
            </div>
            <h2>Contenu du mail</h2>
            <!-- Menu tabulé pour sélectionner le template -->
            <div class="tab-menu">
                <button type="button" class="tab-btn active" id="tab1" onclick="selectTemplate('template1', this)">Rappel</button>
                <button type="button" class="tab-btn" id="tab2" onclick="selectTemplate('template2', this)">Aujourd'hui</button>
                <button type="button" class="tab-btn" id="tab3" onclick="selectTemplate('template3', this)">Bientôt</button>
                <button type="button" class="tab-btn" id="tab4" onclick="selectTemplate('template4', this)">Remerciement</button>
            </div>
            <div class="form-group">
                <label for="subject">Sujet</label>
                <input type="text" name="subject" id="subject" value="🔔 Rappel – Événement de lancement de Raise-Up Cameroon en France 🎉" required>
                <button type="button" class="copy-btn" onclick="copyField('subject')"><i class="fa fa-copy"></i></button>
            </div>
            <div class="form-group">
                <label for="message">Template du mail (HTML) – utilisez <code>{{name}}</code> pour personnaliser le prénom</label>
                <textarea name="message" id="message" required></textarea>
                <button type="button" class="copy-btn" onclick="copyField('message')"><i class="fa fa-copy"></i></button>
            </div>
            <div class="form-group">
                <button type="submit" class="send-btn">Envoyer les mails</button>
            </div>
        </form>
    </div>
    <div id="toast" class="toast"></div>
    <div class="footer">
        &copy; <?php echo date('Y') ?> - Jacobin Daniel
    </div>
    <script>
        //Définition des sujets de mails
        var subjects = {
            template1 : `🔔 Rappel – Événement de lancement de Raise-Up Cameroon en France 🎉`,
            template2 : `📅 C'est aujourd'hui – Événement de lancement de Raise-Up Cameroon en France 🎉`,
            template3 : `😉 C'est bientôt – Événement de lancement de Raise-Up Cameroon en France 🎉`,
            template4 : `😃 Retour sur l'évènement de lancement de Raise-Up Cameroon en France 🎉`
        }
        // Définition des templates
        var templates = {
            template1: `<p>Bonjour {{name}},</p>
<p>Nous sommes ravis de vous compter parmi les inscrits à notre événement de lancement des activités de <strong>Raise-Up Cameroon en France</strong> ! 🎉</p>
<p>📅 <strong>Date</strong> : Samedi 08 février 2025<br>
🕐 <strong>Heure</strong> : 13h00 (heure de France)<br>
📍 <strong>Lieu</strong> : 5 Rue Paul Dautier, 78140 Vélizy-Villacoublay</p>
<p>Nous avons hâte de vous retrouver et de partager ce moment avec vous !</p>
<p><strong>L’équipe Raise-Up Cameroon France 🇨🇲✨</strong></p>`,

            template2: `
                <!DOCTYPE html>
                <html lang="fr">
                <head>
                <meta charset="UTF-8">
                <title>Lancement de Raise-Up Cameroon en France</title>
                </head>
                <body style="margin: 0; padding: 0; background-color: #f2f2f2; font-family: Arial, sans-serif; color: #333;">
                <!-- Container principal -->
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 20px auto; background-color: #fff; border-radius: 8px;">
                    <!-- Header -->
                    <tr>
                    <td style="padding: 20px; text-align: center; border-bottom: 1px solid #e0e0e0;">
                        <!-- Logo RUC retiré -->
                        <h2 style="margin: 5px 0 0 0; font-size: 18px; color: #333;">Raise-Up Cameroon France</h2>
                    </td>
                    </tr>

                    <!-- Contenu -->
                    <tr>
                    <td style="padding: 20px;">
                        <p>Bonjour {{name}},</p>
                        <p>L'événement de lancement de <strong>Raise-Up Cameroon en France</strong> va bientôt débuter ! ⏰</p>
                        <p>Préparez-vous pour un moment riche en rencontres et échanges, pensé spécialement pour les jeunes.</p>
                        
                        <table width="100%" style="margin: 20px 0; background-color: #f9f9f9; border: 2px solid #007a3d; border-radius: 8px;">
                        <tr>
                            <td style="padding: 15px;">
                            <strong>Détails de l'événement :</strong><br>
                            📅 <strong>Date</strong> : Samedi 08 février 2025<br>
                            🕐 <strong>Heure</strong> : 13h00 (heure de Paris)<br>
                            📍 <strong>Lieu</strong> : 5 Rue Paul Dautier, 78140 Vélizy-Villacoublay
                            </td>
                        </tr>
                        </table>

                        <p>Nous sommes impatients de vous accueillir et de partager ensemble ce moment fort !</p>
                        <p><strong>L'équipe Raise-Up Cameroon France 🇨🇲✨</strong></p>
                    </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                    <td style="padding: 20px; text-align: center; background: linear-gradient(to right, #ffce00, #ce1126, #007a3d);">
                        <table width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="text-align: center; padding: 10px;">
                            <!-- Instagram -->
                            <a href="https://www.instagram.com/raise_up_cmr/" style="margin: 0 10px; text-decoration: none!important; color: #e0e0e0;"> 
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 132.004 132" xmlns:xlink="http://www.w3.org/1999/xlink">
                                <defs>
                                    <linearGradient id="b">
                                    <stop offset="0" stop-color="#3771c8"/>
                                    <stop offset="0.128" stop-color="#3771c8"/>
                                    <stop offset="1" stop-color="#60f" stop-opacity="0"/>
                                    </linearGradient>
                                    <linearGradient id="a">
                                    <stop offset="0" stop-color="#fd5"/>
                                    <stop offset="0.1" stop-color="#fd5"/>
                                    <stop offset="0.5" stop-color="#ff543e"/>
                                    <stop offset="1" stop-color="#c837ab"/>
                                    </linearGradient>
                                    <radialGradient id="c" cx="158.429" cy="578.088" r="65" xlink:href="#a" gradientUnits="userSpaceOnUse" gradientTransform="matrix(0 -1.98198 1.8439 0 -1031.402 454.004)" fx="158.429" fy="578.088"/>
                                    <radialGradient id="d" cx="147.694" cy="473.455" r="65" xlink:href="#b" gradientUnits="userSpaceOnUse" gradientTransform="matrix(0.17394 0.86872 -3.5818 0.71718 1648.348 -458.493)" fx="147.694" fy="473.455"/>
                                </defs>
                                <path fill="url(#c)" d="M65.03 0C37.888 0 29.95.028 28.407.156c-5.57.463-9.036 1.34-12.812 3.22-2.91 1.445-5.205 3.12-7.47 5.468C4 13.126 1.5 18.394.595 24.656c-.44 3.04-.568 3.66-.594 19.188-.01 5.176 0 11.988 0 21.125 0 27.12.03 35.05.16 36.59.45 5.42 1.3 8.83 3.1 12.56 3.44 7.14 10.01 12.5 17.75 14.5 2.68.69 5.64 1.07 9.44 1.25 1.61.07 18.02.12 34.44.12 16.42 0 32.84-.02 34.41-.1 4.4-.207 6.955-.55 9.78-1.28 7.79-2.01 14.24-7.29 17.75-14.53 1.765-3.64 2.66-7.18 3.065-12.317.088-1.12.125-18.977.125-36.81 0-17.836-.04-35.66-.128-36.78-.41-5.22-1.305-8.73-3.127-12.44-1.495-3.037-3.155-5.305-5.565-7.624C116.9 4 111.64 1.5 105.372.596 102.335.157 101.73.027 86.19 0H65.03z" transform="translate(1.004 1)"/>
                                <path fill="url(#d)" d="M65.03 0C37.888 0 29.95.028 28.407.156c-5.57.463-9.036 1.34-12.812 3.22-2.91 1.445-5.205 3.12-7.47 5.468C4 13.126 1.5 18.394.595 24.656c-.44 3.04-.568 3.66-.594 19.188-.01 5.176 0 11.988 0 21.125 0 27.12.03 35.05.16 36.59.45 5.42 1.3 8.83 3.1 12.56 3.44 7.14 10.01 12.5 17.75 14.5 2.68.69 5.64 1.07 9.44 1.25 1.61.07 18.02.12 34.44.12 16.42 0 32.84-.02 34.41-.1 4.4-.207 6.955-.55 9.78-1.28 7.79-2.01 14.24-7.29 17.75-14.53 1.765-3.64 2.66-7.18 3.065-12.317.088-1.12.125-18.977.125-36.81 0-17.836-.04-35.66-.128-36.78-.41-5.22-1.305-8.73-3.127-12.44-1.495-3.037-3.155-5.305-5.565-7.624C116.9 4 111.64 1.5 105.372.596 102.335.157 101.73.027 86.19 0H65.03z" transform="translate(1.004 1)"/>
                                <path fill="#fff" d="M66.004 18c-13.036 0-14.672.057-19.792.29-5.11.234-8.598 1.043-11.65 2.23-3.157 1.226-5.835 2.866-8.503 5.535-2.67 2.668-4.31 5.346-5.54 8.502-1.19 3.053-2 6.542-2.23 11.65C18.06 51.327 18 52.964 18 66s.058 14.667.29 19.787c.235 5.11 1.044 8.598 2.23 11.65 1.227 3.157 2.867 5.835 5.536 8.503 2.667 2.67 5.345 4.314 8.5 5.54 3.054 1.187 6.543 1.996 11.652 2.23 5.12.233 6.755.29 19.79.29 13.037 0 14.668-.057 19.788-.29 5.11-.234 8.602-1.043 11.656-2.23 3.156-1.226 5.83-2.87 8.497-5.54 2.67-2.668 4.31-5.346 5.54-8.502 1.18-3.053 1.99-6.542 2.23-11.65.23-5.12.29-6.752.29-19.788 0-13.036-.06-14.672-.29-19.792-.24-5.11-1.05-8.598-2.23-11.65-1.23-3.157-2.87-5.835-5.54-8.503-2.67-2.67-5.34-4.31-8.5-5.535-3.06-1.187-6.55-1.996-11.66-2.23-5.12-.233-6.75-.29-19.79-.29zm-4.306 8.65c1.278-.002 2.704 0 4.306 0 12.816 0 14.335.046 19.396.276 4.68.214 7.22.996 8.912 1.653 2.24.87 3.837 1.91 5.516 3.59 1.68 1.68 2.72 3.28 3.592 5.52.657 1.69 1.44 4.23 1.653 8.91.23 5.06.28 6.58.28 19.39s-.05 14.33-.28 19.39c-.214 4.68-.996 7.22-1.653 8.91-.87 2.24-1.912 3.835-3.592 5.514-1.68 1.68-3.275 2.72-5.516 3.59-1.69.66-4.232 1.44-8.912 1.654-5.06.23-6.58.28-19.396.28-12.817 0-14.336-.05-19.396-.28-4.68-.216-7.22-.998-8.913-1.655-2.24-.87-3.84-1.91-5.52-3.59-1.68-1.68-2.72-3.276-3.592-5.517-.657-1.69-1.44-4.23-1.653-8.91-.23-5.06-.276-6.58-.276-19.398s.046-14.33.276-19.39c.214-4.68.996-7.22 1.653-8.912.87-2.24 1.912-3.84 3.592-5.52 1.68-1.68 3.28-2.72 5.52-3.592 1.692-.66 4.233-1.44 8.913-1.655 4.428-.2 6.144-.26 15.09-.27zm29.928 7.97c-3.18 0-5.76 2.577-5.76 5.758 0 3.18 2.58 5.76 5.76 5.76 3.18 0 5.76-2.58 5.76-5.76 0-3.18-2.58-5.76-5.76-5.76zm-25.622 6.73c-13.613 0-24.65 11.037-24.65 24.65 0 13.613 11.037 24.645 24.65 24.645C79.617 90.645 90.65 79.613 90.65 66S79.616 41.35 66.003 41.35zm0 8.65c8.836 0 16 7.163 16 16 0 8.836-7.164 16-16 16-8.837 0-16-7.164-16-16 0-8.837 7.163-16 16-16z"/>
                                </svg>
                                Instagram
                            </a>
                            <!-- LinkedIn -->
                            <a href="https://www.linkedin.com/company/raise-up-cameroon" style="margin: 0 10px; text-decoration: none!important; color: #e0e0e0;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 72 72">
                                <g fill="none" fill-rule="evenodd">
                                    <path d="M8,72 L64,72 C68.418278,72 72,68.418278 72,64 L72,8 C72,3.581722 68.418278,0 64,0 L8,0 C3.581722,0 0,3.581722 0,8 L0,64 C0,68.418278 3.581722,72 8,72 Z" fill="#007EBB"/>
                                    <path d="M62,62 L51.315625,62 L51.315625,43.8021149 C51.315625,38.8127542 49.4197917,36.0245323 45.4707031,36.0245323 C41.1746094,36.0245323 38.9300781,38.9261103 38.9300781,43.8021149 L38.9300781,62 L28.6333333,62 L28.6333333,27.3333333 L38.9300781,27.3333333 L38.9300781,32.0029283 C38.9300781,32.0029283 42.0260417,26.2742151 49.3825521,26.2742151 C56.7356771,26.2742151 62,30.7644705 62,40.051212 L62,62 Z 
                                    M16.349349,22.7940133 C12.8420573,22.7940133 10,19.9296567 10,16.3970067 C10,12.8643566 12.8420573,10 16.349349,10 C19.8566406,10 22.6970052,12.8643566 22.6970052,16.3970067 C22.6970052,19.9296567 19.8566406,22.7940133 16.349349,22.7940133 Z 
                                    M11.0325521,62 L21.769401,62 L21.769401,27.3333333 L11.0325521,27.3333333 L11.0325521,62 Z" fill="#FFF"/>
                                </g>
                                </svg>
                                LinkedIn
                            </a>
                            <!-- Facebook -->
                            <a href="https://web.facebook.com/p/Raise-Up-Cameroon-61551708985243/" style="color: #e0e0e0; margin: 0 10px; text-decoration: none!important;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 14222 14222" version="1.1" xml:space="preserve" xmlns:xlink="http://www.w3.org/1999/xlink" style="shape-rendering:geometricPrecision; text-rendering:geometricPrecision; image-rendering:optimizeQuality; fill-rule:evenodd; clip-rule:evenodd">
                                <defs>
                                    <style type="text/css">
                                    <![CDATA[
                                        .fil0 {fill:#1977F3;fill-rule:nonzero}
                                        .fil1 {fill:#FEFEFE;fill-rule:nonzero}
                                    ]]>
                                    </style>
                                </defs>
                                <g id="Layer_x0020_1">
                                    <metadata id="CorelCorpID_0Corel-Layer"/>
                                    <path class="fil0" d="M14222 7111c0,-3927 -3184,-7111 -7111,-7111 -3927,0 -7111,3184 -7111,7111 0,3549 2600,6491 6000,7025l0 -4969 -1806 0 0 -2056 1806 0 0 -1567c0,-1782 1062,-2767 2686,-2767 778,0 1592,139 1592,139l0 1750 -897 0c-883,0 -1159,548 -1159,1111l0 1334 1972 0 -315 2056 -1657 0 0 4969c3400,-533 6000,-3475 6000,-7025z"/>
                                    <path class="fil1" d="M9879 9167l315 -2056 -1972 0 0 -1334c0,-562 275,-1111 1159,-1111l897 0 0 -1750c0,0 -814,-139 -1592,-139 -1624,0 -2686,984 -2686,2767l0 1567 -1806 0 0 2056 1806 0 0 4969c362,57 733,86 1111,86 378,0 749,-30 1111,-86l0 -4969 1657 0z"/>
                                </g>
                                </svg>
                                Facebook
                            </a>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: center; color: #fff; font-size: 12px; padding-top: 10px;">
                            © Raise-Up Cameroun 2025<br>
                            Apporte ton fruit pour le développement de ton pays
                            </td>
                        </tr>
                        </table>
                    </td>
                    </tr>
                </table>
                </body>
                </html>
                `,

            template3: `<p>Bonjour {{name}},</p>
<p>L'événement de lancement de <strong>Raise-Up Cameroon en France</strong> va bientôt débuter ! ⏰</p>
<p>Préparez-vous pour une journée riche en rencontres et en échanges.</p>
<p>📅 <strong>Date</strong> : Samedi 08 février 2025<br>
🕐 <strong>Heure</strong> : Bientôt<br>
📍 <strong>Lieu</strong> : 5 Rue Paul Dautier, 78140 Vélizy-Villacoublay</p>
<p>Nous vous attendons avec impatience.</p>
<p><strong>L’équipe Raise-Up Cameroon France 🇨🇲✨</strong></p>`,

            template4: `<!DOCTYPE html>
                <html lang="fr">
                <head>
                    <meta charset="UTF-8">
                    <title>Retour sur le lancement de RUC France &amp; prochaines étapes !</title>
                </head>
                <body style="margin: 0; padding: 0; background-color: #f2f2f2; font-family: Arial, sans-serif; color: #333;">
                    <!-- Container principal -->
                    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 20px auto; background-color: #fff; border-radius: 8px;">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 20px; text-align: center; border-bottom: 1px solid #e0e0e0;">
                        <!-- Vous pouvez ajouter votre logo ici si nécessaire -->
                        <h2 style="margin: 5px 0 0 0; font-size: 18px; color: #333;">RUC - France</h2>
                        </td>
                    </tr>

                    <!-- Contenu -->
                    <tr>
                        <td style="padding: 20px;">
                        <p>Bonjour {{name}},</p>
                        <p>Nous tenons à vous remercier pour votre implication et votre engagement lors du lancement de Raise-Up Cameroon en France !</p>
                        <p>L’événement a été un véritable succès, marqué par des échanges riches, des idées innovantes et une belle énergie collective. Ensemble, nous avons identifié des problématiques clés et réfléchi à des solutions concrètes pour contribuer au développement du Cameroun.</p>
                        <p>La suite ?</p>
                        <p>Nous préparons déjà nos prochaines activités. Restez connectés et rejoignez-nous pour continuer à bâtir ensemble l’avenir du Cameroun.</p>
                        <p>📸 Revivez l’événement en images sur toutes nos pages.</p>
                        <p>À très bientôt !</p>
                        <p><strong>L’équipe RUC de France 🇨🇲</strong></p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px; text-align: center; background: linear-gradient(to left, #ffce00, #ce1126, #007a3d);">
                        <table width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                            <td style="text-align: center; padding: 10px;">
                                <!-- Instagram -->
                                <a href="https://www.instagram.com/raise_up_cmr/" style="margin: 0 10px; text-decoration: none!important; color: #e0e0e0;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 132.004 132" xmlns:xlink="http://www.w3.org/1999/xlink">
                                    <defs>
                                    <linearGradient id="c">
                                        <stop offset="0" stop-color="#fd5"/>
                                        <stop offset="0.1" stop-color="#fd5"/>
                                        <stop offset="0.5" stop-color="#ff543e"/>
                                        <stop offset="1" stop-color="#c837ab"/>
                                    </linearGradient>
                                    <radialGradient id="d" cx="158.429" cy="578.088" r="65" xlink:href="#c" gradientUnits="userSpaceOnUse" gradientTransform="matrix(0 -1.98198 1.8439 0 -1031.402 454.004)" fx="158.429" fy="578.088"/>
                                    </defs>
                                    <path fill="url(#d)" d="M65.03 0C37.888 0 29.95.028 28.407.156c-5.57.463-9.036 1.34-12.812 3.22-2.91 1.445-5.205 3.12-7.47 5.468C4 13.126 1.5 18.394.595 24.656c-.44 3.04-.568 3.66-.594 19.188-.01 5.176 0 11.988 0 21.125 0 27.12.03 35.05.16 36.59.45 5.42 1.3 8.83 3.1 12.56 3.44 7.14 10.01 12.5 17.75 14.5 2.68.69 5.64 1.07 9.44 1.25 1.61.07 18.02.12 34.44.12 16.42 0 32.84-.02 34.41-.1 4.4-.207 6.955-.55 9.78-1.28 7.79-2.01 14.24-7.29 17.75-14.53 1.765-3.64 2.66-7.18 3.065-12.317.088-1.12.125-18.977.125-36.81 0-17.836-.04-35.66-.128-36.78-.41-5.22-1.305-8.73-3.127-12.44-1.495-3.037-3.155-5.305-5.565-7.624C116.9 4 111.64 1.5 105.372.596 102.335.157 101.73.027 86.19 0H65.03z"/>
                                    <path fill="#fff" d="M66.004 18c-13.036 0-14.672.057-19.792.29-5.11.234-8.598 1.043-11.65 2.23-3.157 1.226-5.835 2.866-8.503 5.535-2.67 2.668-4.31 5.346-5.54 8.502-1.19 3.053-2 6.542-2.23 11.65C18.06 51.327 18 52.964 18 66s.058 14.667.29 19.787c.235 5.11 1.044 8.598 2.23 11.65 1.227 3.157 2.867 5.835 5.536 8.503 2.667 2.67 5.345 4.314 8.5 5.54 3.054 1.187 6.543 1.996 11.652 2.23 5.12.233 6.755.29 19.79.29 13.037 0 14.668-.057 19.788-.29 5.11-.234 8.602-1.043 11.656-2.23 3.156-1.226 5.83-2.87 8.497-5.54 2.67-2.668 4.31-5.346 5.54-8.502 1.18-3.053 1.99-6.542 2.23-11.65.23-5.12.29-6.752.29-19.788 0-13.036-.06-14.672-.29-19.792-.24-5.11-1.05-8.598-2.23-11.65-1.23-3.157-2.87-5.835-5.54-8.503-2.67-2.67-5.34-4.31-8.5-5.535-3.06-1.187-6.55-1.996-11.66-2.23-5.12-.233-6.75-.29-19.79-.29zm-4.306 8.65c1.278-.002 2.704 0 4.306 0 12.816 0 14.335.046 19.396.276 4.68.214 7.22.996 8.912 1.653 2.24.87 3.837 1.91 5.516 3.59 1.68 1.68 2.72 3.28 3.592 5.52.657 1.69 1.44 4.23 1.653 8.91.23 5.06.28 6.58.28 19.39s-.05 14.33-.28 19.39c-.214 4.68-.996 7.22-1.653 8.91-.87 2.24-1.912 3.835-3.592 5.514-1.68 1.68-3.275 2.72-5.516 3.59-1.69.66-4.232 1.44-8.912 1.654-5.06.23-6.58.28-19.396.28-12.817 0-14.336-.05-19.396-.28-4.68-.216-7.22-.998-8.913-1.655-2.24-.87-3.84-1.91-5.52-3.59-1.68-1.68-2.72-3.276-3.592-5.517-.657-1.69-1.44-4.23-1.653-8.91-.23-5.06-.276-6.58-.276-19.398s.046-14.33.276-19.39c.214-4.68.996-7.22 1.653-8.912.87-2.24 1.912-3.84 3.592-5.52 1.68-1.68 3.28-2.72 5.52-3.592 1.692-.66 4.233-1.44 8.913-1.655 4.428-.2 6.144-.26 15.09-.27zm29.928 7.97c-3.18 0-5.76 2.577-5.76 5.758 0 3.18 2.58 5.76 5.76 5.76 3.18 0 5.76-2.58 5.76-5.76 0-3.18-2.58-5.76-5.76-5.76zm-25.622 6.73c-13.613 0-24.65 11.037-24.65 24.65 0 13.613 11.037 24.645 24.65 24.645C79.617 90.645 90.65 79.613 90.65 66S79.616 41.35 66.003 41.35zm0 8.65c8.836 0 16 7.163 16 16 0 8.836-7.164 16-16 16-8.837 0-16-7.164-16-16 0-8.837 7.163-16 16-16z"/>
                                </svg>
                                Instagram
                                </a>
                                <!-- LinkedIn -->
                                <a href="https://www.linkedin.com/company/raise-up-cameroon" style="margin: 0 10px; text-decoration: none!important; color: #e0e0e0;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 72 72">
                                    <g fill="none" fill-rule="evenodd">
                                    <path d="M8,72 L64,72 C68.418278,72 72,68.418278 72,64 L72,8 C72,3.581722 68.418278,0 64,0 L8,0 C3.581722,0 0,3.581722 0,8 L0,64 C0,68.418278 3.581722,72 8,72 Z" fill="#007EBB"/>
                                    <path d="M62,62 L51.315625,62 L51.315625,43.8021149 C51.315625,38.8127542 49.4197917,36.0245323 45.4707031,36.0245323 C41.1746094,36.0245323 38.9300781,38.9261103 38.9300781,43.8021149 L38.9300781,62 L28.6333333,62 L28.6333333,27.3333333 L38.9300781,27.3333333 L38.9300781,32.0029283 C38.9300781,32.0029283 42.0260417,26.2742151 49.3825521,26.2742151 C56.7356771,26.2742151 62,30.7644705 62,40.051212 L62,62 Z 
                                    M16.349349,22.7940133 C12.8420573,22.7940133 10,19.9296567 10,16.3970067 C10,12.8643566 12.8420573,10 16.349349,10 C19.8566406,10 22.6970052,12.8643566 22.6970052,16.3970067 C22.6970052,19.9296567 19.8566406,22.7940133 16.349349,22.7940133 Z 
                                    M11.0325521,62 L21.769401,62 L21.769401,27.3333333 L11.0325521,27.3333333 L11.0325521,62 Z" fill="#FFF"/>
                                    </g>
                                </svg>
                                LinkedIn
                                </a>
                                <!-- Facebook -->
                                <a href="https://web.facebook.com/p/Raise-Up-Cameroon-61551708985243/" style="margin: 0 10px; text-decoration: none!important; color: #e0e0e0;">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 14222 14222" version="1.1" xml:space="preserve" xmlns:xlink="http://www.w3.org/1999/xlink" style="shape-rendering: geometricPrecision; text-rendering: geometricPrecision; image-rendering: optimizeQuality; fill-rule:evenodd; clip-rule:evenodd;">
                                    <defs>
                                    <style type="text/css">
                                        <![CDATA[
                                        .fil0 {fill:#1977F3;fill-rule:nonzero}
                                        .fil1 {fill:#FEFEFE;fill-rule:nonzero}
                                        ]]>
                                    </style>
                                    </defs>
                                    <g id="Layer_x0020_1">
                                    <metadata id="CorelCorpID_0Corel-Layer"/>
                                    <path class="fil0" d="M14222 7111c0,-3927 -3184,-7111 -7111,-7111 -3927,0 -7111,3184 -7111,7111 0,3549 2600,6491 6000,7025l0 -4969 -1806 0 0 -2056 1806 0 0 -1567c0,-1782 1062,-2767 2686,-2767 778,0 1592,139 1592,139l0 1750 -897 0c-883,0 -1159,548 -1159,1111l0 1334 1972 0 -315 2056 -1657 0 0 4969c3400,-533 6000,-3475 6000,-7025z"/>
                                    <path class="fil1" d="M9879 9167l315 -2056 -1972 0 0 -1334c0,-562 275,-1111 1159,-1111l897 0 0 -1750c0,0 -814,-139 -1592,-139 -1624,0 -2686,984 -2686,2767l0 1567 -1806 0 0 2056 1806 0 0 4969c362,57 733,86 1111,86 378,0 749,-30 1111,-86l0 -4969 1657 0z"/>
                                    </g>
                                </svg>
                                Facebook
                                </a>
                            </td>
                            </tr>
                            <tr>
                            <td style="text-align: center; color: #fff; font-size: 12px; padding-top: 10px;">
                                © Raise-Up Cameroun 2025<br>
                                <i>« Apporte ton fruit pour le développement de ton pays! »</i>
                            </td>
                            </tr>
                        </table>
                        </td>
                    </tr>
                    </table>
                </body>
                </html>
`
        };

        // Initialisation par défaut : on sélectionne template1
        document.getElementById('message').value = templates.template1;

        // Fonction de sélection d'un template
        function selectTemplate(templateKey, btn) {
            // Met à jour le textarea avec le template choisi
            document.getElementById('message').value = templates[templateKey];
            document.getElementById('subject').value = subjects[templateKey];
            
            showToast("Template sélectionné !");
            // Gestion de la classe active pour le menu tabulé
            var tabs = document.getElementsByClassName('tab-btn');
            for (var i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove('active');
            }
            btn.classList.add('active');
        }

        // Fonction de copie d'un champ dans le presse-papier
        function copyField(fieldId) {
            var field = document.getElementById(fieldId);
            if (field) {
                field.select();
                field.setSelectionRange(0, 99999);
                document.execCommand("copy");
                showToast("Contenu copié !");
            }
        }
        // Bascule l'affichage du mot de passe
        function togglePassword() {
            var passwordField = document.getElementById("smtp_password");
            var eyeIcon = document.getElementById("eyeIcon");
            if (passwordField.type === "password") {
                passwordField.type = "text";
                eyeIcon.classList.remove("fa-eye");
                eyeIcon.classList.add("fa-eye-slash");
            } else {
                passwordField.type = "password";
                eyeIcon.classList.remove("fa-eye-slash");
                eyeIcon.classList.add("fa-eye");
            }
        }
        // Fonction pour afficher une notification toast
        function showToast(message) {
            var toast = document.getElementById("toast");
            toast.textContent = message;
            toast.className = "toast show";
            setTimeout(function() {
                toast.className = toast.className.replace("show", "");
            }, 3000);
        }
    </script>
</body>
</html>
