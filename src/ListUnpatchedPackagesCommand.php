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

#[AsCommand(name: 'list-unpatched-packages', description: 'Lists packages that are *not* patched by the PR')]
class ListUnpatchedPackagesCommand extends Command
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

        $client = HttpClient::create();
        $diff = $client->request('GET', $data['pull_request']['diff_url'], ['auth_bearer' => $input->getArgument('github_token')])->getContent();

        preg_match_all('{^diff --git a/(([^/]++/[^/]++)/.*) b/\1$}m', $diff, $matches, \PREG_PATTERN_ORDER);

        $patchedPackages = array_flip($matches[2]);

        foreach (glob('*/*') as $package) {
            if (!isset($patchedPackages[$package])) {
                $output->writeln($package);
            }
        }

        return 0;
    }
}
