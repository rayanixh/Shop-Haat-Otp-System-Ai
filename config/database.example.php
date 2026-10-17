<?php
/**
 * Template for config/database.php.
 *
 * The installer writes the real file on the server. Copy this one into place
 * only when you are setting the connection up by hand, and never commit the
 * result: config/database.php is listed in .gitignore because it holds the
 * database password in plain text.
 *
 * On cPanel the values look like:
 *   host = localhost
 *   name = cpuser_shophaat
 *   user = cpuser_shopuser
 *
 * Grant this account the privileges the application actually needs on its own
 * database only: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP.
 * It does not need global privileges, and it must not be the MySQL root user.
 */
return [
    'host'    => 'localhost',
    'name'    => 'your_database_name',
    'user'    => 'your_database_user',
    'pass'    => 'your_database_password',
    'port'    => 3306,
    'charset' => 'utf8mb4',
];
