<?php

return [
    'paloma' => [
        'endpoint' => env('PALOMA_ENDPOINT'),
    ],
    'opencart' => [
        'sql_dump' => env('OPENCART_SQL_DUMP'),
        'db_prefix' => env('OPENCART_DB_PREFIX', 'oc_'),
        'project_root' => env('OPENCART_PROJECT_ROOT') ?: base_path('..'),
        'matching_report_path' => env('OPENCART_MATCHING_REPORT_PATH', storage_path('app/reports/opencart-matching-report.csv')),
    ],
    'gsc' => [
        'property_url' => env('GSC_PROPERTY_URL'),
        'credentials_path' => env('GSC_CREDENTIALS_PATH'),
        'client_email' => env('GSC_CLIENT_EMAIL'),
        'auth_mode' => env('GSC_AUTH_MODE', 'service_account'),
        'sync_chunk_days' => (int) env('GSC_SYNC_CHUNK_DAYS', 7),
    ],
    'kaspi' => [
        'merchant_code' => env('KASPI_MERCHANT_CODE'),
        'city_code' => env('KASPI_CITY_CODE', '750000000'),
        'button_template' => env('KASPI_BUTTON_TEMPLATE', 'button'),
        'enrichment_enabled' => (bool) env('KASPI_ENRICHMENT_ENABLED', false),
        'rate_limit_seconds' => (int) env('KASPI_RATE_LIMIT_SECONDS', 10),
        'dry_run' => (bool) env('KASPI_DRY_RUN', true),
        'widget_script_url' => env('KASPI_WIDGET_SCRIPT_URL', 'https://kaspi.kz/kaspibutton/widget/ks-wi_ext.js'),
        'production_import_token' => env('KASPI_IMPORT_API_TOKEN'),
        'production_api_url' => env('KASPI_PRODUCTION_API_URL'),
        'production_candidates_url' => env('KASPI_PRODUCTION_CANDIDATES_URL'),
        'production_api_token' => env('KASPI_PRODUCTION_API_TOKEN'),
        'production_import_rate_limit' => (int) env('KASPI_IMPORT_API_RATE_LIMIT', 30),
        'production_payload_max_bytes' => (int) env('KASPI_IMPORT_PAYLOAD_MAX_BYTES', 262144),
        'node_binary' => env('KASPI_NODE_BINARY', 'node'),
        'image_allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', env('KASPI_IMAGE_ALLOWED_HOSTS', 'resources.cdn-kaspi.kz,kaspi.kz'))))),
        'image_connect_timeout' => (int) env('KASPI_IMAGE_CONNECT_TIMEOUT', 5),
        'image_timeout' => (int) env('KASPI_IMAGE_TIMEOUT', 15),
        'image_max_bytes' => (int) env('KASPI_IMAGE_MAX_BYTES', 5242880),
    ],
];
