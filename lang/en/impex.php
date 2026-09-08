<?php

declare(strict_types=1);

return [

    'label' => 'Impex',

    // Navigation
    'runs' => 'Runs',
    'messages' => 'Messages',
    'flows' => 'Flows',
    'channels' => 'Channels',

    // Settings
    'settings_label' => 'Impex',
    'settings_description' => 'Retention, limits and artifact storage for the workflow engine.',
    'retention' => 'Retention',
    'limits' => 'Limits',
    'artifacts' => 'Artifacts',
    'completed_runs' => 'Completed runs',
    'failed_runs' => 'Failed runs',
    'days' => ':count days',
    'inline_threshold' => 'Inline threshold',
    'sync_seconds' => 'Synchronous wait',
    'preview_bytes' => 'Message body preview',
    'bytes' => ':count bytes',
    'seconds' => ':count seconds',

    // Widgets
    'widget_run_status' => 'Run status',
    'widget_run_status_description' => 'How many runs sit in each state right now.',
    'widget_recent_failures' => 'Recent failures',
    'widget_recent_failures_description' => 'Runs that failed most recently.',
    'widget_messages' => 'Message volume',
    'widget_messages_description' => 'Messages recorded over the last day.',
    'active_runs' => 'Active runs',
    'no_failures' => 'No recent failures.',

    // Runs
    'run' => 'Run',
    'flow' => 'Flow',
    'status' => 'Status',
    'trigger' => 'Trigger',
    'tags' => 'Tags',
    'started' => 'Started',
    'finished' => 'Finished',
    'run_id' => 'Run id',
    'idempotency_key' => 'Idempotency key',
    'parent' => 'Parent',
    'no_runs' => 'No runs match these filters.',
    'all_statuses' => 'All statuses',
    'all_triggers' => 'All triggers',
    'filter' => 'Filter',
    'clear' => 'Clear',

    // Run actions
    'cancel' => 'Cancel',
    'retry' => 'Retry',
    'cancelled_from_dashboard' => 'Cancelled from the dashboard',
    'run_cancelled' => 'Run cancelled.',
    'run_retried' => 'Run queued for retry.',
    'signal_sent' => 'Signal delivered.',
    'signal_ignored' => 'The run had already finished, so nothing was delivered.',
    'send_signal' => 'Send signal',
    'signal_name' => 'Signal name',
    'signal_payload' => 'Payload (JSON)',
    'send' => 'Send',

    // Steps
    'steps' => 'Steps',
    'no_steps' => 'This run has no steps yet.',
    'attempts' => ':count attempts',
    'resumed' => 'resumed :count×',
    'undone' => 'undone',
    'rollback' => 'rollback',
    'result_on_disk' => 'result on artifact disk',

    // Owners
    'owners' => 'Owners',
    'role' => 'Role',
    'type' => 'Type',
    'id' => 'Id',
    'no_owners' => 'No owners attached.',

    // Messages
    'direction' => 'Direction',
    'channel' => 'Channel',
    'endpoint' => 'Endpoint',
    'transport' => 'Transport',
    'when' => 'When',
    'size' => 'Size',
    'no_messages' => 'Nothing crossed the boundary for this run.',
    'no_messages_at_all' => 'No messages match these filters.',
    'all_directions' => 'All directions',
    'signature' => 'Signature',
    'verified' => 'Verified',
    'bad_signature' => 'Bad signature',
    'unsigned' => 'Unsigned',
    'headers' => 'Headers',
    'body' => 'Body',
    'empty_body' => '(empty)',
    'body_on_disk' => 'The full body is stored as artifact :id.',
    'duration' => 'Duration',
    'milliseconds' => ':count ms',

    // Flows
    'slug' => 'Slug',
    'class' => 'Class',
    'schedule' => 'Schedule',
    'enabled' => 'Enabled',
    'disabled' => 'Disabled',
    'arguments' => 'Arguments (JSON array)',
    'start' => 'Run',
    'no_flows' => 'No flows are registered.',
    'flow_started' => 'Run started.',
    'invalid_arguments' => 'Arguments must be a JSON array.',

    // Channels
    'name' => 'Name',
    'path' => 'Path',
    'no_channels' => 'No channels are registered.',

    // Shared
    'actions' => 'Actions',
    'view' => 'View',
    'none' => '—',

];
