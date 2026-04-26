<?php

namespace Boogle\Commands;

use Boogle\Boogle;
use Illuminate\Console\Command;

class BoogleTestCommand extends Command
{
    protected $signature   = 'boogle:test';
    protected $description = 'Send a test exception to your Boogle instance';

    public function handle(Boogle $boogle): void
    {
        if (! $boogle->isEnabled()) {
            $this->warn('Boogle is not configured: set BOOGLE_KEY and BOOGLE_PROJECT_KEY in your .env (and BOOGLE_SERVER if needed).');
            $this->line('No request was sent.');

            return;
        }

        $this->info('Sending test exception to Boogle...');

        try {
            $boogle->handle(
                new \RuntimeException('Boogle test exception — if you see this in your dashboard, everything is working!'),
                'php',
                ['test' => true]
            );
            $this->info('✓ Test exception sent successfully. Check your Boogle dashboard.');
        } catch (\Exception $e) {
            $this->error('Failed to send test exception: ' . $e->getMessage());
        }
    }
}
