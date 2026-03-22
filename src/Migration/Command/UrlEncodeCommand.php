<?php namespace DBDiff\Migration\Command;

use DBDiff\Migration\Config\DsnPasswordEncoder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * dbdiff url:encode <password>
 *
 * Percent-encodes a raw password so it can be safely embedded in a
 * --server1-url / --server2-url / --db-url connection string.
 *
 * All characters except RFC 3986 unreserved characters (A–Z a–z 0–9 - _ . ~)
 * are encoded, including @ # ? / % : + and everything else.
 *
 * Usage:
 *   dbdiff url:encode 'my$ecret#pass'
 *   echo 'my$ecret#pass' | dbdiff url:encode
 *
 * Capture in a shell script:
 *   PASS=$(dbdiff url:encode 'my$ecret#pass')
 *   dbdiff diff --server1-url="postgres://user:${PASS}@host:5432/db" ...
 */
#[AsCommand(name: 'url:encode', description: 'Percent-encode a password for safe use in a --server-url connection string')]
class UrlEncodeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'password',
                InputArgument::OPTIONAL,
                'The raw password to encode. Omit to read from stdin.'
            )
            ->setHelp(<<<'HELP'
Percent-encodes a password so it can be safely embedded in a
<info>--server1-url</info> / <info>--server2-url</info> / <info>--db-url</info> connection string.

  <info>dbdiff url:encode 'my$ecret#pass'</info>
  <info>echo 'my$ecret#pass' | dbdiff url:encode</info>

Capture the result for use in another command:

  <info>PASS=$(dbdiff url:encode 'my$ecret#pass')</info>
  <info>dbdiff diff --server1-url="postgres://user:${PASS}@host:5432/db" ...</info>

All characters except RFC 3986 unreserved characters (A-Z a-z 0-9 - _ . ~)
are encoded.  This includes <comment>@</comment>  <comment>#</comment>  <comment>?</comment>  <comment>/</comment>  <comment>%</comment>  <comment>:</comment>  <comment>+</comment> and all other punctuation.

The only character that cannot be handled by pasting a raw password directly
into a URL is a literal <comment>%</comment> followed by two hex digits (e.g. <comment>abc%12def</comment>).
This command encodes it correctly: <comment>%12</comment> → <comment>%2512</comment> in the URL.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getArgument('password');

        if ($raw === null) {
            if (!stream_isatty(STDIN)) {
                $raw = rtrim(stream_get_contents(STDIN), "\n\r");
            } else {
                $output->writeln('<error>No password supplied.</error>');
                $output->writeln('  Usage:  dbdiff url:encode <password>');
                $output->writeln('          echo <password> | dbdiff url:encode');
                return Command::FAILURE;
            }
        }

        $output->writeln(DsnPasswordEncoder::encode($raw));

        return Command::SUCCESS;
    }
}
