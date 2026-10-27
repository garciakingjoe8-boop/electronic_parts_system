<?php
require_once __DIR__ . '/includes/functions.php';
end_session();                                   // clears the session data AND the session cookie
header('Location: login.php');
exit;
