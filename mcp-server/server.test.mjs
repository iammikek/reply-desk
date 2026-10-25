import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const root = join(import.meta.dirname, '..');

function environment() {
    const env = {};

    for (const [key, value] of Object.entries(process.env)) {
        if (typeof value === 'string') {
            env[key] = value;
        }
    }

    const directory = mkdtempSync(join(tmpdir(), 'reply-desk-'));

    return {
        ...env,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: join(directory, 'desk.sqlite'),
        REPLY_DESK_STORE: 'memory',
        HUBSPOT_ACCESS_TOKEN: '',
        HUBSPOT_PORTAL_ID: '',
    };
}

function php(env, args) {
    execFileSync(env.PHP_BINARY || 'php', args, {
        cwd: root,
        env,
        stdio: 'pipe',
    });
}

test('lists the desk tools and returns the same open row', async () => {
    const env = environment();
    php(env, ['artisan', 'migrate', '--force']);
    php(env, [
        '-r',
        `require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        $now = now();
        Illuminate\\Support\\Facades\\DB::table('companies')->insert([
            ['id' => '10', 'name' => 'Northwharf Bakery', 'created_at' => $now, 'updated_at' => $now],
        ]);
        Illuminate\\Support\\Facades\\DB::table('contacts')->insert([
            ['id' => '20', 'company_id' => '10', 'name' => 'Ada North', 'email' => 'ada@northwharf.example', 'created_at' => $now, 'updated_at' => $now],
        ]);`,
    ]);

    const transport = new StdioClientTransport({
        command: process.execPath,
        args: ['mcp-server/index.mjs'],
        cwd: root,
        env,
        stderr: 'pipe',
    });
    const client = new Client({ name: 'reply-desk-test', version: '0.0.0' });
    await client.connect(transport);

    try {
        const listed = await client.listTools();
        const names = listed.tools.map((tool) => tool.name).sort();

        assert.deepEqual(names, [
            'discard_draft',
            'list_closed_replies',
            'list_companies',
            'list_open_replies',
            'log_inbound',
            'mark_reply_sent',
            'save_draft',
        ]);

        const companies = await client.callTool({ name: 'list_companies', arguments: {} });
        assert.equal(companies.isError, undefined);
        const directory = JSON.parse(companies.content[0].text);
        assert.equal(directory[0].name, 'Northwharf Bakery');
        assert.equal(directory[0].contacts[0].email, 'ada@northwharf.example');

        const logged = await client.callTool({
            name: 'log_inbound',
            arguments: {
                company: directory[0].name,
                email: directory[0].contacts[0].email,
                receipt: '2026-10-20 10:00',
                source: 'mail client',
                summary: 'Which PHP to use',
                client: 'Which loaf for Friday.',
            },
        });
        assert.equal(logged.isError, undefined);

        const saved = await client.callTool({
            name: 'save_draft',
            arguments: {
                inbound: '1',
                subject: 'PHP on the new server',
                body: 'Use 8.3 on the new box.',
            },
        });
        assert.equal(saved.isError, undefined);

        const open = await client.callTool({
            name: 'list_open_replies',
            arguments: { now: '2026-10-23 12:00' },
        });
        assert.equal(open.isError, undefined);

        const rows = JSON.parse(open.content[0].text);

        assert.equal(rows.length, 1);
        assert.equal(rows[0].client, 'Northwharf Bakery / Ada North');
        assert.equal(rows[0].reply_due, '2026-10-21 10:00');
        assert.equal(rows[0].draft_body, 'Use 8.3 on the new box.');
        assert.equal(rows[0].has_draft, true);
        assert.match(rows[0].body, /Which loaf for Friday\./);
    } finally {
        await client.close();
    }
});
