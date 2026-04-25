<?php

namespace Boogle\Commands;

use Illuminate\Console\Command;

class BoogleInstallCommand extends Command
{
    protected $signature   = 'boogle:install';
    protected $description = 'Publish the Boogle configuration file';

    public function handle(): void
    {
        $this->info('Publishing Boogle configuration...');

        $this->call('vendor:publish', ['--tag' => 'boogle-config', '--force' => true]);

        $this->newLine();
        $this->info('✓ Configuration published to config/boogle.php');
        $this->newLine();
        $this->comment('Next steps:');
        $this->line('  1. Add your credentials to .env:');
        $this->line('     BOOGLE_KEY=your-api-token');
        $this->line('     BOOGLE_PROJECT_KEY=your-project-key');
        $this->line('     BOOGLE_SERVER=https://your-boogle-instance.com/api/log');
        $this->newLine();
        $this->line('  2. Report exceptions in your exception handler:');
        $this->line('     Boogle::handle($exception);');
        $this->newLine();
        $this->line('  3. Test your setup:');
        $this->line('     php artisan boogle:test');
        $this->newLine();
    }
}
