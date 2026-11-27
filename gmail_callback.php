<?php
// Google sends the user back here after consent. Hand over to the normal app router.
$_GET['p'] = 'gmail_callback';
require __DIR__ . '/index.php';
