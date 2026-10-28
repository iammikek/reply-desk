<?php

use Illuminate\Support\Facades\Process;

it('lists the desk tools and returns the same open row', function (): void {
    $result = Process::path(base_path())->run(['node', '--test', 'mcp-server/server.test.mjs']);

    expect($result->successful())->toBeTrue($result->errorOutput()."\n".$result->output());
});
