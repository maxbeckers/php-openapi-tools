<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Command;

use MaxBeckers\OpenApiTools\Document\DocumentBundler;
use MaxBeckers\OpenApiTools\Document\JsonDocumentReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bundle', description: 'Bundle local OpenAPI references into a new JSON document')]
final class BundleCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('input', InputArgument::REQUIRED, 'OpenAPI document path');
        $this->addArgument('output', InputArgument::REQUIRED, 'New JSON output path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $destination = (string) $input->getArgument('output');
            if (strtolower(pathinfo($destination, PATHINFO_EXTENSION)) !== 'json') {
                throw new \InvalidArgumentException('Bundle output must use a .json extension.');
            }
            $document = (new DocumentBundler(new JsonDocumentReader()))->bundle((string) $input->getArgument('input'));
            $contents = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $stream = @fopen($destination, 'xb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to create output; it must not already exist: ' . $destination);
            }
            try {
                if (fwrite($stream, $contents) !== strlen($contents)) {
                    throw new \RuntimeException('Unable to write complete output: ' . $destination);
                }
            } finally {
                fclose($stream);
            }
            $output->writeln('<info>Bundle written.</info>');

            return Command::SUCCESS;
        } catch (\RuntimeException | \InvalidArgumentException | \JsonException $exception) {
            $output->writeln('<error>Bundle failed: ' . OutputFormatter::escape($exception->getMessage()) . '</error>');

            return Command::FAILURE;
        }
    }
}
