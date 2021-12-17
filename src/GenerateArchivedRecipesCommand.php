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
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'generate:archived-recipes', description: 'Generates an "archived" directory containing the history of every recipe.')]
class GenerateArchivedRecipesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::REQUIRED, 'Path to the local recipes repository')
            ->addArgument('branch', InputArgument::REQUIRED, 'Branch on the recipes repository to use')
            ->addArgument('output_directory', InputArgument::REQUIRED, 'The directory where generated files should be stored')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $recipesDirectory = $input->getArgument('directory');
        $branch = $input->getArgument('branch');
        $outputDir = $input->getArgument('output_directory');
        $filesystem = new Filesystem();

        if (!file_exists($recipesDirectory)) {
            throw new \InvalidArgumentException(sprintf('Cannot find directory "%s"', $recipesDirectory));
        }

        if (!file_exists($outputDir)) {
            $filesystem->mkdir($outputDir);
        }

        $process = new Process(['git', 'checkout', $branch], $recipesDirectory);
        $process->mustRun();

        $tmpDir = sys_get_temp_dir().'/_flex_archive/';
        $filesystem->mkdir($tmpDir);

        $process = (new Process(['git', 'rev-list', '--count', $branch, '--no-merges'], $recipesDirectory))->mustRun();
        // an imperfect estimate of the total commits
        $totalCommits = (int) trim($process->getOutput());
        $progress = new ProgressBar($output, $totalCommits);
        while (true) {
            // most arguments to the command do not matter for us and so are hardcoded
            $process = Process::fromShellCommandline(
                sprintf('git ls-tree HEAD */*/* | php %s/run generate:flex-endpoint symfony/recipes master flex/main $OUTPUT_DIR', realpath(__DIR__.'/../')),
                $recipesDirectory
            );
            // this WILL occasionally fail: some legacy recipes were invalid and pointed to non-existent files
            $process->run(null, ['OUTPUT_DIR' => $tmpDir]);

            $finder = new Finder();
            $finder->in($tmpDir.'/archived')
                ->name('*.json')
                ->notName('index.json');

            foreach ($finder as $file) {
                $data = json_decode($file->getContents(), true);
                $manifests = array_values($data['manifests']);
                $treeRef = $manifests[0]['ref'];

                $parts = explode('.', $file->getRelativePathname());
                $dottedPackageName = implode('.', [$parts[0], $parts[1]]);

                $targetPath = sprintf('%s/%s/%s.json', $outputDir, $dottedPackageName, $treeRef);
                if (!file_exists(\dirname($targetPath))) {
                    $filesystem->mkdir(\dirname($targetPath));
                }
                if (!file_exists($targetPath)) {
                    file_put_contents($targetPath, $file->getContents());
                }
            }

            $process = new Process(['git', 'checkout', 'HEAD^1'], $recipesDirectory);
            $process->mustRun();

            $process = (new Process(['git', 'rev-list', '--count', 'HEAD', '--no-merges'], $recipesDirectory))->mustRun();
            $newCount = (int) trim($process->getOutput());
            // when we've come to the final commit, this will be 1
            if (1 === $newCount) {
                break;
            }
            $progress->setProgress($totalCommits - $newCount);
        }

        $progress->finish();
        $filesystem->remove($tmpDir);

        return 0;
    }
}
