<?php

namespace GlpiPlugin\Pellissarisync\Command;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Pellissarisync\Agent;
use GlpiPlugin\Pellissarisync\ApiServer;
use GlpiPlugin\Pellissarisync\Config;
use GlpiPlugin\Pellissarisync\Handshake;
use GlpiPlugin\Pellissarisync\Outbox;
use GlpiPlugin\Pellissarisync\Ping;
use GlpiPlugin\Pellissarisync\Schema;
use GlpiPlugin\Pellissarisync\TestTicket;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scriptable counterpart of the configuration screen.
 *
 * Exists because the enrollment token is encrypted with each instance's own key:
 * it can only be read back through the plugin's own API, never from raw SQL. This
 * makes provisioning an agent reproducible instead of a manual copy/paste.
 */
class ConfigureCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->setName('plugins:pellissarisync:configure');
        $this->setDescription('Configure the Pellissari Sync plugin');

        $this->addOption('role', null, InputOption::VALUE_REQUIRED, 'agent or master');
        $this->addOption('master-url', null, InputOption::VALUE_REQUIRED, 'Base URL of the master (agent only)');
        $this->addOption('own-url', null, InputOption::VALUE_REQUIRED, 'Base URL of this agent, as reachable by the master');
        $this->addOption('enrollment-token', null, InputOption::VALUE_REQUIRED, 'Enrollment token issued by the master');
        $this->addOption('category-id', null, InputOption::VALUE_REQUIRED, 'ITIL category id used for mirroring');
        $this->addOption('title-prefix', null, InputOption::VALUE_REQUIRED, 'Title prefix template, e.g. "[{client}]"');
        $this->addOption('connect', null, InputOption::VALUE_NONE, 'Run the handshake against the master');
        $this->addOption('link-agent', null, InputOption::VALUE_REQUIRED, 'Agent id to link (master only)');
        $this->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Customer entity id to bind to --link-agent');
        $this->addOption('test-ticket', null, InputOption::VALUE_NONE, 'Create a test ticket in the synchronized category');
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'Act as this GLPI user (needed for authored tickets)', 'glpi');
        $this->addOption('ping', null, InputOption::VALUE_REQUIRED, 'Ping a peer by id, or "master" from an agent');
        $this->addOption('flush', null, InputOption::VALUE_NONE, 'Deliver the pending outbox entries now');
        $this->addOption('show', null, InputOption::VALUE_NONE, 'Print the current configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $values = [];

        if ($role = $input->getOption('role')) {
            if (!in_array($role, [Config::ROLE_AGENT, Config::ROLE_MASTER], true)) {
                $output->writeln('<error>role must be "agent" or "master"</error>');

                return self::FAILURE;
            }
            $values['role'] = $role;
        }

        foreach (
            [
            'master-url'       => 'master_url',
            'own-url'          => 'own_url',
            'enrollment-token' => 'enrollment_token',
            'title-prefix'     => 'title_prefix_template',
            ] as $option => $key
        ) {
            $value = $input->getOption($option);
            if ($value !== null) {
                $values[$key] = (string) $value;
            }
        }

        if ($values !== []) {
            Config::set($values);
            Config::reset();
            $output->writeln('<info>Configuration updated.</info>');
        }

        if (($category = $input->getOption('category-id')) !== null) {
            $key = Config::isMaster() ? 'mirror_itilcategories_id' : 'trigger_itilcategories_id';
            Config::set([$key => (int) $category]);
            Config::reset();
            $output->writeln(sprintf('<info>Category set to %d.</info>', (int) $category));
        }

        if (($agentId = $input->getOption('link-agent')) !== null) {
            $agent = new Agent();

            if (!$agent->getFromDB((int) $agentId)) {
                $output->writeln('<error>unknown agent</error>');

                return self::FAILURE;
            }

            $values = ['id' => $agent->getID(), 'link_status' => Agent::STATUS_LINKED, 'is_active' => 1];

            if (($entity = $input->getOption('entity')) !== null) {
                $values['entities_id'] = (int) $entity;
            }

            $agent->update($values);

            $output->writeln(sprintf('<info>Agent #%d linked.</info>', $agent->getID()));
        }

        if ($input->getOption('connect')) {
            $result = Handshake::connect();

            $output->writeln(
                ($result['ok'] ? '<info>' : '<error>') . $result['message'] . ($result['ok'] ? '</info>' : '</error>')
            );

            if (!$result['ok']) {
                return self::FAILURE;
            }
        }

        if ($input->getOption('test-ticket')) {
            // A real session is needed so the ticket has an author to report.
            $this->loadUserSession((string) $input->getOption('user'));

            $result = TestTicket::create();

            $output->writeln(
                ($result['ok'] ? '<info>' : '<error>') . $result['message'] . ($result['ok'] ? '</info>' : '</error>')
            );

            if (!$result['ok']) {
                return self::FAILURE;
            }
        }

        if (($target = $input->getOption('ping')) !== null) {
            $peer = $target === 'master' ? Agent::master() : null;

            if ($peer === null && $target !== 'master') {
                $candidate = new Agent();
                $peer = $candidate->getFromDB((int) $target) ? $candidate : null;
            }

            if ($peer === null) {
                $output->writeln('<error>unknown peer</error>');

                return self::FAILURE;
            }

            $result = Ping::run($peer);

            $output->writeln(
                ($result['ok'] ? '<info>' : '<error>') . $result['message'] . ($result['ok'] ? '</info>' : '</error>')
            );

            if (!$result['ok']) {
                return self::FAILURE;
            }
        }

        if ($input->getOption('flush')) {
            $delivered = Outbox::flush(100);
            $output->writeln(sprintf('<info>%d delivery(ies) succeeded.</info>', $delivered));
        }

        if ($input->getOption('show') || $values === []) {
            $this->show($output);
        }

        return self::SUCCESS;
    }

    private function show(OutputInterface $output): void
    {
        $status = ApiServer::pong();

        $output->writeln('');
        $output->writeln('<comment>Pellissari Sync</comment>');
        $output->writeln(sprintf('  role             : %s', $status['role'] ?: '(not configured)'));
        $output->writeln(sprintf('  instance uuid    : %s', $status['uuid']));
        $output->writeln(sprintf('  plugin version   : %s', $status['plugin_version']));
        $output->writeln(sprintf('  sync category id : %d', Config::syncCategoryId()));
        $output->writeln(sprintf('  mirrored tickets : %d', $status['mirrored']));
        $output->writeln(sprintf('  pending outbox   : %d', Outbox::countPending()));

        if (Config::isMaster()) {
            $output->writeln(sprintf('  enrollment token : %s', Config::enrollmentToken()));
            $output->writeln(sprintf(
                '  registered agents: %d',
                countElementsInTable(Agent::getTable(), ['is_master' => 0])
            ));
        }

        if (Config::isAgent()) {
            $output->writeln(sprintf('  master url       : %s', Config::masterUrl()));
            $output->writeln(sprintf('  handshake        : %s', (string) Config::get('handshake_status')));

            $error = (string) Config::get('last_handshake_error');
            if ($error !== '') {
                $output->writeln(sprintf('  last error       : <error>%s</error>', $error));
            }
        }

        $output->writeln('');

        foreach ($this->db->request(['FROM' => Agent::getTable(), 'ORDER' => 'id ASC']) as $row) {
            $output->writeln(sprintf(
                '  peer #%d %s [%s] url=%s entity=%d contact=%s',
                $row['id'],
                $row['name'],
                $row['link_status'],
                $row['url'] ?: '-',
                $row['entities_id'],
                $row['last_contact'] ?: 'never'
            ));
        }

        $output->writeln(sprintf('  rights: %s / %s', Schema::RIGHT_AGENT, Schema::RIGHT_MIRROR));
    }
}
