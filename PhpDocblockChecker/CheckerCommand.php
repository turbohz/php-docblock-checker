<?php
/**
 * PHP Docblock Checker
 *
 * @copyright    Copyright 2014, Block 8 Limited.
 * @license      https://github.com/Block8/php-docblock-checker/blob/master/LICENSE.md
 * @link         http://www.phptesting.org/
 */

namespace PhpDocblockChecker;

use DirectoryIterator;
use PHP_Token_Stream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to check a directory of PHP files for Docblocks.
 * @author Dan Cryer <dan@block8.co.uk>
 */
class CheckerCommand extends Command
{
    /**
     * @var string
     */
    protected $basePath = './';

    /**
     * @var bool
     */
    protected $verbose = true;

    /**
     * @var array
     */
    protected $report = array();

    /**
     * @var array
     */
    protected $exclude = array();

    /**
     * @var bool
     */
    protected $skipClasses = false;

    /**
     * @var bool
     */
    protected $skipMethods = false;

    /**
     * @var OutputInterface
     */
    protected $output;

    protected function configure()
    {
        $this
            ->setName('check')
            ->setDescription('Check PHP files within a directory for appropriate use of Docblocks.')
            ->addOption('exclude', 'x', InputOption::VALUE_REQUIRED, 'Files and directories to exclude.', null)
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Directory to scan.', './')
            ->addOption('skip-classes', null, InputOption::VALUE_NONE, 'Don\'t check classes for docblocks.')
            ->addOption('skip-methods', null, InputOption::VALUE_NONE, 'Don\'t check methods for docblocks.')
            ->addOption('errors', 'e', InputOption::VALUE_NONE, 'Only check validity of docblocks.')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output JSON instead of a log.')
            ->addOption('oks', null, InputOption::VALUE_REQUIRED, 'Report OK classes.', true)
            ->addArgument('file', InputArgument::OPTIONAL, 'File to scan', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Process options:
        $exclude = $input->getOption('exclude');
        $json = $input->getOption('json');
        $file = $input->getArgument('file');
        $this->basePath = $input->getOption('directory');
        $this->verbose = !$json;
        $this->output = $output;
        $this->errors = $input->getOption('errors');
        $this->skipClasses = $input->getOption('skip-classes');
        $this->skipMethods = $input->getOption('skip-methods');
        $this->reportMissingClasses = !$this->skipClasses && !$this->errors;
        $this->reportMissingMethods = !$this->skipMethods && !$this->errors;
        $this->oks = filter_var($input->getOption('oks'), FILTER_VALIDATE_BOOLEAN);

        // Set up excludes:
        if (!is_null($exclude)) {
            $this->exclude = array_map('trim', explode(',', $exclude));
        }

        // Check base path ends with a slash:
        if (substr($this->basePath, -1) != '/') {
            $this->basePath .= '/';
        }

        // Process:

        if ($file) {
            $this->processFile($file);
        } else {
            $this->processDirectory();
        }

        // Output JSON if requested:
        if ($json) {
            print json_encode($this->report);
        }

        return count($this->report) ? 1 : 0;
    }

    protected function processDirectory($path = '')
    {
        $dir = new DirectoryIterator($this->basePath . $path);

        foreach ($dir as $item) {
            if ($item->isDot()) {
                continue;
            }

            $itemPath = $path . $item->getFilename();

            if (in_array($itemPath, $this->exclude)) {
                continue;
            }

            if ($item->isFile() && $item->getExtension() == 'php') {
                $this->processFile($itemPath);
            }

            if ($item->isDir()) {
                $this->processDirectory($itemPath . '/');
            }
        }
    }

    protected function processFile($file)
    {
        $stream = new PHP_Token_Stream($this->basePath . $file);

        $errorData = array();

        foreach($stream->getClasses() as $name => $class) {
            $errors = false;

            if ($this->reportMissingClasses && is_null($class['docblock'])) {
                $errors = true;

                $errorData = array(
                    'type' => 'class',
                    'file' => $file,
                    'class' => $name,
                    'line' => $class['startLine'],
                    'message' => 'Class is missing a docblock.'
                );
                
                $this->report[] = $errorData;

                if ($this->verbose) {
                    $this->displayError($errorData);
                }
            }

            if (!$this->skipMethods) {

                $errorData = array();

                foreach ($class['methods'] as $methodName => $method) {
                    if (is_null($method['docblock'])) {

                        if ($this->reportMissingMethods) {
                            $errors = true;
                            $errorData  = array(
                                'type' => 'method',
                                'file' => $file,
                                'class' => $name,
                                'method' => $methodName,
                                'line' => $method['startLine'],
                                'message' => 'Method is missing a docblock.'
                            );

                            $this->report[] = $errorData;

                            if ($this->verbose) {
                                $this->displayError($errorData);
                            }
                        }
                    } else {
                        $docblockParams = $this->getDocBlockParams($method['docblock']);
                        $signatureParams = $this->getMethodSignatureParams($method['signature']);

                        foreach ($docblockParams as $p) {
                            if (!in_array($p, $signatureParams)) {
                                $errorData  = array(
                                    'type' => 'method',
                                    'file' => $file,
                                    'class' => $name,
                                    'method' => $methodName,
                                    'line' => $method['startLine'],
                                    'message' => "Argument $p in DockBlock isn't used."
                                );
                                $this->report[] = $errorData;
                                if ($this->verbose) {
                                    $this->displayError($errorData);
                                }
                            }
                        }
                        foreach ($signatureParams as $p) {
                            if (!in_array($p, $docblockParams)) {
                                $errorData  = array(
                                    'type' => 'method',
                                    'file' => $file,
                                    'class' => $name,
                                    'method' => $methodName,
                                    'line' => $method['startLine'],
                                    'message' => "Argument $p isn't specified in DockBlock."
                                );
                                $this->report[] = $errorData;
                                if ($this->verbose) {
                                    $this->displayError($errorData);
                                }
                            }
                        }

                    }
                }
            }

            if (!$errors && $this->verbose && $this->oks) {
                $this->output->writeln($name . ' <info>OK</info>');
            }
        }


    }

    private function displayError($error)
    {
        if ($error['type'] === 'class') {
            $message = "$error[file]: $error[line]  - Class $error[class] > $error[message]";
        } elseif ($error['type'] === 'method') {
            $message = "$error[file]: $error[line] - Method $error[class]::$error[method] > $error[message]";
        }
        $this->output->writeln('<error>' . $message . '</error>');
    }

    private function getMethodSignatureParams($docblock)
    {
        $var = self::VARIABLE_MATCH;

        preg_match_all("/({$var})/", $docblock, $matches);
        return $matches[1];
    }

    private function getDocBlockParams($docblock)
    {
        $var = self::VARIABLE_MATCH;

        preg_match_all("/@param.+?({$var})/", $docblock, $matches);
        return $matches[1];
    }

    const VARIABLE_MATCH = '\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]+';
}
