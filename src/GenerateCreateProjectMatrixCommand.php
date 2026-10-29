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
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'generate:create-project-matrix', description: 'Generates the matrix of skeletons and PHP versions that can install the packages listed on stdin')]
class GenerateCreateProjectMatrixCommand extends Command
{
    private const PLATFORMS = [
        ['skeleton' => '^6', 'stability' => 'stable', 'php' => '8.1'],
        ['skeleton' => '^7', 'stability' => 'stable', 'php' => '8.2'],
        ['skeleton' => '^6', 'stability' => 'stable', 'php' => '8.4'],
        ['skeleton' => '^7', 'stability' => 'stable', 'php' => '8.4'],
        ['skeleton' => '^8', 'stability' => 'dev', 'php' => '8.4'],
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('summary', InputArgument::OPTIONAL, 'The file where the Markdown table of the supported versions is written')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $log = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $packages = [];

        while (false !== $package = fgets(\STDIN)) {
            $package = substr($package, 0, -1);

            $versions = \array_slice(scandir($package), 2);
            usort($versions, 'version_compare');

            if ($versions) {
                $packages[] = $package.':^'.array_pop($versions);
            }
        }

        if (!$packages) {
            $output->writeln('[]');

            return 0;
        }

        $platforms = $this->probePlatforms($packages, $log);
        $installable = array_filter($platforms, fn ($platform) => 0 === $platform['code']);
        $matrix = [];

        foreach ($platforms as $i => $platform) {
            if ($installable && 2 === $platform['code']) {
                continue;
            }

            $matrix[] = [
                'skeleton' => $platform['skeleton'],
                'stability' => $platform['stability'],
                'symfony' => $platform['symfony'],
                'php' => $platform['php'],
                // Without any installable combination, only the newest one fails, to show the error
                'strict' => $installable ? 0 === $platform['code'] : array_key_last($platforms) === $i,
            ];
        }

        $output->writeln(json_encode($matrix, \JSON_UNESCAPED_SLASHES));

        if (!array_filter($platforms, fn ($platform) => null !== $platform['code'])) {
            return 0;
        }

        if ($installable) {
            $symfony = array_column($installable, 'symfony');
            $php = array_column($installable, 'php');
            usort($symfony, 'version_compare');
            usort($php, 'version_compare');
            $summary = sprintf('Lowest versions that can install the package(s) among the tested combinations: Symfony %s, PHP %s', $symfony[0], $php[0]);
        } else {
            $summary = 'No combination can install the package(s), so all of them are tested anyway to show the error.';
            $log->writeln('::warning::'.$summary);
        }

        if ($file = $input->getArgument('summary')) {
            $markdown = "## Supported Symfony and PHP versions\n\n$summary\n\n| Symfony | PHP | Status |\n| --- | --- | --- |\n";
            foreach ($platforms as $platform) {
                $markdown .= sprintf("| %s | %s | %s |\n", $platform['symfony'], $platform['php'], match ($platform['code']) {
                    0 => '✅ Tested',
                    2 => $installable ? '⏭️ Not installable, skipped' : '❌ Not installable',
                    null => '❔ Could not check, tested anyway',
                });
            }
            (new Filesystem())->dumpFile($file, $markdown."\n");
        }

        return 0;
    }

    private function probePlatforms(array $packages, OutputInterface $log): array
    {
        $filesystem = new Filesystem();
        $tmpDir = sys_get_temp_dir().'/create-project-matrix-'.bin2hex(random_bytes(4));
        $env = [
            // A Flex installed in its own home applies the "extra.symfony.require" of the skeletons without installing them
            'COMPOSER_HOME' => $tmpDir.'/composer',
            'COMPOSER_CACHE_DIR' => trim($this->composer(['config', '--global', 'cache-dir'])->mustRun()->getOutput()),
        ];
        $symfony = [];
        $required = [];

        try {
            $filesystem->dumpFile($env['COMPOSER_HOME'].'/composer.json', '{"config":{"allow-plugins":{"symfony/flex":true}}}');
            $flex = $this->composer(['global', 'require', '--no-progress', '--no-plugins', '--no-scripts', 'symfony/flex'], $env);
            $created = [];
            foreach (self::PLATFORMS as $i => $platform) {
                $created[$i] = $this->composer(['create-project', '--no-progress', '--no-plugins', '--no-scripts', '--no-install', '--ignore-platform-reqs', '--stability='.$platform['stability'], 'symfony/skeleton:'.$platform['skeleton'], "$tmpDir/$i"], $env);
            }
            $this->runInParallel([$flex, ...$created]);

            foreach (self::PLATFORMS as $i => $platform) {
                if (!$flex->isSuccessful() || !$created[$i]->isSuccessful()) {
                    continue;
                }

                $dir = "$tmpDir/$i";
                $composerJson = json_decode(file_get_contents("$dir/composer.json"));
                $composerJson->config ??= new \stdClass();
                $composerJson->config->platform = (object) ['php' => $platform['php'].'.99'];
                $composerJson->{'minimum-stability'} = 'dev';
                $filesystem->dumpFile("$dir/composer.json", json_encode($composerJson, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

                $symfony[$i] = $composerJson->extra->symfony->require ?? null;
                $required[$i] = $this->composer(['require', '-W', '--dry-run', '--no-progress', '--no-scripts', ...$packages], $env, $dir);
            }
            $this->runInParallel($required);
        } finally {
            $filesystem->remove($tmpDir);
        }

        $this->log($log, 'Install Flex', $flex);
        $platforms = [];

        foreach (self::PLATFORMS as $i => $platform) {
            $exitCode = ($required[$i] ?? null)?->getExitCode();
            $platform['symfony'] = str_replace('.*', '', $symfony[$i] ?? substr($platform['skeleton'], 1));
            // 0: installable, 2: not installable, null: unknown
            $platform['code'] = \in_array($exitCode, [0, 2], true) ? $exitCode : null;
            $platforms[] = $platform;

            $this->log($log, sprintf('Symfony %s, PHP %s', $platform['symfony'], $platform['php']), $created[$i], $required[$i] ?? null);
        }

        return $platforms;
    }

    private function composer(array $arguments, array $env = [], ?string $cwd = null): Process
    {
        return new Process(['composer', '--no-interaction', ...$arguments], $cwd, $env, null, null);
    }

    private function log(OutputInterface $log, string $title, ?Process ...$processes): void
    {
        $log->writeln('::group::'.$title);
        foreach (array_filter($processes) as $process) {
            $log->write('$ '.$process->getCommandLine()."\n".$process->getOutput().$process->getErrorOutput(), false, OutputInterface::OUTPUT_RAW);
        }
        $log->writeln('::endgroup::');
    }

    /**
     * @param Process[] $processes
     */
    private function runInParallel(array $processes): void
    {
        foreach ($processes as $process) {
            $process->start();
        }

        foreach ($processes as $process) {
            $process->wait();
        }
    }
}
