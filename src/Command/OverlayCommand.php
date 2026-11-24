<?php

declare(strict_types=1);

namespace MaxBeckers\OpenApiTools\Command;

use MaxBeckers\OpenApiTools\Document\JsonDocumentReader;
use MaxBeckers\OpenApiTools\Document\LocalOverlayReference;
use MaxBeckers\OpenApiTools\Document\OverlayApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'overlay', description: 'Apply supported Overlay 1.0, 1.1 or 1.2 documents to an OpenAPI document')]
final class OverlayCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('input', InputArgument::REQUIRED, 'OpenAPI document path');
        $this->addArgument('output', InputArgument::REQUIRED, 'New JSON output path in the input directory');
        $this->addOption('overlay', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Overlay document path, in application order');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $inputPath = (string) $input->getArgument('input');
            $outputPath = (string) $input->getArgument('output');
            $root = realpath($inputPath);
            $destinationDirectory = realpath(dirname($outputPath));
            if ($root === false || !is_file($root) || $destinationDirectory === false || $destinationDirectory !== dirname($root)) {
                throw new \InvalidArgumentException('Input must be a file and output must be in the same existing directory.');
            }
            if (strtolower(pathinfo($outputPath, PATHINFO_EXTENSION)) !== 'json') {
                throw new \InvalidArgumentException('Overlay output must use a .json extension.');
            }
            $overlays = $input->getOption('overlay');
            if (!is_array($overlays) || $overlays === []) {
                throw new \InvalidArgumentException('Supply at least one --overlay.');
            }
            $reader = new JsonDocumentReader();
            $document = $reader->read($root);
            if (!$document instanceof \stdClass) {
                throw new \InvalidArgumentException('Input must be an OpenAPI document object.');
            }
            $inputSelf = isset($document->{'$self'}) && is_string($document->{'$self'}) ? $document->{'$self'} : null;
            $applier = new OverlayApplier();
            foreach ($overlays as $path) {
                if (!is_string($path)) {
                    throw new \InvalidArgumentException('Invalid overlay path.');
                }
                $overlay = $reader->read($path);
                if (!$overlay instanceof \stdClass) {
                    throw new \InvalidArgumentException('Overlay must be an object: ' . $path);
                }
                if (property_exists($overlay, 'extends')) {
                    $self = property_exists($overlay, '$self') ? $overlay->{'$self'} : null;
                    if (($self !== null && !is_string($self)) || !is_string($overlay->extends)
                        || !LocalOverlayReference::extendsInput($overlay->extends, $self, $path, $root, $inputSelf)) {
                        throw new \InvalidArgumentException('Overlay extends must identify the local input document: ' . $path);
                    }
                }
                try {
                    $document = $applier->apply($document, $overlay);
                } catch (\InvalidArgumentException $exception) {
                    throw new \InvalidArgumentException('Overlay ' . $path . ': ' . $exception->getMessage(), 0, $exception);
                }
            }
            $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $stream = @fopen($outputPath, 'xb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to create output; it must not already exist: ' . $outputPath);
            }
            try {
                if (fwrite($stream, $encoded) !== strlen($encoded)) {
                    throw new \RuntimeException('Unable to write complete output: ' . $outputPath);
                }
            } finally {
                fclose($stream);
            }
            $output->writeln('<info>Overlay output written.</info>');

            return Command::SUCCESS;
        } catch (\RuntimeException | \InvalidArgumentException | \JsonException $e) {
            $output->writeln('<error>Overlay failed: ' . OutputFormatter::escape($e->getMessage()) . '</error>');

            return Command::FAILURE;
        }
    }
}
