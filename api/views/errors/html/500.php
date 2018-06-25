<?php
if (class_exists('ApiRouter') && ApiRouter::getOutputFormat() === 'JSON') {
    header('Content-Type: application/json');
} else {
    header('Content-Type: text/html');
}
?>
{
    "status": 500,
    "error": "Sorry, an error occurred from which we could not recover. The technical team have been informed. We apologise for the inconvenience."
}
