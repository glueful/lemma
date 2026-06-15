<?php

/**
 * Documentation Generation Configuration
 *
 * Centralizes all settings for OpenAPI/Swagger documentation generation.
 * Used by ResourceRouteExpander, DocGenerator, CommentsDocGenerator, and OpenApiGenerator.
 */

$root = dirname(__DIR__);

return [
    /*
    |--------------------------------------------------------------------------
    | Documentation Enabled
    |--------------------------------------------------------------------------
    |
    | Controls whether API documentation is enabled. Automatically disabled
    | in production for security unless explicitly enabled.
    |
    */
    'enabled' => env('API_DOCS_ENABLED', env('APP_ENV') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | OpenAPI Specification Version
    |--------------------------------------------------------------------------
    |
    | The OpenAPI specification version to use for generated documentation.
    | Supported: "3.0.0", "3.0.3", "3.1.0"
    |
    | Key differences in 3.1.0:
    | - Uses JSON Schema draft 2020-12 (full alignment)
    | - Nullable types use array syntax: type: ["string", "null"]
    | - License supports SPDX identifier field
    | - $ref can have sibling keywords
    |
    */
    'openapi_version' => env('OPENAPI_VERSION', '3.1.0'),

    /*
    |--------------------------------------------------------------------------
    | API Information
    |--------------------------------------------------------------------------
    |
    | Metadata about your API that appears in the generated documentation.
    | These values populate the "info" section of the OpenAPI spec.
    |
    | TIP: After adding or modifying endpoints in your code, regenerate docs:
    |   php glueful generate:openapi -f -u
    |
    | If changes don't appear, try:
    |   1. Hard refresh the browser (Cmd+Shift+R / Ctrl+Shift+R)
    |   2. Clear browser cache
    |   3. Run with --clean flag: php glueful generate:openapi -f -u --clean
    |
    */
    'info' => [
        'title' => env('API_TITLE', 'Lemma CMS API'),
        'description' => env('API_DESCRIPTION', implode("\n", [
            'The Lemma headless CMS API.',
            '',
            '## Authentication',
            '',
            '- **Delivery API** (`/v1/content/*`) — send an API key in the `X-API-Key` header. '
                . 'The key must carry the `read:content` scope. Keys are environment-prefixed '
                . '(`gf_live_*` / `gf_test_*`) and provisioned out of band.',
            '- **Admin API** (`/v1/admin/*`) — send a bearer JWT in the `Authorization` header '
                . '(`Authorization: Bearer <token>`). Each route also enforces a `lemma.*` '
                . 'permission (named in the operation description). Obtain a token from your '
                . 'Glueful auth endpoint (e.g. `POST /v1/auth/login`).',
            '- **Preview** (`GET /v1/preview/{token}`) — unauthenticated: the signed, '
                . 'short-lived token in the path is itself the capability.',
            '',
            'Only published content is ever returned by the delivery API; drafts are reachable '
                . 'only through a valid preview token.',
        ])),
        'version' => env('API_VERSION', '1') . '.0.0',
        'contact' => [
            'name' => env('API_CONTACT_NAME', ''),
            'email' => env('API_CONTACT_EMAIL', ''),
            'url' => env('API_CONTACT_URL', ''),
        ],
        'license' => [
            'name' => env('API_LICENSE_NAME', ''),
            'url' => env('API_LICENSE_URL', ''),
            // SPDX identifier for OpenAPI 3.1+ (e.g., 'MIT', 'Apache-2.0')
            'identifier' => env('API_LICENSE_IDENTIFIER', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Configuration
    |--------------------------------------------------------------------------
    |
    | Define the server URLs that appear in the generated documentation.
    | Multiple servers can be defined for different environments.
    |
    */
    'servers' => [
        [
            // Production first so SDK/codegen defaults to the live base URL.
            'url' => env('API_SERVER_URL', 'https://api.getlemma.dev'),
            'description' => 'Production',
        ],
        [
            'url' => 'http://localhost:8000',
            'description' => 'Local development',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Paths
    |--------------------------------------------------------------------------
    |
    | Paths where generated documentation files are stored.
    | All paths are relative to base_path() unless absolute.
    |
    */
    'paths' => [
        // Main documentation output directory
        'output' => $root . '/docs',

        // Final OpenAPI specification file location
        'openapi' => $root . '/docs/openapi.json',

        // JSON definitions for route-based documentation
        'route_definitions' => $root . '/docs/json-definitions/routes',

        // JSON definitions for extension documentation
        'extension_definitions' => $root . '/docs/json-definitions/extensions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Paths
    |--------------------------------------------------------------------------
    |
    | Paths to scan for routes when generating documentation.
    | Extensions are discovered via Composer packages through ExtensionManager.
    |
    */
    'sources' => [
        // Directory containing project route files
        'routes' => $root . '/routes',

        // Include framework routes in documentation generation.
        // Default false: this spec documents the Lemma CMS API only. The framework's
        // own auth/blobs/health routes are documented upstream; authentication is
        // summarized in info.description instead. Set true to include them.
        'include_framework_routes' => env('API_DOCS_INCLUDE_FRAMEWORK_ROUTES', false),

        // Framework routes directory (auto-detected from vendor or local path)
        'framework_routes' => null, // Will be resolved at runtime

        /*
        |--------------------------------------------------------------------------
        | Route File Prefixes
        |--------------------------------------------------------------------------
        |
        | Map route files to their URL path prefixes. Routes in these files will
        | have the prefix prepended to their documented paths.
        |
        | Format: 'filename.php' => '/prefix' or '' for no prefix
        |
        */
        'route_prefixes' => [
            // Lemma route files already carry absolute /v1/... paths in their
            // @route tags, so they take no prefix injection.
            'lemma_content.php' => '',
            'lemma_admin.php' => '',
            'lemma_preview.php' => '',

            // Framework routes - no version prefix
            'health.php' => '',
            'docs.php' => '',

            // Framework auth routes - versioned
            'auth.php' => '/v1',
            'resource.php' => '/v1',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    |
    | Default security schemes to include in the generated documentation.
    | These define authentication methods for your API.
    |
    */
    'security_schemes' => [
        'BearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => 'JWT bearer token. Used by the admin authoring API (/v1/admin/*), '
                . 'combined with a per-route lemma_permission RBAC check.',
        ],
        'ApiKeyAuth' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-API-Key',
            'description' => 'Environment-prefixed API key (gf_live_* / gf_test_*) sent in the '
                . 'X-API-Key header. The public delivery API (/v1/content/*) requires a key '
                . 'carrying the read:content scope. (OpenAPI apiKey schemes cannot express '
                . 'scopes natively; the required scope is noted in each operation description.)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware → Security Scheme Map
    |--------------------------------------------------------------------------
    |
    | Maps route middleware names to the security schemes above. Each route
    | resolves its security from the middleware it carries: the admin API uses
    | `auth` (BearerAuth) and the delivery API uses `api_key` (ApiKeyAuth), so
    | per-operation security is emitted natively. scripts/openapi-finalize-
    | security.php only refines the public preview route's explicit no-auth.
    |
    */
    'middleware_map' => [
        'auth' => ['BearerAuth'],
        'api_key' => ['ApiKeyAuth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation Options
    |--------------------------------------------------------------------------
    |
    | Options that control how documentation is generated.
    |
    */
    'options' => [
        // Include route-based documentation from PHPDoc comments
        'include_routes' => true,

        // Include extension documentation (e.g. the aegis /rbac/* admin routes).
        // Default false to keep this spec focused on the Lemma CMS API.
        'include_extensions' => false,

        // Pretty print JSON output
        'pretty_print' => true,

        // Generate resource/table routes (CRUD endpoints for all database tables)
        // Set to false to disable automatic generation of table-based API endpoints
        'include_resource_routes' => env('API_DOCS_INCLUDE_RESOURCE_ROUTES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    |
    | Tables to exclude from resource route expansion and documentation.
    | System/internal tables that shouldn't be exposed in the API.
    |
    */
    'excluded_tables' => [
        // System tables
        'migrations',
        'failed_jobs',
        'password_resets',
        'personal_access_tokens',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        // Tables with explicit routes in api.php (avoid duplicate docs)
        'notifications',
        'notification_preferences',
        'notification_templates',
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation UI
    |--------------------------------------------------------------------------
    |
    | Settings for the interactive documentation UI.
    | Supported: "scalar", "swagger-ui", "redoc"
    |
    */
    'ui' => [
        // Default UI to generate (scalar, swagger-ui, redoc)
        'default' => env('API_DOCS_UI', 'scalar'),

        // Output filename for the generated HTML
        'filename' => 'index.html',

        // Page title
        'title' => env('API_DOCS_UI_TITLE', 'API Documentation'),

        // Scalar-specific settings
        'scalar' => [
            'theme' => env('API_DOCS_THEME', 'purple'),
            'dark_mode' => env('API_DOCS_DARK_MODE', true),
            'hide_download_button' => false,
            'hide_client_button' => true,
            'hide_models' => false,
            'default_open_all_tags' => false,
            'show_developer_tools' => 'never',
            'hide_powered_badge' => true,
        ],

        // Swagger UI-specific settings
        'swagger_ui' => [
            'deep_linking' => true,
            'display_request_duration' => true,
            'filter' => true,
        ],

        // Redoc-specific settings
        'redoc' => [
            'expand_responses' => '200,201',
            'hide_download_button' => false,
            'theme' => [],
        ],
    ],
];
