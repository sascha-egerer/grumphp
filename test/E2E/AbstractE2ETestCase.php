<?php

declare(strict_types=1);

namespace GrumPHPTest\E2E;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractE2ETestCase extends TestCase
{
    /**
     * @var ExecutableFinder
     */
    protected $executableFinder;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var string
     */
    protected $hash;

    /**
     * @var string
     */
    protected $rootDir;

    protected function setUp()
    {
        $this->filesystem = new Filesystem();
        $this->executableFinder = new ExecutableFinder();

        $tmpDir = sys_get_temp_dir().$this->useCorrectDirectorySeparator('/grumpytests');
        $this->hash = md5(get_class($this).'::'.$this->getName());
        $this->rootDir = $tmpDir.$this->useCorrectDirectorySeparator('/'.$this->hash);

        $this->filesystem->remove($this->rootDir);
        $this->filesystem->mkdir($this->rootDir);

        // Basic actions
        $this->initializeGit();
        $this->appendToGitignore(['vendor']);
    }

    protected function tearDown()
    {
        $this->filesystem->remove($this->rootDir);
    }

    private function initializeGit()
    {
        $process = new Process([$this->executableFinder->find('git'), 'init'], $this->rootDir);
        $this->runCommand('install git', $process);
    }

    protected function appendToGitignore(array $paths)
    {
        $gitignore = $this->rootDir.$this->useCorrectDirectorySeparator('/.gitignore');
        $this->filesystem->appendToFile($gitignore, implode(PHP_EOL, $paths));
    }

    protected function initializeComposer(string $path): string
    {
        $process = new Process(
            [
                $this->executableFinder->find('composer'),
                'init',
                '--name=grumphp/testsuite'.$this->hash,
                '--type=library',
                '--require-dev=phpro/grumphp:*',
                '--repository='.json_encode([
                    'type' => 'path',
                    'url' => PROJECT_BASE_PATH,
                ]),
                '--no-interaction',
            ],
            $path
        );

        $this->runCommand('initialize composer', $process);

        $composerFile = $path.$this->useCorrectDirectorySeparator('/composer.json');

        $this->mergeComposerConfig($composerFile, [
            'autoload' => [
                'psr-4' => [
                    '' => 'src/',
                ],
            ],
        ]);

        return $composerFile;
    }

    protected function mergeComposerConfig(string $composerFile, array $config)
    {
        $this->assertFileExists($composerFile);
        $source = json_decode(file_get_contents($composerFile), true);
        $newSource = array_merge_recursive($source, $config);
        $flags = JSON_FORCE_OBJECT+JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES;
        $this->dumpFile($composerFile, json_encode($newSource,  $flags));
    }

    protected function ensureHooksExist()
    {
        $hooks = ['pre-commit', 'commit-msg'];
        foreach ($hooks as $hook) {
            $hookFile = $this->rootDir.$this->useCorrectDirectorySeparator('/.git/hooks/'.$hook);
            $this->assertFileExists($hookFile);
            $this->assertContains('grumphp', file_get_contents($hookFile));
        }
    }

    protected function initializeGrumphpConfig(string $path, string $composerDir = null): string
    {
        $composerDir = $composerDir ?: $path;
        $binDir = $composerDir.$this->useCorrectDirectorySeparator('/vendor/bin');
        $grumphpFile = $path.'/grumphp.yml';

        $this->filesystem->dumpFile(
            $this->useCorrectDirectorySeparator($grumphpFile),
            Yaml::dump([
                'parameters' => [
                    'bin_dir' => $this->filesystem->makePathRelative($binDir, $path),
                    'git_dir' => $this->filesystem->makePathRelative($this->rootDir, $path),
                    'tasks' => []
                ]
            ])
        );

        return $grumphpFile;
    }

    protected function mergeGrumphpConfig(string $grumphpFile, array $config)
    {
        $this->assertFileExists($grumphpFile);
        $source = Yaml::parseFile($grumphpFile);
        $newSource = array_merge_recursive($source, $config);
        $this->dumpFile($grumphpFile, Yaml::dump($newSource));
    }

    protected function registerGrumphpDefaultPathInComposer(string $composerFile, string $grumphpFile)
    {
        $this->mergeComposerConfig($composerFile, [
            'extra' => [
                'grumphp' => [
                    'config-default-path' => $grumphpFile
                ]
            ]
        ]);
    }

    protected function enableValidatePathsTask(string $grumphpFile, string $projectDir)
    {
        $this->mkdir($projectDir.'/src');
        $this->dumpFile(
            $projectDir.'/src/ValidatePathTask.php',
            file_get_contents(TEST_BASE_PATH.'/fixtures/e2e/tasks/ValidatePathsTask.php')
        );

        $this->mergeGrumphpConfig($grumphpFile, [
            'parameters' => [
                'tasks' => [
                    'validatePaths' => null,
                ],
            ],
            'services' => [
                'task.validatePaths' => [
                    'class' => 'GrumPHP\\E2E\\ValidatePathsTask',
                    'arguments' => [
                        $this->getAvailableFilesInPath($projectDir),
                    ],
                    'tags' => [
                        [
                            'name' => 'grumphp.task',
                            'config' => 'validatePaths'
                        ],
                    ]
                ]
            ],
        ]);
    }

    protected function installComposer(string $path)
    {
        $process = new Process(
            [
                $this->executableFinder->find('composer'),
                'install',
                '--optimize-autoloader',
                '--no-interaction',
            ],
            $path
        );

        $this->runCommand('install composer', $process);
    }

    protected function commitAll()
    {
        $git = $this->executableFinder->find('git');
        $this->gitAddPath($this->rootDir);
        $this->runCommand('commit', $commit = new Process([$git, 'commit', '-mtest'], $this->rootDir));

        $allOutput = $commit->getOutput().$commit->getErrorOutput();
        $this->assertContains('GrumPHP', $allOutput);
    }

    protected function gitAddPath(string $path)
    {
        $path = $this->relativeRootPath($path);
        $git = $this->executableFinder->find('git');
        $this->runCommand('add files to git', new Process([$git, 'add', '-A'], $path));
    }

    protected function runGrumphp(string $projectPath)
    {
        $projectPath = $this->relativeRootPath($projectPath);
        $this->runCommand('grumphp run', new Process(['./vendor/bin/grumphp', 'run'], $projectPath));

    }

    protected function mkdir(string $path): string
    {
        $pathDir = $this->relativeRootPath($path);
        $this->filesystem->mkdir($pathDir);

        return $pathDir;
    }

    protected function dumpFile(string $path, string $contents): string
    {
        $filePath = $this->relativeRootPath($path);
        $this->filesystem->dumpFile($filePath, $contents);

        return $filePath;
    }

    protected function runCommand(string $action, Process $process)
    {
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Could not '.$action.'! '.$process->getErrorOutput());
        }
    }

    protected function getAvailableFilesInPath(string $path): array
    {
        $path = $this->relativeRootPath($path);
        $this->gitAddPath($path);

        $process = new Process([$this->executableFinder->find('git'), 'ls-files'], $path);
        $this->runCommand('git ls-files', $process);

        return array_values(array_filter(explode("\n", $process->getOutput())));
    }

    protected function relativeRootPath(string $path): string
    {
        if (strpos($path, $this->rootDir) === 0) {
            return $this->useCorrectDirectorySeparator($path);
        }

        return $this->rootDir.DIRECTORY_SEPARATOR.$this->useCorrectDirectorySeparator(ltrim($path, '/\\'));
    }

    protected function useCorrectDirectorySeparator(string $path): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
