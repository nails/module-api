<?php
if (class_exists('ApiRouter') && ApiRouter::getOutputFormat() === 'JSON') {
    header('Content-Type: application/json');
} else {
    header('Content-Type: text/html');
}
?>
{
    "status": 503,
    "error": "Down for maintenance"
}
