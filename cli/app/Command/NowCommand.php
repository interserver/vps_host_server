<?php
namespace App\Command;

use CLIFramework\Command;
use DateTime;
use DateTimeInterface;

class NowCommand extends Command {
    public function brief()
    {
        return "Displays current date and time."; //Short description
    }

    public function execute()
    {
        $this->logger->notice('executing bar command.');
        $this->logger->info('info message');
        $this->logger->debug('debug message');
        $this->logger->write('just write..no ln..');
        $this->logger->writeln('just write and drop a line');
        $this->logger->newline();
        $this->logger->writeln('just write and drop a line after a newline');
    }
}
