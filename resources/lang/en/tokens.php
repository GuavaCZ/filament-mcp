<?php

return [

    'page' => [
        'title' => 'MCP tokens',
        'navigation_label' => 'MCP tokens',
    ],

    'section' => [
        'heading' => 'MCP tokens',
        'description' => 'Let MCP clients such as Claude Code, Claude Desktop or Cursor access this panel on your behalf.',
    ],

    'columns' => [
        'name' => 'Name',
        'abilities' => 'Abilities',
        'panel_id' => 'Panel',
        'last_used_at' => 'Last used',
        'expires_at' => 'Expires',
        'created_at' => 'Created',
    ],

    'placeholders' => [
        'all_panels' => 'All panels',
        'never' => 'Never',
    ],

    'badges' => [
        'full_access' => 'Full access',
        'expired' => 'Expired',
    ],

    'empty' => [
        'heading' => 'No MCP tokens',
        'description' => 'Create a token to let an MCP client access this panel on your behalf.',
    ],

    'actions' => [

        'create' => [
            'label' => 'Create token',
            'modal_heading' => 'Create MCP token',
            'fields' => [
                'name' => [
                    'label' => 'Name',
                    'helper_text' => 'So you can tell your tokens apart later, e.g. "Claude Code on my laptop".',
                ],
                'access' => [
                    'label' => 'Access',
                    'options' => [
                        'full' => 'Full access',
                        'custom' => 'Limited access',
                    ],
                    'descriptions' => [
                        'full' => 'Everything you are allowed to do in this panel.',
                        'custom' => 'Pick exactly which operations the client may perform.',
                    ],
                ],
                'abilities' => [
                    'label' => 'Abilities',
                    'validation_attribute' => 'abilities',
                ],
                'expires_at' => [
                    'label' => 'Expires at',
                    'helper_text' => 'Leave empty for a token that never expires.',
                ],
            ],
        ],

        'reveal' => [
            'heading' => 'Your new MCP token',
            'description' => 'Copy it now. For security reasons it is hashed at rest and cannot be shown again.',
            'close_label' => 'Done',
            'entries' => [
                'token' => [
                    'label' => 'Token',
                ],
                'command' => [
                    'label' => 'Claude Code',
                    'helper_text' => 'Run this in your terminal to connect Claude Code to this panel.',
                ],
                'config' => [
                    'label' => 'Claude Desktop, Cursor and other clients',
                    'helper_text' => 'Add this to your client\'s MCP configuration file.',
                ],
            ],
            'copied' => 'Copied',
            'copy_hint' => 'Click the text to copy it',
        ],

        'revoke' => [
            'label' => 'Revoke',
            'modal_heading' => 'Revoke MCP token',
            'modal_description' => 'Any client using this token loses access immediately. This cannot be undone.',
            'modal_submit_label' => 'Revoke',
            'notification' => 'MCP token revoked',
        ],

    ],

    'operations' => [
        'list' => 'List',
        'get' => 'Read',
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Delete',
    ],

];
