<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Command;

use Qoliber\TridentSymfony\Delivery\DeliveryFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('trident:purge', 'Purge cache tags (or --all) on every Trident instance, durably')]
final class PurgeCommand extends Command
{
    public function __construct(private readonly DeliveryFactory $deliveries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tags', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Unprefixed cache tags')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every page of this shop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivery = $this->deliveries->delivery();
        if (!$delivery->settings()->enabled()) {
            $output->writeln('No Trident instance is configured.');

            return Command::FAILURE;
        }
        $rows = $input->getOption('all') ? $delivery->recordAll() : $delivery->recordTags((array) $input->getArgument('tags'));
        if ($rows === 0) {
            $output->writeln('Nothing recorded (no tags given).');

            return Command::INVALID;
        }
        $report = $delivery->flush();
        $output->writeln(sprintf('Recorded %d row(s); delivered %d, failed %d, %d entries purged.', $rows, $report->delivered, $report->failed, $report->purged));

        return $report->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
