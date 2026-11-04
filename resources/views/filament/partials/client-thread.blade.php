@php
    $statusLabel = match ($thread['status'] ?? '') {
        'replied' => 'Replied',
        'closed' => 'Closed — no reply',
        default => 'Open',
    };
    $heading = ($thread['subject'] ?? '') !== ''
        ? $thread['subject']
        : ($thread['summary'] ?? '');
@endphp

<article class="client-thread" id="thread-{{ $thread['id'] }}">
    <header class="client-thread__meta">
        <p class="client-thread__started">Started {{ $thread['started_at'] }}</p>
        @if ($heading !== '')
            <p class="client-thread__subject">{{ $heading }}</p>
        @endif
        <p class="client-thread__status">{{ $statusLabel }}</p>
    </header>

    <ol class="client-thread__messages">
        @foreach ($thread['events'] ?? [] as $event)
            @php
                $side = $event['side'] ?? 'system';
                $hasBody = ($event['body'] ?? '') !== '';
                $hasAttachment = ($event['attachment'] ?? '') !== '' && ($event['note_id'] ?? '') !== '';
            @endphp
            @if ($side === 'system' || $hasBody || $hasAttachment)
                <li class="client-thread__row client-thread__row--{{ $side }}">
                    @if ($side === 'system')
                        <p class="client-thread__system">
                            <time>{{ $event['at'] }}</time>
                            @if ($hasBody)
                                <span>{{ $event['body'] }}</span>
                            @endif
                        </p>
                    @else
                        <div class="client-thread__bubble client-thread__bubble--{{ $side }}">
                            <time>{{ $event['at'] }}</time>
                            @if ($hasBody)
                                <div class="client-thread__body">{{ $event['body'] }}</div>
                            @endif
                            @if ($hasAttachment)
                                <p class="client-thread__attachment">
                                    <a href="{{ route('reply-desk.notes.attachment', ['note' => $event['note_id']]) }}">{{ $event['attachment'] }}</a>
                                </p>
                            @endif
                        </div>
                    @endif
                </li>
            @endif
        @endforeach
    </ol>
</article>
