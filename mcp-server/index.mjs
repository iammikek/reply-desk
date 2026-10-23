#!/usr/bin/env node
/**
 * Local MCP server for Reply desk.
 * Cursor: command "node", args ["mcp-server/index.mjs"], cwd = this repo.
 * The process shells to php artisan. It does not send mail and it does not hold the HubSpot token.
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
            'Record one inbound client email as a clock note. Use a company name and contact email from list_companies. Receipt source is "mail client" or "logged". This does not send mail.',
        inputSchema: {
            company: z.string().describe('Company name from list_companies.'),
            email: z.string().describe('Contact email from list_companies.'),
            receipt: z.string().describe('When the email arrived, Y-m-d H:i Europe/London.'),
            source: z.enum(['mail client', 'logged']).describe('Where the receipt time came from.'),
            summary: z.string().describe('One line describing the email.'),
            client: z.string().describe('The client email, pasted from Mail.'),
        },
    },
    async ({ company, email, receipt, source, summary, client }) =>
        run(['reply:log-inbound', company, email, receipt, source, summary, client]),
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
            'Record a reply that was already sent from the mail client. Writes one sent clock note and deletes any draft. This does not send mail.',
        inputSchema: {
            inbound: z.string().describe('Inbound note id.'),
            sent: z.string().describe('When the reply was sent, Y-m-d H:i Europe/London.'),
            subject: z.string().describe('Subject of the sent reply, one line.'),
            reply: z.string().describe('The reply that was sent, pasted from Mail.'),
        },
    },
    async ({ inbound, sent, subject, reply }) => run(['reply:mark-sent', inbound, sent, subject, reply]),
);

const transport = new StdioServerTransport();
await server.connect(transport);
