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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'lint:manifests', description: 'Checks manifest.json files')]
class LintManifestsCommand extends Command
{
    private const ALLOWED_KEYS = [
        'bundles' => 1,
        'copy-from-recipe' => 0,
        'copy-from-package' => 0,
        'composer-scripts' => 0,
        'env' => 0,
        'makefile' => 0,
        'gitignore' => 0,
        'post-install-output' => 0,
        'aliases' => 0,
        'container' => 0,
        'conflict' => 0,
        'dockerfile' => 0,
        'docker-compose' => 0,
    ];

    private const SPECIAL_FILES = ['.', '..', 'manifest.json', 'post-install.txt', 'Makefile'];

    protected function configure(): void
    {
        $this
            ->addOption('contrib')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exit = 0;
        $aliases = [];

        foreach (glob('*/*/*/manifest.json') as $manifest) {
            [$vendor, $package, $version] = explode('/', $manifest);
            $package = "$vendor/$package";
            $data = json_decode(file_get_contents($manifest), true);

            $empty = true;
            foreach (self::ALLOWED_KEYS as $key => $count) {
                if ($count ? \count($data[$key] ?? []) > $count : !empty($data[$key])) {
                    $empty = false;
                    break;
                }
            }
            if ($empty && !is_file("$package/$version/post-install.txt") && 'all' === current($data['bundles'])) {
                $output->writeln(sprintf('::error file=%s::Recipe is not needed as it only registers a bundle for all environments', $manifest));
                continue;
            }

            if (!isset($data['aliases'])) {
                // no-op
            } elseif ($input->getOption('contrib')) {
                $output->writeln(sprintf('::error file=%s::Aliases not supported in the contrib repository', $manifest));
                $exit = 1;
            } else {
                foreach ($data['aliases'] as $alias) {
                    if (\in_array($aliases, ['lock', 'nothing', 'mirrors', ''], true)) {
                        $output->writeln(sprintf('::error file=%s::Alias "%s" cannot be used as it\'s a special alias used by Composer', $manifest, $alias));
                        $exit = 1;
                    }
                    if (isset($aliases[$alias]) && $package !== $aliases[$alias]) {
                        $output->writeln(sprintf('::error file=%s::Alias "%s" also defined for "%s"', $manifest, $alias, $aliases[$alias]));
                        $exit = 1;
                    } else {
                        $aliases[$alias] = $package;
                    }
                }
            }

            if ($extraKeys = array_diff_key($data, self::ALLOWED_KEYS)) {
                $extraKeys = array_keys($extraKeys);
                $lastKey = array_pop($extraKeys);
                $extraKeys = $extraKeys ? 's: '.implode('", "', $extraKeys).' and' : ':';
                $output->writeln(sprintf('::error file=%s::Unsupported key%s "%s"', $manifest, $extraKeys, $lastKey));
                $exit = 1;
            }

            foreach (scandir("$package/$version") as $file) {
                $path = "$package/$version/$file";

                if (\in_array($file, self::SPECIAL_FILES, true)) {
                    if (is_file($path) && !preg_match('//u', file_get_contents($path))) {
                        $output->writeln(sprintf('::error file=%s::File "%s" must be UTF-8 encoded', $path, $file));
                        $exit = 1;
                    }
                    continue;
                }

                if (is_dir($path)) {
                    if (isset($data['copy-from-recipe'][$file.'/'])) {
                        // no-op
                    } elseif (isset($data['copy-from-recipe'][$file])) {
                        $output->writeln(sprintf('::error file=%s::Directory must be listed under "%s/" in the "copy-from-recipe" section', $manifest, $file));
                        $exit = 1;
                    } else {
                        $output->writeln(sprintf('::error file=%s::Directory must be listed under "%s/" in the "copy-from-recipe" section of "manifest.json"', $path, $file));
                        $exit = 1;
                    }
                } elseif (!isset($data['copy-from-recipe'][$file])) {
                    $output->writeln(sprintf('::error file=%s::File must be listed in the "copy-from-recipe" section of "manifest.json"', $path));
                    $exit = 1;
                }
            }
        }

        return $exit;
    }
}
