# RUC Bulk Mailer

RUC Bulk Mailer est une application web permettant d'envoyer des emails en masse à partir de fichiers CSV ou de Google Sheets. Elle utilise PHPMailer pour l'envoi des emails et l'API Google Sheets pour récupérer les données des feuilles de calcul.

## Prérequis

- PHP 7.4 ou supérieur
- Composer
- Un compte Google avec accès à l'API Google Sheets et Google Drive
- Un fichier `credentials.json` pour l'authentification avec l'API Google

## Installation

1. Clonez le dépôt :

    ```sh
    git clone https://github.com/votre-utilisateur/ruc-bulk-mailer.git
    cd ruc-bulk-mailer
    ```

2. Installez les dépendances avec Composer :

    ```sh
    composer install
    ```

3. Placez votre fichier [credentials.json](http://_vscodecontentref_/1) dans le répertoire racine du projet.

4. Configurez votre serveur web pour pointer vers le fichier [index.php](http://_vscodecontentref_/2).

## Utilisation

1. Accédez à l'application via votre navigateur web.

2. Choisissez la source des données (fichier CSV ou Google Sheet).

3. Remplissez les champs requis, y compris la configuration SMTP pour l'envoi des emails.

4. Cliquez sur "Envoyer les mails" pour démarrer l'envoi en masse.

## Configuration SMTP

Assurez-vous de remplir correctement les champs de configuration SMTP :

- **Hôte SMTP** : L'adresse du serveur SMTP (ex : `smtp.gmail.com`).
- **Port SMTP** : Le port du serveur SMTP (ex : `587`).
- **Encryption SMTP** : Le type de chiffrement (ex : `tls`).
- **Utilisateur SMTP** : Votre adresse email.
- **Mot de passe SMTP** : Le mot de passe de votre compte email.

## Développement

Pour contribuer au développement de ce projet :

1. Forkez le dépôt.
2. Créez une branche pour votre fonctionnalité (`git checkout -b feature/ma-fonctionnalite`).
3. Commitez vos modifications (`git commit -am 'Ajout de ma fonctionnalité'`).
4. Poussez votre branche (`git push origin feature/ma-fonctionnalite`).
5. Ouvrez une Pull Request.

## Licence

Ce projet est sous licence MIT. Voir le fichier LICENSE pour plus de détails.


![Image](./Documentations/images/RUC%20Bulk%20Mailer%20landscape.png)