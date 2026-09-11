<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tools;

use DateTimeImmutable;
use JsonException;
use Random\Randomizer;
use RuntimeException;

final class PrWorkflow
{
    public const RECEIPT_VERSION = 1;

    public const EXPECTED_REPOSITORY = 'jkudish/laravel-ai-librarium';

    /** @var list<array{command: string, arguments: list<string>}> */
    public const CHECK_STEPS = [
        ['command' => 'composer', 'arguments' => ['validate', '--strict']],
        ['command' => 'composer', 'arguments' => ['check-platform-reqs']],
        ['command' => 'composer', 'arguments' => ['test']],
        ['command' => 'composer', 'arguments' => ['analyse']],
        ['command' => 'composer', 'arguments' => ['format:check']],
        ['command' => 'git', 'arguments' => ['diff', '--check']],
        ['command' => 'git', 'arguments' => ['diff', '--exit-code']],
    ];

    private const SHA_PATTERN = '/^[0-9a-f]{40}$/D';

    private const VERSION_PATTERN = '/^\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?$/D';

    private const MAX_RECEIPT_BYTES = 65_536;

    private const MAX_CAPTURE_BYTES = 65_536;

    /** @var callable(string, list<string>, array{env?: array<string, string>, inherit?: bool}): array{status: int, stdout: string, stderr: string} */
    private $runner;

    /** @var array<string, string> */
    private array $environment;

    /**
     * @param  null|callable(string, list<string>, array{env?: array<string, string>, inherit?: bool}): array{status: int, stdout: string, stderr: string}  $runner
     * @param  null|array<string, string>  $environment
     */
    public function __construct(?callable $runner = null, ?array $environment = null)
    {
        $this->runner = $runner ?? self::runProcess(...);
        $this->environment = $environment ?? self::ambientEnvironment();
    }

    /** @return array{path: string, receipt: array<string, mixed>} */
    public function check(?DateTimeImmutable $completedAt = null): array
    {
        $home = sys_get_temp_dir().'/librarium-pr-check-home-'.self::randomSuffix();

        if (! mkdir($home, 0700) || ! chmod($home, 0700)) {
            throw new RuntimeException('Could not create the isolated verification home.');
        }

        $environment = self::candidateEnvironment($this->environment, $home);

        try {
            $sha = $this->headSha($environment);
            $this->requireCleanWorktree($environment);

            foreach (self::CHECK_STEPS as $step) {
                $this->successful(
                    ($this->runner)($step['command'], $step['arguments'], [
                        'env' => $environment,
                        'inherit' => true,
                    ]),
                    $step['command'].' '.implode(' ', $step['arguments']),
                );
            }

            $runtime = $this->collectRuntime($environment);
            $this->requireCleanWorktree($environment);

            if ($this->headSha($environment) !== $sha) {
                throw new RuntimeException('Git HEAD changed while verification was running.');
            }

            $receipt = [
                'version' => self::RECEIPT_VERSION,
                'sha' => $sha,
                'repository' => self::EXPECTED_REPOSITORY,
                'planId' => $this->planId(),
                'success' => true,
                'completedAt' => ($completedAt ?? new DateTimeImmutable)->format(DATE_ATOM),
                'runtime' => $runtime,
            ];
            $path = $this->receiptPathFor($sha, $environment);
            $this->writeReceipt($path, $receipt);

            return ['path' => $path, 'receipt' => $receipt];
        } finally {
            self::removeDirectory($home);
        }
    }

    /** @return array{path: string, sha: string} */
    public function signoff(?string $approvedSha): array
    {
        if (! is_string($approvedSha) || preg_match(self::SHA_PATTERN, $approvedSha) !== 1) {
            throw new RuntimeException('--approved-sha must be a full 40-character lowercase Git SHA.');
        }

        $readEnvironment = self::signoffReadEnvironment($this->environment);
        $this->requireCleanWorktree($readEnvironment);
        $sha = $this->headSha($readEnvironment);

        if ($sha !== $approvedSha) {
            throw new RuntimeException('The explicitly approved SHA does not match the current Git HEAD.');
        }

        $path = $this->receiptPathFor($sha, $readEnvironment);
        $receipt = $this->readReceipt($path);
        $this->requireValidReceipt($receipt, $sha, $this->currentRuntime());

        $token = $this->environment['GH_SIGNOFF_TOKEN'] ?? '';

        if (trim($token) === '') {
            throw new RuntimeException('GH_SIGNOFF_TOKEN is required for authenticated GitHub preflight and final signoff.');
        }

        $githubEnvironment = $readEnvironment;
        $githubEnvironment['GH_TOKEN'] = $token;
        $githubEnvironment['GH_REPO'] = self::EXPECTED_REPOSITORY;
        $this->requireSignoffExtension($githubEnvironment);
        $branch = $this->currentBranch($readEnvironment);
        $this->requirePushedBranchAtSha($branch, $sha, $githubEnvironment);
        $this->requireOpenPullRequestAtSha($branch, $sha, $githubEnvironment);

        $repeatedCheck = $this->check();
        $repeatedReceipt = $this->readReceipt($path);

        if ($repeatedCheck['path'] !== $path || $repeatedCheck['receipt'] !== $repeatedReceipt) {
            throw new RuntimeException('Repeated verification did not return the expected exact-SHA receipt.');
        }

        $this->requireValidReceipt($repeatedReceipt, $sha, $this->currentRuntime());

        $this->requirePushedBranchAtSha($branch, $sha, $githubEnvironment);
        $this->requireOpenPullRequestAtSha($branch, $sha, $githubEnvironment);
        $this->requireCleanWorktree($readEnvironment);

        if ($this->headSha($readEnvironment) !== $sha) {
            throw new RuntimeException('Git HEAD changed while signoff eligibility was being checked.');
        }

        if ($this->currentBranch($readEnvironment) !== $branch) {
            throw new RuntimeException('The current Git branch changed while signoff eligibility was being checked.');
        }

        $this->gh(
            ['signoff', '--commit', $sha],
            $githubEnvironment,
            'gh signoff --commit '.$sha,
        );

        return ['path' => $path, 'sha' => $sha];
    }

    public function planId(): string
    {
        try {
            $encoded = json_encode([
                'steps' => self::CHECK_STEPS,
                'repository' => self::EXPECTED_REPOSITORY,
                'runtimePolicy' => $this->runtimePolicy(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the verification plan.', previous: $exception);
        }

        return hash('sha256', $encoded);
    }

    /** @return array{phpConstraint: string, phpMinimum: string} */
    public function runtimePolicy(): array
    {
        try {
            $composer = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('composer.json does not contain valid JSON.', previous: $exception);
        }

        if (! is_array($composer)) {
            throw new RuntimeException('composer.json does not contain an object.');
        }

        $requirements = $composer['require'] ?? null;
        $constraint = is_array($requirements) ? ($requirements['php'] ?? null) : null;

        if (! is_string($constraint) || preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/D', $constraint, $matches) !== 1) {
            throw new RuntimeException('The PHP compatibility constraint is unsupported by the PR workflow.');
        }

        return [
            'phpConstraint' => $constraint,
            'phpMinimum' => $matches[1].'.'.$matches[2].'.'.($matches[3] ?? '0'),
        ];
    }

    /**
     * @param  array<string, string>  $ambient
     * @return array<string, string>
     */
    public static function candidateEnvironment(array $ambient, string $home): array
    {
        return array_filter([
            'PATH' => $ambient['PATH'] ?? null,
            'HOME' => $home,
            'APP_ENV' => 'testing',
            'CI' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
        ], is_string(...));
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{policy: array{phpConstraint: string, phpMinimum: string}, actual: array{platform: string, architecture: string, php: string, composer: string, composerInstalled: string}}
     */
    private function collectRuntime(array $environment): array
    {
        $phpOutput = $this->successful(
            ($this->runner)('php', ['-r', 'echo json_encode([PHP_OS_FAMILY, php_uname("m"), PHP_VERSION], JSON_THROW_ON_ERROR);'], ['env' => $environment]),
            'PHP runtime evidence',
        );

        try {
            $php = json_decode($phpOutput, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('PHP returned malformed runtime evidence.', previous: $exception);
        }

        if (! is_array($php)) {
            throw new RuntimeException('PHP returned malformed runtime evidence.');
        }

        $composerOutput = $this->successful(
            ($this->runner)('composer', ['--version', '--no-ansi'], ['env' => $environment]),
            'Composer runtime evidence',
        );

        if (preg_match('/^Composer version (\S+)/', $composerOutput, $composerMatch) !== 1) {
            throw new RuntimeException('Composer returned an unrecognized version.');
        }

        $actual = [
            'platform' => $php[0] ?? null,
            'architecture' => $php[1] ?? null,
            'php' => $php[2] ?? null,
            'composer' => $composerMatch[1],
            'composerInstalled' => $this->composerInstalledHash(),
        ];

        $this->requireValidRuntimeActual($actual);

        /** @var array{platform: string, architecture: string, php: string, composer: string, composerInstalled: string} $actual */
        return ['policy' => $this->runtimePolicy(), 'actual' => $actual];
    }

    /** @return array{policy: array{phpConstraint: string, phpMinimum: string}, actual: array{platform: string, architecture: string, php: string, composer: string, composerInstalled: string}} */
    private function currentRuntime(): array
    {
        $home = sys_get_temp_dir().'/librarium-pr-signoff-home-'.self::randomSuffix();

        if (! mkdir($home, 0700) || ! chmod($home, 0700)) {
            throw new RuntimeException('Could not create the isolated runtime evidence home.');
        }

        try {
            return $this->collectRuntime(self::candidateEnvironment($this->environment, $home));
        } finally {
            self::removeDirectory($home);
        }
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array{policy: array{phpConstraint: string, phpMinimum: string}, actual: array{platform: string, architecture: string, php: string, composer: string, composerInstalled: string}}  $currentRuntime
     */
    private function requireValidReceipt(array $receipt, string $sha, array $currentRuntime): void
    {
        $completedAt = $receipt['completedAt'] ?? null;
        $runtime = $receipt['runtime'] ?? null;
        $actual = is_array($runtime) ? ($runtime['actual'] ?? null) : null;
        $policy = is_array($runtime) ? ($runtime['policy'] ?? null) : null;

        try {
            $validDate = is_string($completedAt) && (new DateTimeImmutable($completedAt))->format(DATE_ATOM) === $completedAt;
        } catch (\Exception) {
            $validDate = false;
        }

        if (
            array_keys($receipt) !== ['version', 'sha', 'repository', 'planId', 'success', 'completedAt', 'runtime']
            || ($receipt['version'] ?? null) !== self::RECEIPT_VERSION
            || ($receipt['sha'] ?? null) !== $sha
            || ($receipt['repository'] ?? null) !== self::EXPECTED_REPOSITORY
            || ($receipt['planId'] ?? null) !== $this->planId()
            || ($receipt['success'] ?? null) !== true
            || ! $validDate
            || ! is_array($runtime)
            || array_keys($runtime) !== ['policy', 'actual']
            || $policy !== $this->runtimePolicy()
            || ! is_array($actual)
            || $runtime !== $currentRuntime
        ) {
            throw new RuntimeException('The verification receipt is malformed, stale, or does not match the current plan, repository, SHA, and runtime evidence.');
        }

        /** @var array<string, mixed> $actual */
        $this->requireValidRuntimeActual($actual);
    }

    /** @param array<string, mixed> $actual */
    private function requireValidRuntimeActual(array $actual): void
    {
        $policy = $this->runtimePolicy();
        $maximumMajor = (string) (((int) explode('.', $policy['phpMinimum'])[0]) + 1).'.0.0';

        if (
            array_keys($actual) !== ['platform', 'architecture', 'php', 'composer', 'composerInstalled']
            || ! is_string($actual['platform'] ?? null)
            || $actual['platform'] === ''
            || strlen($actual['platform']) > 32
            || ! is_string($actual['architecture'] ?? null)
            || $actual['architecture'] === ''
            || strlen($actual['architecture']) > 32
            || ! is_string($actual['php'] ?? null)
            || strlen($actual['php']) > 64
            || preg_match(self::VERSION_PATTERN, $actual['php']) !== 1
            || version_compare($actual['php'], $policy['phpMinimum'], '<')
            || version_compare($actual['php'], $maximumMajor, '>=')
            || ! is_string($actual['composer'] ?? null)
            || strlen($actual['composer']) > 64
            || preg_match(self::VERSION_PATTERN, $actual['composer']) !== 1
            || ! is_string($actual['composerInstalled'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $actual['composerInstalled']) !== 1
        ) {
            throw new RuntimeException('Runtime evidence is missing, malformed, or outside the accepted PHP policy.');
        }
    }

    private function composerInstalledHash(): string
    {
        $path = dirname(__DIR__).'/vendor/composer/installed.json';

        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Composer installed-package evidence is unavailable or unsafe.');
        }

        $hash = hash_file('sha256', $path);

        if (! is_string($hash) || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('Composer installed-package evidence could not be collected.');
        }

        return $hash;
    }

    /** @return array<string, mixed> */
    private function readReceipt(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('No readable verification receipt exists for the current SHA: '.$path);
        }

        $pathStat = $this->requireSafeOwnedPath($path, 0600, 'verification receipt');
        $this->requireSafeOwnedPath(dirname($path), 0700, 'receipt directory');
        $this->requireSafeOwnedPath(dirname($path, 2), 0700, 'receipt namespace directory');
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The verification receipt could not be opened safely.');
        }

        try {
            $openStat = fstat($handle);

            if (
                ! is_array($openStat)
                || $openStat['dev'] !== $pathStat['dev']
                || $openStat['ino'] !== $pathStat['ino']
            ) {
                throw new RuntimeException('The verification receipt changed while it was being opened.');
            }

            $this->requireSafeOwnedStat($openStat, 0600, 'verification receipt');
            $size = $openStat['size'];

            if ($size > self::MAX_RECEIPT_BYTES) {
                throw new RuntimeException('The verification receipt is malformed or too large.');
            }

            $contents = stream_get_contents($handle, self::MAX_RECEIPT_BYTES + 1);

            if (! is_string($contents) || strlen($contents) !== $size || ! feof($handle)) {
                throw new RuntimeException('The verification receipt could not be read completely.');
            }
        } finally {
            fclose($handle);
        }

        try {
            $receipt = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The verification receipt contains malformed JSON.', previous: $exception);
        }

        if (! is_array($receipt)) {
            throw new RuntimeException('The verification receipt is malformed.');
        }

        /** @var array<string, mixed> $receipt */
        return $receipt;
    }

    /** @param array<string, mixed> $receipt */
    private function writeReceipt(string $path, array $receipt): void
    {
        $directory = dirname($path);
        $namespaceDirectory = dirname($path, 2);

        foreach ([$namespaceDirectory, $directory] as $component) {
            if (
                is_link($component)
                || (! is_dir($component) && ! mkdir($component, 0700))
                || ! chmod($component, 0700)
            ) {
                throw new RuntimeException('Could not create a safe receipt directory: '.$component);
            }

            $this->requireSafeOwnedPath($component, 0700, 'receipt directory');
        }

        if (file_exists($path) || is_link($path)) {
            if (! is_file($path) || is_link($path)) {
                throw new RuntimeException('The existing verification receipt path is unsafe.');
            }

            $this->requireSafeOwnedPath($path, 0600, 'existing verification receipt');
        }

        try {
            $contents = json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the verification receipt.', previous: $exception);
        }

        $temporaryPath = $path.'.'.self::randomSuffix().'.tmp';
        $handle = fopen($temporaryPath, 'x');

        if ($handle === false) {
            throw new RuntimeException('Could not create a temporary verification receipt.');
        }

        try {
            if (! chmod($temporaryPath, 0600) || fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new RuntimeException('Could not write the verification receipt completely.');
            }

            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('Could not flush the verification receipt.');
            }
        } finally {
            fclose($handle);
        }

        try {
            $this->requireSafeOwnedPath($temporaryPath, 0600, 'temporary verification receipt');

            if (! rename($temporaryPath, $path) || ! chmod($path, 0600)) {
                throw new RuntimeException('Could not atomically install the verification receipt.');
            }

            $this->requireSafeOwnedPath($path, 0600, 'verification receipt');
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /** @param array<string, string> $environment */
    private function receiptPathFor(string $sha, array $environment): string
    {
        $gitDirectory = rtrim($this->git(['rev-parse', '--path-format=absolute', '--git-common-dir'], $environment), '/');
        $path = $this->git([
            '--git-dir='.$gitDirectory,
            'rev-parse',
            '--path-format=absolute',
            '--git-path',
            'librarium/pr-check/'.$sha.'.json',
        ], $environment);
        $expectedPath = $gitDirectory.'/librarium/pr-check/'.$sha.'.json';

        if ($gitDirectory === '' || $path !== $expectedPath) {
            throw new RuntimeException('Git did not resolve receipt storage beneath its administrative directory.');
        }

        return $path;
    }

    /** @param array<string, string> $environment */
    private function requireSignoffExtension(array $environment): void
    {
        $extensions = $this->gh(
            ['extension', 'list'],
            $environment,
            'gh extension list',
        );

        if (preg_match('/(^|\s)basecamp\/gh-signoff(\s|$)/m', $extensions) !== 1) {
            throw new RuntimeException('The canonical basecamp/gh-signoff extension is not installed.');
        }
    }

    /** @param array<string, string> $environment */
    private function requirePushedBranchAtSha(string $branch, string $sha, array $environment): void
    {
        $remoteSha = $this->gh(
            [
                'api',
                '--hostname',
                'github.com',
                '--method',
                'GET',
                'repos/'.self::EXPECTED_REPOSITORY.'/git/ref/heads/'.rawurlencode($branch),
                '--jq',
                '.object.sha',
            ],
            $environment,
            'pushed branch lookup',
        );

        if ($remoteSha !== $sha) {
            throw new RuntimeException('The pushed branch head does not match the approved SHA.');
        }
    }

    /** @param array<string, string> $environment */
    private function requireOpenPullRequestAtSha(string $branch, string $sha, array $environment): void
    {
        $output = $this->gh(
            [
                'pr',
                'view',
                '--repo',
                self::EXPECTED_REPOSITORY,
                '--json',
                'headRefName,headRefOid,headRepositoryOwner,state',
                '--',
                $branch,
            ],
            $environment,
            'gh pr view',
        );

        try {
            $pullRequest = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('GitHub returned an invalid pull-request response.', previous: $exception);
        }

        if (! is_array($pullRequest)) {
            throw new RuntimeException('GitHub returned an invalid pull-request response.');
        }

        if (($pullRequest['state'] ?? null) !== 'OPEN') {
            throw new RuntimeException('The current branch must have an open pull request.');
        }

        $owner = $pullRequest['headRepositoryOwner'] ?? null;

        if (
            ($pullRequest['headRefName'] ?? null) !== $branch
            || ($pullRequest['headRefOid'] ?? null) !== $sha
            || ! is_array($owner)
            || ($owner['login'] ?? null) !== 'jkudish'
        ) {
            throw new RuntimeException('The repository-pinned open pull request does not match the approved branch and SHA.');
        }
    }

    /** @param array<string, string> $environment */
    private function currentBranch(array $environment): string
    {
        $branch = $this->git(['branch', '--show-current'], $environment);

        if ($branch === '' || strlen($branch) > 255 || str_contains($branch, "\0")) {
            throw new RuntimeException('Git did not resolve a valid current branch for pull-request lookup.');
        }

        return $branch;
    }

    /** @param array<string, string> $environment */
    private function requireCleanWorktree(array $environment): void
    {
        if ($this->git(['status', '--porcelain=v1', '--untracked-files=all'], $environment) !== '') {
            throw new RuntimeException('The worktree must be clean before verification or signoff.');
        }
    }

    /** @param array<string, string> $environment */
    private function headSha(array $environment): string
    {
        $sha = $this->git(['rev-parse', 'HEAD'], $environment);

        if (preg_match(self::SHA_PATTERN, $sha) !== 1) {
            throw new RuntimeException('Git HEAD did not resolve to a full lowercase SHA.');
        }

        return $sha;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function git(array $arguments, array $environment): string
    {
        return $this->successful(
            ($this->runner)('git', $arguments, ['env' => $environment]),
            'git '.implode(' ', $arguments),
        );
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function gh(array $arguments, array $environment, string $label): string
    {
        $result = ($this->runner)('gh', $arguments, [
            'env' => $environment,
        ]);

        if ($result['status'] !== 0) {
            // Truncated or encoded credentials cannot be safely removed by exact matching.
            throw new RuntimeException($label.' failed (exit code '.$result['status'].'). Authenticated command output withheld.');
        }

        return trim($result['stdout']);
    }

    /** @param array{status: int, stdout: string, stderr: string} $result */
    private function successful(array $result, string $label): string
    {
        if ($result['status'] !== 0) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new RuntimeException($label.' failed'.($detail === '' ? '' : ': '.$detail));
        }

        return trim($result['stdout']);
    }

    /** @return array<int|string, int> */
    private function requireSafeOwnedPath(string $path, int $mode, string $label): array
    {
        clearstatcache(true, $path);
        $stat = lstat($path);

        if (! is_array($stat)) {
            throw new RuntimeException('The '.$label.' could not be inspected safely.');
        }

        $this->requireSafeOwnedStat($stat, $mode, $label);

        return $stat;
    }

    /** @param array<int|string, int> $stat */
    private function requireSafeOwnedStat(array $stat, int $mode, string $label): void
    {
        $permissions = $stat['mode'] ?? null;
        $expectedType = $mode === 0700 ? 0040000 : 0100000;

        if (
            ! is_int($permissions)
            || ($permissions & 0170000) !== $expectedType
            || ($permissions & 0777) !== $mode
        ) {
            throw new RuntimeException('The '.$label.' must have mode '.decoct($mode).'.');
        }

        if (function_exists('posix_geteuid')) {
            $owner = $stat['uid'] ?? null;

            if (! is_int($owner) || $owner !== posix_geteuid()) {
                throw new RuntimeException('The '.$label.' must be owned by the current user.');
            }
        }
    }

    /**
     * @param  array<string, string>  $ambient
     * @return array<string, string>
     */
    private static function signoffReadEnvironment(array $ambient): array
    {
        return array_filter([
            'PATH' => $ambient['PATH'] ?? null,
            'HOME' => $ambient['HOME'] ?? null,
            'CI' => '1',
        ], is_string(...));
    }

    /** @return array<string, string> */
    private static function ambientEnvironment(): array
    {
        return array_filter(getenv(), is_string(...));
    }

    /**
     * @param  list<string>  $arguments
     * @param  array{env?: array<string, string>, inherit?: bool}  $options
     * @return array{status: int, stdout: string, stderr: string}
     */
    private static function runProcess(string $command, array $arguments, array $options = []): array
    {
        $inherit = $options['inherit'] ?? false;
        $stdoutHandle = $inherit ? null : tmpfile();
        $stderrHandle = $inherit ? null : tmpfile();

        if (! $inherit && (! is_resource($stdoutHandle) || ! is_resource($stderrHandle))) {
            if (is_resource($stdoutHandle)) {
                fclose($stdoutHandle);
            }

            if (is_resource($stderrHandle)) {
                fclose($stderrHandle);
            }

            return ['status' => 1, 'stdout' => '', 'stderr' => 'Could not create process output storage.'];
        }

        if ($inherit) {
            $descriptors = [STDIN, STDOUT, STDERR];
        } else {
            if (! is_resource($stdoutHandle) || ! is_resource($stderrHandle)) {
                return ['status' => 1, 'stdout' => '', 'stderr' => 'Could not create process output storage.'];
            }

            $descriptors = [STDIN, $stdoutHandle, $stderrHandle];
        }

        $pipes = [];
        $process = proc_open([$command, ...$arguments], $descriptors, $pipes, null, $options['env'] ?? null, [
            'bypass_shell' => true,
        ]);

        if (! is_resource($process)) {
            if (is_resource($stdoutHandle)) {
                fclose($stdoutHandle);
            }

            if (is_resource($stderrHandle)) {
                fclose($stderrHandle);
            }

            return ['status' => 1, 'stdout' => '', 'stderr' => 'The process could not start.'];
        }

        $status = proc_close($process);
        $stdout = is_resource($stdoutHandle) ? self::readCapturedOutput($stdoutHandle) : '';
        $stderr = is_resource($stderrHandle) ? self::readCapturedOutput($stderrHandle) : '';

        if (is_resource($stdoutHandle)) {
            fclose($stdoutHandle);
        }

        if (is_resource($stderrHandle)) {
            fclose($stderrHandle);
        }

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param resource $handle */
    private static function readCapturedOutput($handle): string
    {
        rewind($handle);
        $output = (string) stream_get_contents($handle, self::MAX_CAPTURE_BYTES + 1);

        if (strlen($output) <= self::MAX_CAPTURE_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_CAPTURE_BYTES)."\n[output truncated]\n";
    }

    private static function randomSuffix(): string
    {
        return bin2hex((new Randomizer)->getBytes(12));
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            throw new RuntimeException('Could not inspect temporary directory: '.$directory);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path) && ! is_link($path)) {
                self::removeDirectory($path);
            } elseif (! unlink($path)) {
                throw new RuntimeException('Could not remove temporary file: '.$path);
            }
        }

        if (! rmdir($directory)) {
            throw new RuntimeException('Could not remove temporary directory: '.$directory);
        }
    }
}
