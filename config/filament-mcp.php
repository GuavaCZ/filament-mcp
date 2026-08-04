<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    */

    'tokens' => [
        'table' => 'mcp_tokens',
        'prefix' => 'gmcp_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | MCP has no channel for sending binary data in a tool call, so agents
    | upload files out of band: the "request_upload" tool returns a signed URL,
    | the agent PUTs the bytes to it, and passes back the handle. These options
    | control that staging area. Where the field finally stores the file always
    | comes from the FileUpload field's own configuration.
    |
    */

    'uploads' => [
        'table' => 'mcp_uploads',

        // The staging disk. Null uses the application's default. Staged files are
        // always written with private visibility.
        'disk' => null,

        'directory' => 'mcp-uploads',

        // Minutes a signed upload URL and its handle stay usable.
        'expires_after' => 60,

        // Maximum staged upload size in kilobytes. A field's own maxSize() is
        // enforced separately when the file is claimed.
        'max_size' => 12288,

        // Uploads per minute per IP. The upload endpoint is authorised by the URL
        // signature rather than a token, so the IP is all there is to key on.
        // Set to null to disable.
        'rate_limit' => 30,

        // Extra mime type => extension pairs, taking precedence over the built-in
        // list.
        //
        // A stored file's extension always comes from its sniffed type, never from
        // the name the agent gave it, and an unrecognised type is stored as ".bin".
        // Use this for formats your app accepts that the package does not know
        // about. Naming an executable type here means storing executable files.
        //
        // SVG is absent from the built-in list because a stored .svg on a public
        // disk runs any script it carries when opened directly. Map it here if
        // your app needs stored SVGs (e.g. logos).
        'extensions' => [
            // 'application/x-sqlite3' => 'sqlite',
            // 'image/svg+xml' => 'svg',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote files
    |--------------------------------------------------------------------------
    |
    | A file field can also be given an https URL for the server to download.
    | That makes the server issue requests on an agent's behalf, so it is guarded
    | against server-side request forgery by default: URLs resolving to private
    | or reserved addresses are refused and every redirect hop is re-checked.
    |
    */

    'remote_files' => [
        'enabled' => true,

        // When false, URLs resolving to private, loopback or reserved ranges are
        // refused. Enabling it lets an MCP token holder probe your internal
        // network.
        'allow_private_networks' => false,

        // When non-empty, only these hosts may be fetched, and the private
        // network check is skipped for them. Supports "*" wildcards.
        'allowed_hosts' => [],

        'timeout' => 10,

        'max_redirects' => 3,

        // Maximum download size in kilobytes.
        'max_size' => 12288,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Two limits apply to every MCP request, and a request has to pass both.
    |
    | "rate_limit" runs before authentication and is keyed by client IP, the only
    | thing an unauthenticated caller cannot vary for free. Raise it if your
    | agents call from shared infrastructure, where many unrelated callers arrive
    | from one address.
    |
    | "token_rate_limit" runs after authentication and is keyed by the access
    | token, making it the more specific limit and the one worth tightening. A
    | request that authenticated without an MCP token is keyed by user instead,
    | and one with no caller at all by IP.
    |
    | Set either to null to disable it.
    |
    */

    'rate_limit' => 60,

    'token_rate_limit' => 60,

    /*
    |--------------------------------------------------------------------------
    | Local serving
    |--------------------------------------------------------------------------
    |
    | The panel served when the MCP server runs over STDIO (Mcp::local),
    | e.g. through `php artisan mcp:start` or the MCP Inspector.
    |
    */

    'local_panel' => env('FILAMENT_MCP_LOCAL_PANEL'),

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy
    |--------------------------------------------------------------------------
    */

    'tenant_header' => 'X-Tenant',

];
