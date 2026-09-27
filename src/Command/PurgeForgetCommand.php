<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Command;

use Qoliber\TridentSymfony\Delivery\DeliveryFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('trident:purge:forget', 'Drop outbox rows owed to an instance that is no longer configured')]
final class PurgeForgetCommand extends Command
{
    public function __construct(private readonly DeliveryFactory $deliveries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('instance', InputArgument::REQUIRED, 'The instance name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivery = $this->deliveries->delivery();
        $name = (string) $input->getArgument('instance');
        if (\in_array($name, $delivery->settings()->instanceNames(), true)) {
            $output->writeln(sprintf('"%s" is configured: its rows are still owed. Remove it from the configuration first.', $name));

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Forgot %d row(s) for "%s".', $delivery->forget($name), $name));

        return Command::SUCCESS;
    }
}
