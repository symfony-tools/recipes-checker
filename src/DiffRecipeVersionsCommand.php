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

#[AsCommand(name: 'diff-recipe-versions', description: 'Displays the diff between versions of a recipe')]
class DiffRecipeVersionsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('endpoint', InputArgument::OPTIONAL, 'The Flex endpoint', '')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($endpoint = $input->getArgument('endpoint')) {
            $endpoint = <<<EOMD

## How to test these changes in your application

 1. Define the `SYMFONY_ENDPOINT` environment variable:
    ```sh
    # On *nix and Mac
    export SYMFONY_ENDPOINT={$endpoint}
    # On Windows
    SET SYMFONY_ENDPOINT={$endpoint}
    ```

 2. Install the package(s) related to this recipe using `composer require`

 3. Don't forget to unset the `SYMFONY_ENDPOINT` environment variable when done:
    ```sh
    # On *nix and Mac
    unset SYMFONY_ENDPOINT
    # On Windows
    SET SYMFONY_ENDPOINT=
    ```

EOMD;
        }

        $head = <<<EOMD
Thanks for the PR 😍
{$endpoint}
## Diff between recipe versions

In order to help with the review stage, I'm in charge of computing the diff between the various versions of patched recipes.
I'm going keep this comment up to date with any updates of the attached patch.

EOMD;

        while (false !== $package = fgets(STDIN)) {
            $package = substr($package, 0, -1);

            $versions = scandir($package,  SCANDIR_SORT_NONE);
            usort($versions, 'version_compare');
            $versions = array_slice($versions, 2);
            $previousVersion = array_shift($versions);

            if (!$versions) {
                continue;
            }

            if (null !== $head) {
                $output->writeln($head);
                $head = null;
            }
            $output->writeln(sprintf("### %s\n", $package));

            foreach ($versions as $version) {
                $diff = shell_exec(sprintf('LC_ALL=C git diff --color=never --no-index %s/%s %1$s/%s', $package, $previousVersion, $version));

                $output->writeln("<details>");
                $output->writeln(sprintf("<summary>%s <em>vs</em> %s</summary>\n", $previousVersion, $version));
                $output->writeln("```diff\n$diff```");
                $output->writeln("\n</details>\n");

                $previousVersion = $version;
            }
        }

        if (null !== $head) {
            $output->writeln($head);
        }

        return 0;
    }
}
