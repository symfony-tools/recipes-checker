<?php

/*
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;

#[AsCommand(name: 'lint:pull-request', description: 'Ensures the PR can be accepted')]
class LintPullRequestCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('event_path', InputArgument::REQUIRED, 'The path where the GitHub event is stored')
            ->addArgument('github_token', InputArgument::REQUIRED, 'The GitHub API token to use')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = json_decode(file_get_contents($input->getArgument('event_path')), true);
        $exit = 0;

        if (!preg_match('/^[ |\t]*License[ |\t]+MIT[ |\t]*\r?$/mi', $data['pull_request']['body'])) {
            $output->writeln('::error::Contributions must be licensed under MIT (add the pull request header in the description)');
            $exit = 1;
        }

        $client = HttpClient::create();
        $commits = $client->request('GET', $data['pull_request']['commits_url'], ['auth_bearer' => $input->getArgument('github_token')]);

        foreach ($commits->toArray() as $commit) {
            if (1 < \count($commit['parents'])) {
                $output->writeln('Pull requests should not have merge commits (please rebase)');
                $exit = 1;
                break;
            }
        }

        return $exit;
    }
}
