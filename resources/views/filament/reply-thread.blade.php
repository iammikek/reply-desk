<div class="reply-thread">
    @if ($earlier !== '')
        <p class="reply-thread-label">Earlier on this client</p>
        <div class="reply-thread-body">{{ $earlier }}</div>
    @endif
</div>

<style>
    .fi-modal:has(.reply-compose) > .fi-modal-window-ctn {
        overflow: hidden;
    }

    .fi-modal > .fi-modal-window-ctn > .fi-modal-window.reply-compose {
        position: fixed;
        top: 1rem;
        right: 0;
        bottom: 1rem;
        left: 0;
        width: min(56rem, calc(100vw - 2rem));
        max-width: none;
        height: auto;
        margin-inline: auto;
        overflow: hidden;
    }

    .fi-modal-window.reply-compose > .fi-modal-content {
        flex: 1 1 auto;
        min-height: 0;
        overflow: hidden;
        gap: 0;
        padding-bottom: 0.75rem;
    }

    .fi-modal-window.reply-compose > .fi-modal-content > .reply-thread {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
    }

    .fi-modal-window.reply-compose .reply-thread-label {
        margin: 0 0 0.75rem;
        font-size: 0.875rem;
        font-weight: 600;
    }

    .fi-modal-window.reply-compose .reply-thread-body {
        white-space: pre-wrap;
    }

    .fi-modal-window.reply-compose > .fi-modal-content > .fi-sc {
        flex: 0 0 auto;
        max-height: 70%;
        margin-top: auto;
        overflow-y: auto;
        padding-top: 1rem;
        border-top: 1px solid color-mix(in srgb, currentColor 18%, transparent);
    }
</style>
