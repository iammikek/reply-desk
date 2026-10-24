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
            <p>Reply desk is that queue, on a screen. The record is a clock note. Send the reply from here, or mark it sent after it goes from Mail.</p>

            <h2>Why it is useful</h2>
            <ul>
                <li>One list shows who is waiting. Each open row is a client email with no reply yet.</li>
                <li>The reply is due 24 hours after the clock starts. Monday to Friday, 08:00 to 20:00, Europe/London. Evening and weekend mail starts at 08:00 on the next working day.</li>
                <li>Due time is calculated when the page is opened. It is not stored, so it cannot go stale.</li>
                <li>A draft can sit on the row while the reply is written. The draft does not stop the clock, and it is not a reply.</li>
                <li>Send delivers the reply and keeps the paste: blank lines, indentation, and runs of spaces. A file on the note is stored in HubSpot. Mark sent records a reply that already went from Mail.</li>
                <li>Closed threads stay on History, newest first. The timeline shows each message as pasted, and the name of a file opens it.</li>
            </ul>
        </section>

        <section>
            <h2>Features</h2>
            <dl>
                <dt>Open queue</dt>
                <dd>Company, contact, summary, inbound time, clock start, reply due, and a draft when one is saved. Overdue rows can be filtered. Earlier threads for the same company sit on the open row.</dd>
                <dt>Log inbound</dt>
                <dd>Company and contact come from the directory already on file. The desk creates neither. A match that is not exactly one stops. Subject and body are logged separately, and an optional file is stored on the note.</dd>
                <dt>Draft</dt>
                <dd>One draft per open row. Save replaces it. Discard removes it and leaves the row open. The body keeps the paste.</dd>
                <dt>Send</dt>
                <dd>Delivers the reply from the desk, then writes the sent note. The subject stays one line. A file goes with the message.</dd>
                <dt>Mark sent</dt>
                <dd>Records the sent time, the subject, and the reply pasted from Mail. The row leaves the queue.</dd>
                <dt>History</dt>
                <dd>Closed threads, ten at a time. A company with nothing open is still there. Thread opens the timeline, and a file name opens the file.</dd>
                <dt>The same actions from Cursor</dt>
                <dd>Listing the queue, logging an inbound, saving a draft, and marking sent use the same actions as the panel. Cursor cannot send. Both read the same notes.</dd>
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
        gap: 0.75rem;
        max-width: 68rem;
        margin: 0 auto;
        padding: 1rem 3rem;
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
        margin: 0.7rem 0 0.35rem;
        font-size: 1.05rem;
        font-weight: 600;
    }

    .reply-desk-case-study p,
    .reply-desk-case-study li,
    .reply-desk-case-study dd {
        margin: 0.22rem 0 0;
        line-height: 1.38;
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
        margin-top: 0.32rem;
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
