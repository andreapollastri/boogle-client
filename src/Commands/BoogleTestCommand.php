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
