<?php

return [
    'factories' => [
        'ApiResponse' => function () {
            if (class_exists('\App\Api\Factory\ApiResponse')) {
                return new \App\Api\Factory\ApiResponse();
            } else {
                return new \Nails\Api\Factory\ApiResponse();
            }
        },
    ],
];
