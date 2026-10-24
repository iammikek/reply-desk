<article class="reply-desk-case-study">
    <header class="reply-desk-case-study-header">
        <div>
            <p class="reply-desk-case-study-kicker">Reply desk</p>
            <h1>Why this desk exists</h1>
        </div>
        <a href="{{ url('/') }}">Open the queue</a>
    </header>

    <div class="reply-desk-case-study-grid">
        <section>
            <p>Practice client email used to be a table in a file. Due time was calculated when someone read it. The next reply still depended on remembering to look.</p>
            <p>Reply desk is that queue, on a screen. The record is a clock note. The reply still goes out from Mail.</p>

            <h2>Why it is useful</h2>
            <ul>
                <li>One list shows who is waiting. Each open row is a client email with no reply yet.</li>
                <li>The reply is due 24 hours after the clock starts. Monday to Friday, 08:00 to 20:00, Europe/London. Evening and weekend mail starts at 08:00 on the next working day.</li>
                <li>Due time is calculated when the page is opened. It is not stored, so it cannot go stale.</li>
                <li>A draft can sit on the row while the reply is written. The draft does not stop the clock, and it is not a reply.</li>
                <li>Marking sent records that the reply went. The desk does not send the email.</li>
                <li>Closed threads stay on History, newest first, with the client email and then each reply.</li>
            </ul>
        </section>

        <section>
            <h2>Features</h2>
            <dl>
                <dt>Open queue</dt>
                <dd>Company, contact, summary, inbound time, clock start, reply due, and a draft when one is saved. Overdue rows can be filtered. Earlier threads for the same company sit on the open row.</dd>
                <dt>Log inbound</dt>
                <dd>Company and contact come from the directory already on file. The desk creates neither. A match that is not exactly one stops, and nothing is written.</dd>
                <dt>Draft</dt>
                <dd>One draft per open row. Save replaces it. Discard removes it and leaves the row open.</dd>
                <dt>Mark sent</dt>
                <dd>Records the sent time, the subject, and the reply pasted from Mail. The row leaves the queue.</dd>
                <dt>History</dt>
                <dd>Closed threads, ten at a time. A company with nothing open is still there. Thread opens the timeline.</dd>
                <dt>The same actions from Cursor</dt>
                <dd>Listing the queue, logging an inbound, saving a draft, and marking sent use the same actions as the panel. Both read the same notes.</dd>
            </dl>
        </section>
    </div>
</article>

<style>
    .reply-desk-case-study {
        box-sizing: border-box;
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 1.25rem;
        max-width: 68rem;
        margin: 0 auto;
        padding: 1.5rem 3rem;
        color: var(--gray-950);
    }

    .dark .reply-desk-case-study {
        color: var(--color-white);
    }

    .reply-desk-case-study-header {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 1.5rem;
    }

    .reply-desk-case-study-kicker {
        margin: 0 0 0.4rem;
        font-size: 0.8rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        opacity: 0.55;
    }

    .reply-desk-case-study h1 {
        margin: 0;
        font-size: 1.85rem;
        font-weight: 600;
        letter-spacing: -0.02em;
        line-height: 1.2;
    }

    .reply-desk-case-study h2 {
        margin: 1.25rem 0 0.6rem;
        font-size: 1.05rem;
        font-weight: 600;
    }

    .reply-desk-case-study p,
    .reply-desk-case-study li,
    .reply-desk-case-study dd {
        margin: 0.45rem 0 0;
        line-height: 1.45;
    }

    .reply-desk-case-study-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
        gap: 2.5rem;
        align-items: start;
    }

    .reply-desk-case-study ul {
        margin: 0.35rem 0 0;
        padding-left: 1.15rem;
        list-style: disc;
    }

    .reply-desk-case-study li {
        display: list-item;
    }

    .reply-desk-case-study dl {
        margin: 0.35rem 0 0;
    }

    .reply-desk-case-study-grid h2:first-child {
        margin-top: 0;
    }

    .reply-desk-case-study dt {
        margin-top: 0.55rem;
        font-weight: 600;
    }

    .reply-desk-case-study a {
        color: inherit;
        font-weight: 600;
        white-space: nowrap;
    }

    @media (max-width: 56rem) {
        .reply-desk-case-study {
            justify-content: flex-start;
            padding: 1.75rem 1.25rem 2.5rem;
        }

        .reply-desk-case-study-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .reply-desk-case-study-grid {
            grid-template-columns: 1fr;
            gap: 0.5rem;
        }
    }
</style>
