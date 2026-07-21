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
    ],
];
