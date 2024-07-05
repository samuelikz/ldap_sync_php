# ldap_sync_php
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    displayName VARCHAR(255) NOT NULL,
    userPrincipalName VARCHAR(255) NOT NULL UNIQUE,
    mail VARCHAR(255) NOT NULL,
    department VARCHAR(255) NOT NULL,
    userPassword VARCHAR(255) NOT NULL
);
