#!/usr/bin/env node
/**
 * Local MCP server for Reply desk.
 * Cursor: command "node", args ["mcp-server/index.mjs"], cwd = this repo.
 * The process shells to php artisan. It does not hold the HubSpot token.
 */
import { spawn } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.PHP_BINARY || 'php';

const server = new McpServer({
    name: 'reply-desk',
    version: '0.1.0',
});

function artisan(args) {
    return new Promise((resolve, reject) => {
        const child = spawn(php, ['artisan', ...args], {
            cwd: root,
            env: process.env,
        });
        let stdout = '';
        let stderr = '';

        child.stdout.setEncoding('utf8');
        child.stderr.setEncoding('utf8');
        child.stdout.on('data', (chunk) => {
            stdout += chunk;
        });
        child.stderr.on('data', (chunk) => {
            stderr += chunk;
        });
        child.on('error', reject);
        child.on('close', (code) => {
            if (code !== 0) {
                reject(new Error((stderr || stdout || `php artisan exited ${code}`).trim()));

                return;
            }

            resolve(stdout.trim());
        });
    });
}

function toolResult(text, isError = false) {
    return {
        content: [{ type: 'text', text }],
        ...(isError ? { isError: true } : {}),
    };
}

async function run(args) {
    try {
        return toolResult(await artisan(args));
    } catch (error) {
        const text = error instanceof Error ? error.message : String(error);

        return toolResult(text, true);
    }
}

server.registerTool(
    'list_open_replies',
    {
        description:
            'List open replies. Due times are calculated at call time in Europe/London. A saved draft is included and does not change the due time.',
        inputSchema: {
            now: z
                .string()
                .optional()
                .describe('Optional clock time, Y-m-d H:i in Europe/London. Defaults to now.'),
        },
    },
    async ({ now } = {}) => {
        const args = ['reply:list-open'];

        if (now) {
            args.push(`--now=${now}`);
        }

        return run(args);
    },
);

server.registerTool(
    'list_closed_replies',
    {
        description:
            'List closed replies, newest sent time first. Each row is an inbound that has a sent note, including companies with nothing open. The client email and each pasted reply are included.',
        inputSchema: {},
    },
    async () => run(['reply:list-closed']),
);

server.registerTool(
    'create_company',
    {
        description:
            'Add a company. A blank name is refused. A company already there is refused. More than one match stops. This does not send mail.',
        inputSchema: {
            name: z.string().describe('Company name.'),
        },
    },
    async ({ name }) => run(['reply:create-company', name]),
);

server.registerTool(
    'create_contact',
    {
        description:
            'Add a contact on one company from list_companies. The email is stored in lowercase. A contact already there is refused. More than one company match stops. This does not send mail.',
        inputSchema: {
            company: z.string().describe('Company name from list_companies.'),
            name: z.string().describe('Contact name.'),
            email: z.string().describe('Contact email address.'),
        },
    },
    async ({ company, name, email }) => run(['reply:create-contact', company, name, email]),
);

server.registerTool(
    'list_companies',
    {
        description:
            'List companies and the contacts on each company. The list is cached. Log an inbound with a company name and contact email from this list.',
        inputSchema: {},
    },
    async () => run(['reply:directory']),
);

server.registerTool(
    'log_inbound',
    {
        description:
            'Record one inbound client message as a clock note. Use a company name and contact email from list_companies. Receipt source is "mail client", "logged", or "whatsapp". WhatsApp is a pasted message. This does not connect to WhatsApp and it does not send mail.',
        inputSchema: {
            company: z.string().describe('Company name from list_companies.'),
            email: z.string().describe('Contact email from list_companies.'),
            receipt: z.string().describe('When the email arrived, Y-m-d H:i Europe/London.'),
            source: z.enum(['mail client', 'logged', 'whatsapp']).describe('Where the message came from. whatsapp is a pasted WhatsApp message.'),
            summary: z.string().describe('One line describing the email.'),
            client: z.string().describe('The client email, pasted from Mail.'),
            subject: z.string().optional().describe('Subject of the client email, one line. Omit when the paste has none.'),
        },
    },
    async ({ company, email, receipt, source, summary, client, subject }) => {
        const args = ['reply:log-inbound', company, email, receipt, source, summary, client];

        if (subject) {
            args.push(`--subject=${subject}`);
        }

        return run(args);
    },
);

server.registerTool(
    'save_draft',
    {
        description:
            'Save or replace the local draft for an open inbound note. This does not stop the clock and does not write a HubSpot note.',
        inputSchema: {
            inbound: z.string().describe('Inbound note id.'),
            subject: z.string().describe('Draft subject, one line.'),
            body: z.string().describe('Draft body.'),
        },
    },
    async ({ inbound, subject, body }) => run(['reply:save-draft', inbound, subject, body]),
);

server.registerTool(
    'discard_draft',
    {
        description: 'Delete the local draft for an inbound note. The row stays open.',
        inputSchema: {
            inbound: z.string().describe('Inbound note id.'),
        },
    },
    async ({ inbound }) => run(['reply:discard-draft', inbound]),
);

server.registerTool(
    'mark_reply_sent',
    {
        description:
            'Record a reply that was already sent from the mail client. The first sent note closes the row. A later reply at a different minute is another sent note on that same inbound. The same minute is refused. A reply with no subject keeps the subject of the previous message. Deletes any draft. This does not send mail.',
        inputSchema: {
            inbound: z.string().describe('Inbound note id.'),
            sent: z.string().describe('When the reply was sent, Y-m-d H:i Europe/London.'),
            subject: z
                .string()
                .optional()
                .describe('Subject of the sent reply, one line. Omit to keep the subject of the previous message.'),
            reply: z.string().describe('The reply that was sent, pasted from Mail.'),
        },
    },
    async ({ inbound, sent, subject, reply }) => {
        const args = ['reply:mark-sent', inbound, sent, reply];

        if (subject) {
            args.push(`--subject=${subject}`);
        }

        return run(args);
    },
);

server.registerTool(
    'close_inbound',
    {
        description:
            'Close an open inbound without sending a reply. Use for thanks, FYI, or other mail that needs no response. Writes a closed clock note, deletes any draft, and removes the row from the open queue. Refuses if the inbound is already closed or replied. This does not send mail.',
        inputSchema: {
            inbound: z.string().describe('Inbound note id.'),
            at: z
                .string()
                .optional()
                .describe('When it was closed, Y-m-d H:i Europe/London. Defaults to now.'),
        },
    },
    async ({ inbound, at } = {}) => {
        const args = ['reply:close', inbound];

        if (at) {
            args.push(`--at=${at}`);
        }

        return run(args);
    },
);

server.registerTool(
    'send_reply',
    {
        description:
            'Send a reply for an open inbound through the desk mailer or practice mailbox, then mark it sent. Deletes any draft. A blank subject keeps the subject of the previous message. Requires approval.',
        inputSchema: {
            inbound: z.string().describe('Inbound note id.'),
            subject: z
                .string()
                .describe('Subject of the reply, one line. Pass an empty string to keep the subject of the previous message.'),
            body: z.string().describe('The reply body to send.'),
        },
    },
    async ({ inbound, subject, body }) => run(['reply:send', inbound, subject, body]),
);

const transport = new StdioServerTransport();
await server.connect(transport);
