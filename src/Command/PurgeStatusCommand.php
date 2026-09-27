<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Command;

use Qoliber\TridentSymfony\Delivery\DeliveryFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The outbox and the instances, for monitoring: exit 1 when a purge has been
 * pending longer than --stale-after seconds (default {@see STALE_AFTER}), a configuration error exists
 * or rows are owed to an instance that is no longer configured.
 */
#[AsCommand('trident:purge:status', 'Pending Trident purges, instances and configuration errors (exit 1 when unhealthy)')]
final class PurgeStatusCommand extends Command
{
    public const STALE_AFTER = 900;

    public function __construct(private readonly DeliveryFactory $deliveries)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stale-after', null, InputOption::VALUE_REQUIRED, 'Seconds a purge may stay owed before the status is unhealthy', (string) self::STALE_AFTER);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $staleAfter = $input->getOption('stale-after');
        if (!is_string($staleAfter) || !ctype_digit($staleAfter)) {
            $output->writeln('<error>--stale-after takes whole seconds</error>');

            return Command::INVALID;
        }
        $delivery = $this->deliveries->delivery();
        $settings = $delivery->settings();
        $status = $delivery->status();
        $output->writeln(sprintf('source: %s', $settings->source));
        foreach ($settings->instances as $instance) {
            $output->writeln(sprintf('  instance %-20s %s  token: %s  pending %d', $instance->name, $instance->apiUrl, $instance->apiToken !== '' ? 'yes' : 'no', $status['by_instance'][$instance->name] ?? 0));
        }
        foreach ($settings->errors as $error) {
            $output->writeln('  error: ' . $error);
        }
        $output->writeln(sprintf('pending: %d  oldest: %s  last failure: %s', $status['pending'], $status['oldest_age'] === null ? '-' : $status['oldest_age'] . 's', $status['last_error'] ?? '-'));
        foreach ($status['orphaned'] as $name => $count) {
            $output->writeln(sprintf('  orphaned: %d row(s) for "%s", which is not configured (trident:purge:forget %s)', $count, $name, $name));
        }
        $unhealthy = $settings->errors !== [] || $status['orphaned'] !== [] || (($status['oldest_age'] ?? 0) > (int) $staleAfter);

        return $unhealthy ? Command::FAILURE : Command::SUCCESS;
    }
}
