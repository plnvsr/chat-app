<?php
/*
Grouped table creations here for better understanding of the project.
Tables are kept as simple as possible.
*/

// Connect to the SQLite database
$db = new SQLite3('chat.db');

// Create tables
$db->exec('CREATE TABLE IF NOT EXISTS users 
            (id INTEGER PRIMARY KEY, 
            username TEXT NOT NULL)');

$db->exec('CREATE TABLE IF NOT EXISTS groups 
            (id INTEGER PRIMARY KEY, 
            groupname TEXT NOT NULL)');

$db->exec('CREATE TABLE IF NOT EXISTS messages 
                (id INTEGER PRIMARY KEY, 
                group_id INTEGER, 
                message TEXT NOT NULL, 
                user_id INTEGER,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id),
                FOREIGN KEY (group_id) REFERENCES groups(id))');

$db->exec('CREATE TABLE IF NOT EXISTS users_groups 
            (id INTEGER PRIMARY KEY,
            user_id INTEGER NOT NULL,
            group_id INTEGER NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (group_id) REFERENCES groups(id))');





