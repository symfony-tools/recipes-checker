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
        'dotenv' => 0,
        'env' => 0,
        'makefile' => 0,
        'gitignore' => 0,
        'post-install-output' => 0,
        'aliases' => 0,
        'container' => 0,
        'conflict' => 0,
        'dockerfile' => 0,
        'docker-compose' => 0,
        'add-lines' => 0,
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
            if ($empty && !is_file("$package/$version/post-install.txt") && 'all' === current($data['bundles'] ?? [])) {
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

            if (isset($data['add-lines'])) {
                if (!$this->isAddLinesValid($data['add-lines'], $manifest, $output)) {
                    $exit = 1;
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

    private function isAddLinesValid(mixed $data, string $manifest, OutputInterface $output)
    {
        if (!is_array($data)) {
            $output->writeln(sprintf('::error file=%s::"add-lines" must be an array', $manifest));

            return false;
        }

        $isValid = true;
        foreach ($data as $index => $addLine) {
            foreach (['file', 'content', 'position'] as $key) {
                if (!isset($addLine[$key])) {
                    $output->writeln(sprintf('::error file=%s::"add-lines" (index %d) must have a "%s" key', $manifest, $index, $key));
                    $isValid = false;

                    continue;
                }

                if (!is_string($addLine[$key])) {
                    $output->writeln(sprintf('::error file=%s::"add-lines" (index %d) has a "%s" key but it must be a string value', $manifest, $index, $key));

                    $isValid = false;
                }
            }

            if (isset($addLine['position'])) {
                $validPositions = ['top', 'bottom', 'after_target'];
                if (!\in_array($addLine['position'], $validPositions, true)) {
                    $output->writeln(sprintf('::error file=%s::"add-lines" (index %d) must have a "position" key with one of the following values: "%s"', $manifest, $index, implode('", "', $validPositions)));

                    $isValid = false;
                }

                if ('after_target' === $addLine['position']) {
                    if (!isset($addLine['target'])) {
                        $output->writeln(sprintf('::error file=%s::"add-lines" (index %d) must have a "target" key when "position" is "after_target"', $manifest, $index));

                        $isValid = false;
                    } elseif (!is_string($addLine['target'])) {
                        $output->writeln(sprintf('::error file=%s::"add-lines" (index %d) has a "target" key but it must be a string value', $manifest, $index));

                        $isValid = false;
                    }
                }
            }
        }

        return $isValid;
    }
}
