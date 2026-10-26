<div>
    @if ($showClient ?? true)
        <p style="margin: 0; font-weight: 600;">{{ $client }}</p>
        @if ($email !== '')
            <p style="margin: 0.15rem 0 0; opacity: 0.7;">{{ $email }}</p>
        @endif
        @if (($note ?? '') !== '')
            <p class="thread-note">Note {{ $note }}</p>
        @endif
    @endif

    <ol class="thread-timeline">
        @foreach ($events as $event)
            <li>
                <time>{{ $event['at'] }}</time>
                <p class="kind">{{ $event['kind'] }}</p>
                @if ($event['title'] !== '')
                    <p class="title">{{ $event['title'] }}</p>
                @endif
                @if (($event['detail'] ?? '') !== '')
                    <p class="detail">{{ $event['detail'] }}</p>
                @endif
                @if ($event['body'] !== '')
                    <div class="body">{{ $event['body'] }}</div>
                @endif
                @if (($event['attachment'] ?? '') !== '' && ($event['note_id'] ?? '') !== '')
                    <p class="attachment"><a href="{{ route('reply-desk.notes.attachment', ['note' => $event['note_id']]) }}">{{ $event['attachment'] }}</a></p>
                @endif
            </li>
        @endforeach
    </ol>
</div>

<style>
    .thread-timeline {
        list-style: none;
        margin: 1.25rem 0 0;
        padding: 0 0 0 1.25rem;
        border-left: 2px solid color-mix(in srgb, currentColor 22%, transparent);
    }

    .thread-timeline li {
        position: relative;
        padding: 0 0 1.5rem 1rem;
    }

    .thread-timeline li:last-child {
        padding-bottom: 0;
    }

    .thread-timeline li::before {
        content: "";
        position: absolute;
        left: calc(-1.25rem - 5px);
        top: 0.35rem;
        width: 0.7rem;
        height: 0.7rem;
        border-radius: 999px;
        background: currentColor;
    }

    .thread-timeline time {
        display: block;
        font-variant-numeric: tabular-nums;
        font-size: 0.8rem;
        opacity: 0.7;
    }

    .thread-timeline .kind {
        margin: 0.15rem 0 0;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        opacity: 0.6;
    }

    .thread-timeline .title {
        margin: 0.2rem 0 0;
        font-weight: 600;
    }

    .thread-note {
        margin: 0.35rem 0 0;
        font-size: 0.8rem;
        opacity: 0.7;
    }

    .thread-timeline .detail {
        margin: 0.2rem 0 0;
        font-size: 0.8rem;
        font-variant-numeric: tabular-nums;
        opacity: 0.75;
    }

    .thread-timeline .body {
        margin: 0.45rem 0 0;
        white-space: pre-wrap;
    }

    .thread-timeline .attachment {
        margin: 0.45rem 0 0;
    }

    .thread-timeline .attachment a {
        color: inherit;
    }
</style>
