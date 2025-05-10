<?php
// SharedPeopleParser.php

require 'vendor/autoload.php';

class SharedPeopleParser {
    private $spreadsheetId;
    private $client;
    private $driveService;
    private $people = [];
    private $parsed = false;

    public function __construct(string $spreadsheetId) {
        $this->spreadsheetId = $spreadsheetId;
        $this->client = new Google_Client();
        $this->client->setApplicationName('RUC Bulk Mailer');
        // Scope pour accéder aux métadonnées du Drive
        $this->client->setScopes(['https://www.googleapis.com/auth/drive.metadata.readonly']);
        if (!file_exists(__DIR__ . '/credentials.json')) {
            throw new Exception('credentials.json introuvable dans : ' . __DIR__);
        }
        $this->client->setAuthConfig(__DIR__ . '/credentials.json');
        $this->driveService = new Google_Service_Drive($this->client);
    }

    public function parse(): array {
        if ($this->parsed) {
            return $this->people;
        }
        try {
            $permissions = $this->driveService->permissions->listPermissions($this->spreadsheetId, [
                'fields' => 'permissions(id, emailAddress, displayName, role, type)'
            ]);
            foreach ($permissions->getPermissions() as $permission) {
                // On ne conserve que les permissions de type "user" disposant d'une adresse e-mail
                if ($permission->getType() === 'user' && $permission->getEmailAddress()) {
                    $this->people[] = [
                        "displayName"=>$permission->getDisplayName(),   // index 0 : Nom
                        "emailAddress"=>$permission->getEmailAddress(),     // index 1 : E-mail
                        "role"=>$permission->getRole()
                        // Vous pouvez ajouter d'autres informations si besoin
                    ];
                }
            }
            $this->parsed = true;
            return $this->people;
        } catch (Exception $e) {
            throw new Exception('Erreur lors de la récupération des personnes partagées : ' . $e->getMessage());
        }
    }

    // Pour rester cohérent avec les autres parsers
    public function getHeaders(): array {
        return ['name', 'email'];
    }

    public function getPeople(): array {
        return $this->people;
    }

    public function getSpreadsheetId(): string {
        return $this->spreadsheetId;
    }

}

$sharedPeopleParser = new SharedPeopleParser($_GET['spreadsheet_id']);
try {
    $people = $sharedPeopleParser->parse();
    header('Content-Type: application/json');
    echo json_encode($people);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
