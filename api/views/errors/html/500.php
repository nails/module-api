<?php
if (\Nails\Environment::is(\Nails\Environment::ENV_PROD)) {
    if (class_exists('ApiRouter') && ApiRouter::getOutputFormat() === 'JSON') {
        header('Content-Type: application/json');
    } else {
        header('Content-Type: text/html');
    }

    echo json_encode([
        'status' => 500,
        'error'  => 'Sorry, an error occurred from which we could not recover. The technical team have been informed. We apologise for the inconvenience.',
    ]);
} else {
    require NAILS_COMMON_PATH . 'views/errors/html/500.php';
}
