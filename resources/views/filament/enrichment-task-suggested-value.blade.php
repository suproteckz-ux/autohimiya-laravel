<div style="display:grid; gap:12px">
    <div>
        <strong>Current value</strong>
        <pre style="white-space:pre-wrap">{{ $record->current_value ?: 'empty' }}</pre>
    </div>
    <div>
        <strong>Suggested value</strong>
        <pre style="white-space:pre-wrap">{{ $record->suggested_value ?: 'empty' }}</pre>
    </div>
    <div>
        <strong>Reason</strong>
        <p>{{ $record->reason ?: 'No reason provided.' }}</p>
    </div>
</div>
