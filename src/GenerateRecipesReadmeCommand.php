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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'generate:recipes-readme', description: 'Generates a "README" containing a list of all recipes.')]
class GenerateRecipesReadmeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('index_path', InputArgument::REQUIRED, 'Path to the local index.json')
            ->addOption('contrib', null, InputOption::VALUE_NONE, 'Is this the contrib repository?')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $indexPath = $input->getArgument('index_path');
        $isContrib = $input->getOption('contrib');

        if (!file_exists($indexPath)) {
            throw new \InvalidArgumentException(sprintf('Cannot find index JSON file "%s".', $indexPath));
        }

        $data = json_decode(file_get_contents($indexPath), true);

        $aliases = $this->organizeAliases($data['aliases']);
        $hasAliases = count($aliases) > 0;

        $contentLines = [];
        $contentLines[] = '# List of Recipes';
        $contentLines[] = '';
        if ($isContrib) {
            $contentLines[] = 'Additional recipes can be found on the [Main Recipes Repository](https://github.com/symfony/recipes/blob/flex/main/RECIPES.md)';
        } else {
            $contentLines[] = 'Additional recipes can be found on the [Contrib Recipes Repository](https://github.com/symfony/recipes-contrib/blob/flex/main/RECIPES.md)';
        }
        $contentLines[] = '';

        // Package | Latest recipe | Aliases
        // [symfony/framework-bundle](https://packagist) | [5.4](github.com/.../) | framework-bundle
        $contentLines[] = '| Package | Latest Recipe |'.($hasAliases ? ' Aliases |' : '');
        $contentLines[] = '| --- | --- |'.($hasAliases ? ' --- |' : '');
        foreach ($data['recipes'] as $package => $versions) {
            $latestVersion = array_pop($versions);
            $line = sprintf('| [%s](https://packagist.org/packages/%s) | [%s](%s/%s) |', $package, $package, $latestVersion, $package, $latestVersion);
            if ($hasAliases) {
                $styledAliases = array_map(function ($alias) {
                    return sprintf('`%s`', $alias);
                }, $aliases[$package] ?? []);
                $line .= sprintf(' %s |', implode(', ', $styledAliases));
            }

            $contentLines[] = $line;
        }

        echo implode("\n", $contentLines);

        return 0;
    }

    private function organizeAliases(array $aliases): array
    {
        $byPackageAliases = [];
        foreach ($aliases as $alias => $package) {
            if (!isset($byPackageAliases[$package])) {
                $byPackageAliases[$package] = [];
            }

            $byPackageAliases[$package][] = $alias;
        }

        return $byPackageAliases;
    }
}
