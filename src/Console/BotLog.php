<?php

namespace RadioChatBox\Console;

use RadioChatBox\Services\LlmAccount;
use RadioChatBox\Services\LlmLog;
use RadioChatBox\Services\LlmPricing;
use RadioChatBox\Services\SettingsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bot:log` — recent LLM calls with token usage and cost, so a bad or truncated
 * reply can be traced. `--problems` shows only failures and truncations.
 */
class BotLog extends Command
{
    protected function configure(): void
    {
        $this->setName('bot:log')
            ->setDescription('Recent LLM calls with token usage and cost')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many entries to show', '10')
            ->addOption('problems', null, InputOption::VALUE_NONE, 'Show only failures and truncations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = new SettingsService();
        $llmLog   = new LlmLog($settings);
        $summary  = $llmLog->summary(24);
        $currency = $summary['currency'] ?: LlmPricing::CURRENCY;

        $output->writeln('LLM calls, last 24h');
        $output->writeln(sprintf(
            '  %s call(s), %s error(s), %s truncated | %s tokens (%s on reasoning) | ~%s | avg %sms',
            $summary['calls'] ?? 0,
            $summary['errors'] ?? 0,
            $summary['truncated'] ?? 0,
            $summary['total_tokens'] ?? 0,
            $summary['reasoning_tokens'] ?? 0,
            LlmPricing::format((float) ($summary['cost'] ?? 0), $currency),
            $summary['avg_duration_ms'] ?? '-'
        ));

        if ((int) ($summary['uncosted_calls'] ?? 0) > 0) {
            $output->writeln(sprintf(
                '  (%s call(s) have no cost: no price configured for the model - see bot_llm_prices)',
                $summary['uncosted_calls']
            ));
        }

        $account = new LlmAccount($settings);
        $balance = $account->balance();
        if ($balance !== null) {
            $output->writeln(sprintf(
                '  balance: %s%s',
                LlmPricing::format($balance['total'], $balance['currency']),
                $balance['is_available'] ? '' : ' (INSUFFICIENT - calls will fail)'
            ));
        }

        $real = $account->realSpend(24);
        if ($real !== null) {
            $output->writeln(sprintf(
                '  actually spent (from %d balance readings): %s%s',
                $real['readings'],
                LlmPricing::format($real['spent'], $real['currency']),
                $real['topped_up'] > 0
                    ? ' (+' . LlmPricing::format($real['topped_up'], $real['currency']) . ' topped up)'
                    : ''
            ));
        }

        if (!$llmLog->isEnabled()) {
            $output->writeln('  (logging is OFF - enable it in Admin > Settings)');
        }

        $entries = $llmLog->recent((int) $input->getOption('limit'), (bool) $input->getOption('problems'));
        if ($entries === []) {
            $output->writeln('No entries.');
            return Command::SUCCESS;
        }

        foreach (array_reverse($entries) as $entry) {
            $output->writeln(sprintf(
                '%s  %s -> %s  %s  finish=%s  %sms',
                $entry['created_at'],
                $entry['fake_nickname'] ?? '?',
                $entry['peer_username'] ?? '?',
                $entry['model'],
                $entry['finish_reason'] ?? '-',
                $entry['duration_ms'] ?? '-'
            ));

            if ($entry['cost'] !== null) {
                $output->writeln('    cost: ~' . LlmPricing::format(
                    (float) $entry['cost'],
                    (string) ($entry['currency'] ?: $currency)
                ));
            }

            $usage = json_decode((string) $entry['usage'], true) ?: [];
            $output->writeln(sprintf(
                '    tokens: prompt=%s completion=%s reasoning=%s | reasoning setting: %s, budget: %s',
                $usage['prompt_tokens'] ?? '-',
                $usage['completion_tokens'] ?? '-',
                $usage['completion_tokens_details']['reasoning_tokens'] ?? 0,
                $entry['reasoning'] ? 'on' : 'off',
                $entry['max_tokens']
            ));

            if (!empty($entry['reply'])) {
                $output->writeln('    reply: ' . mb_substr((string) $entry['reply'], 0, 160));
            }
            if (!empty($entry['error'])) {
                $output->writeln('    ERROR: ' . $entry['error']);
            }
        }

        return Command::SUCCESS;
    }
}
