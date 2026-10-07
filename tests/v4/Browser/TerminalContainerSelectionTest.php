<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->terminalServer = null;
    config()->set('app.maintenance.store', 'array');
    config()->set('constants.ssh.mux_enabled', false);

    $stack = seedBrowserResourceStack();
    $stack['server']->settings->update(['is_terminal_enabled' => true]);
    $this->serverUuid = $stack['server']->uuid;
    $application = createBrowserApplication($stack, [
        'uuid' => 'app-terminal-selection',
        'build_pack' => 'dockercompose',
    ]);
    $this->terminalPath = "/project/{$stack['project']->uuid}/environment/{$stack['environment']->uuid}/application/{$application->uuid}/terminal";
    Cache::flush();

    $this->terminalServer = new SymfonyProcess(['node', base_path('tests/Fixtures/terminal-websocket-rejection-server.mjs'), 'reject-token']);
    $this->terminalServer->start();
    $this->terminalServer->waitUntil(fn (string $type, string $output) => str_contains($output, "\n"));
    config()->set('constants.terminal.protocol', 'ws');
    config()->set('constants.terminal.host', '127.0.0.1');
    config()->set('constants.terminal.port', trim($this->terminalServer->getOutput()));
});

afterEach(function () {
    $this->terminalServer?->stop(0);
});

it('shows asynchronously loaded compose containers and lets the user select and switch them', function () {
    Process::fake(function ($process) {
        $command = (string) $process->command;

        if (str_contains($command, 'docker ps -a')) {
            return Process::result(output: collect(['app-terminal-selection-b', 'app-terminal-selection-a'])
                ->map(fn (string $name) => json_encode(['ID' => md5($name), 'Names' => $name, 'State' => 'running', 'Labels' => '']))
                ->implode("\n"));
        }

        if (str_contains($command, 'docker inspect')) {
            return Process::result(output: json_encode(['State' => ['Status' => 'exited']]));
        }

        return Process::result();
    });

    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate($this->terminalPath)
        ->assertSee('Choose a container to start a session')
        ->assertScript("Array.from(document.querySelectorAll('[data-terminal-target-picker=\"launcher\"] button')).map(button => button.textContent.trim())", [
            'app-terminal-selection-a · localhost',
            'app-terminal-selection-b · localhost',
        ])
        ->assertNoJavaScriptErrors();

    // Coolify emits CustomEvent('error') for expected terminal-failure toasts. Track
    // actual ErrorEvents separately while exercising the two rejected sessions.
    $page->script(<<<'JS'
        window.terminalJavaScriptErrors = [];
        window.addEventListener('error', (event) => {
            if (event instanceof ErrorEvent) {
                window.terminalJavaScriptErrors.push(event.message);
            }
        });
        window.addEventListener('unhandledrejection', (event) => {
            window.terminalJavaScriptErrors.push(String(event.reason));
        });
    JS);

    $page->click('[data-terminal-target-picker="launcher"] button:first-of-type')
        ->assertSee('The container is not running.')
        ->assertScript("Alpine.\$data(document.querySelector('[data-terminal-session-canvas]').parentElement).selectedContainer", "{$this->serverUuid}:app-terminal-selection-a")
        ->click('button.terminal-session-target-trigger')
        ->click('.terminal-target-list button:last-of-type')
        ->assertScript("Alpine.\$data(document.querySelector('[data-terminal-session-canvas]').parentElement).selectedContainer", "{$this->serverUuid}:app-terminal-selection-b")
        ->assertScript('window.terminalJavaScriptErrors', []);

    $page->screenshot(filename: 'terminal-compose-container-selection');
});
