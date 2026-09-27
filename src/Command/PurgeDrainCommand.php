<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Command;

use Qoliber\TridentSymfony\Delivery\DeliveryFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('trident:purge:drain', 'Deliver due Trident purges from the outbox (cron: every minute)')]
final class PurgeDrainCommand extends Command
{
    public function __construct(private readonly DeliveryFactory $deliveries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many rows', '1000')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore the backoff: retry failed rows now')
            ->addOption('now', null, InputOption::VALUE_NONE, 'Deliver every row now, whatever its due time (second deliveries, backstop rows) — after an incident, or in tests');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivery = $this->deliveries->delivery();
        $limit = max(1, (int) $input->getOption('limit'));
        $report = $input->getOption('now') ? $delivery->drainAll($limit) : $delivery->drain($limit, (bool) $input->getOption('force'));
        $status = $delivery->status();
        $output->writeln(sprintf('Delivered %d purge(s), %d failed, %d entries purged; %d pending.', $report->delivered, $report->failed, $report->purged, $status['pending']));
        foreach ($report->instances as $name => $info) {
            $output->writeln(sprintf('  %s: %s', $name, json_encode($info)));
        }

        return $report->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
