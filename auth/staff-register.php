<?php
// Staff accounts are created by administrators. Keep old signup links usable.
header('Location: login.php', true, 303);
exit;
