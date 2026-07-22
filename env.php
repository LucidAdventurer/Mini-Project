<?php

return [
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_USER' => getenv('DB_USER') ?: 'postgres.enteveefrrxsuhlxdcfu',
    'DB_PASS' => getenv('DB_PASS') ?: '654556AT654556',
    'DB_NAME' => getenv('DB_NAME') ?: 'postgres',
    'DB_PORT' => getenv('DB_PORT') ?: '5432',
    'APP_ENV' => getenv('APP_ENV') ?: 'production',

    // SMTP
    'SMTP_HOST' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'SMTP_PORT' => getenv('SMTP_PORT') ?: 587,
    'SMTP_USER' => getenv('SMTP_USER') ?: 'tempminiprojects6@gmail.com',
    'SMTP_PASS' => getenv('SMTP_PASS') ?: 'nbqg phbf gfug twmi',
    'SMTP_FROM' => getenv('SMTP_FROM') ?: 'tempminiprojects6@gmail.com',
    'SMTP_FROM_NAME' => 'PTA Platform',

    // Cloudinary
     'CLOUDINARY_CLOUD_NAME' => getenv('CLOUDINARY_CLOUD_NAME') ?: 'dmysg5azm',
    'CLOUDINARY_API_KEY'    => getenv('CLOUDINARY_API_KEY') ?: '272119994945228',
    'CLOUDINARY_API_SECRET' => getenv('CLOUDINARY_API_SECRET') ?: 'y4YiBp8FDwaUmIUv5TrsCEapeXQ',
];