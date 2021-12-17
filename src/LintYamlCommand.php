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
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'lint:yaml', description: 'Validates the content of yaml files')]
class LintYamlCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exit = 0;

        while (false !== $file = fgets(\STDIN)) {
            $file = substr($file, 0, -1);
            $this->validate($file, $output, $exit);
        }

        return $exit;
    }

    private function validate(string $file, OutputInterface $output, int &$exit)
    {
        $parser = new Parser();
        $content = file_get_contents($file);

        $prevErrorHandler = set_error_handler(function ($level, $message, $file, $line) use (&$prevErrorHandler, $parser) {
            if (\E_USER_DEPRECATED === $level) {
                throw new ParseException($message, $parser->getRealCurrentLineNb() + 1);
            }

            return $prevErrorHandler ? $prevErrorHandler($level, $message, $file, $line) : false;
        });

        try {
            $data = $parser->parse($content, Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            $output->writeln(sprintf('::error file=%s,line=%s::%s', $file, $e->getParsedLine(), $e->getMessage()));
            $exit = 1;

            return;
        } finally {
            restore_error_handler();
        }

        if (null === $data || !preg_match('{^[^/]+/[^/]+/[^/]+/config/packages/}', $file)) {
            return;
        }

        if (!\is_array($data)) {
            $output->writeln(sprintf('::error file=%s::A configuration array is expected', $file));
            $exit = 1;

            return;
        }

        foreach ($data as $k => $v) {
            if (!\in_array($v, ['', null, []], true)) {
                continue;
            }

            $v = preg_quote($k);
            foreach (file($file) as $i => $line) {
                if (preg_match("{^$v\s*:}", $line)) {
                    $line = 1 + $i;
                    break;
                }
            }

            if (\is_int($line)) {
                $output->writeln(sprintf('::error file=%s,line=%s::"%s" entry should be removed as it is empty', $file, $line, $k));
            } else {
                $output->writeln(sprintf('::error file=%s::"%s" entry should be removed as it is empty', $file, $k));
            }
            $exit = 1;
        }
    }
}
