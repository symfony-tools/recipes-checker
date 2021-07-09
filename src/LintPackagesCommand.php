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
use Symfony\Component\HttpClient\HttpClient;

#[AsCommand(name: 'lint:packages', description: 'Ensures directories map to valid packages on packagist.org')]
class LintPackagesCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = HttpClient::create();

        $packages = [];

        foreach (glob('*/*') as $package) {
            $packages[] = [false, $package, $client->request('GET', "https://repo.packagist.org/p2/{$package}.json")];
        }

        $exit = 0;

        for ($i = 0; isset($packages[$i]); ++$i) {
            [$dev, $package, $response] = $packages[$i];
            unset($packages[$i]);

            if (200 !== $response->getStatusCode()) {
                $output->writeln(sprintf('::error::Package "%s" does not exist on Packagist', $package));
                $exit = 1;
                unset($packages[$package]);

                continue;
            }

            $data = $response->toArray();

            foreach (glob("$package/*") as $version) {
                $version = substr($version, 1 + \strlen($package));

                if (!preg_match('/^\d+\.\d+$/D', $version)) {
                    $output->writeln(sprintf('::error::Version "%s" is not valid, format is "x.y" where x and y are numbers for "%s"', $version, $package));
                    $exit = 1;
                    continue;
                }

                $found = false;
                foreach ($data['packages'][$package] as $versionData) {
                    $v = str_ends_with($version, '.0') ? substr($version, 0, -2) : $version;

                    if (str_starts_with($versionData['version_normalized'], $v.'.')) {
                        $found = true;
                        break;
                    }

                    if ($dev && str_starts_with($versionData['extra']['branch-alias'][$versionData['version'] ?? ''] ?? '', $v.'.')) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    if ($dev) {
                        $output->writeln(sprintf('::error::Version "%s" of "%s" does not exist on Packagist', $version, $package));
                        $exit = 1;
                    } else {
                        if (!$packages) {
                            $i = -1;
                        }
                        $packages[] = [true, $package, $client->request('GET', "https://repo.packagist.org/p2/{$package}~dev.json")];
                    }

                    continue;
                }

                if (isset($versionData['require']['symfony/symfony'])) {
                    $output->writeln(sprintf('::error::Package "%s/%s" must not depend on symfony/symfony; depend on explicit symfony/* packages instead', $package, $version));
                    $exit = 1;
                }

                if (isset($versionData['require']['symfony/security']) && !\in_array($package.'/'.$version, ['nelmio/security-bundle/2.4', 'symfony/security-bundle/3.3'], true)) {
                    $output->writeln(sprintf('::error::Package "%s/%s" must not depend on symfony/security; depend on explicit symfony/security-* packages instead', $package, $version));
                    $exit = 1;
                }

                if (!is_file("$package/$version/manifest.json")) {
                    continue;
                }

                $manifest = json_decode(file_get_contents("$package/$version/manifest.json"), true);
                if (empty($manifest['bundles']) && !empty($versionData['type'])) {
                    if ('symfony-bundle' === $versionData['type']) {
                        $output->writeln(sprintf('::error::You should register the bundle in the manifest as "%s/%s" is a Symfony bundle', $package, $version));
                        $exit = 1;
                    } elseif ('sylius-plugin' === $versionData['type']) {
                        $output->writeln(sprintf('::error::You should register the bundle in the manifest as "%s/%s" is a Sylius plugin', $package, $version));
                        $exit = 1;
                    } elseif ('sulu-plugin' === $versionData['type']) {
                        $output->writeln(sprintf('::error::You should register the bundle in the manifest as "%s/%s" is a Sulu bundle', $package, $version));
                        $exit = 1;
                    }
                }
            }
        }

        return $exit;
    }
}
