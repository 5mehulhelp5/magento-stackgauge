<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\ViewGento\Console\Command;

use StackNuts\ViewGento\Model\ReportSender;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manual verification without needing an admin Test Ping click: --dry-run proves the
 * collectors actually produce a well-formed payload on this environment; --force proves
 * end-to-end delivery even before the "Enabled" toggle is switched on in admin.
 */
class SendReportCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_FORCE = 'force';

    public function __construct(
        private readonly ReportSender $reportSender,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('viewgento:send')
            ->setDescription('Build and send a ViewGento status report')
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Print the assembled payload as JSON without sending it'
            )
            ->addOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                'Send even if ViewGento is disabled in Stores > Configuration > Advanced > ViewGento'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption(self::OPTION_DRY_RUN)) {
            $payload = $this->reportSender->buildPayload();
            $output->writeln((string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $sent = $input->getOption(self::OPTION_FORCE)
            ? $this->reportSender->sendNow()
            : $this->reportSender->send();

        if ($sent) {
            $output->writeln('<info>Report sent.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(
            '<error>Report not sent - check that ViewGento is enabled (or pass --force) and that the endpoint '
            . 'URL / API key are configured. See var/log/stacknuts_viewgento.log for details.</error>'
        );
        return Command::FAILURE;
    }
}
