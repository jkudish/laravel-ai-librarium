<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Tools\PrWorkflow;

require_once dirname(__DIR__, 2).'/tools/PrWorkflow.php';

const PR_SHA = '1111111111111111111111111111111111111111';
const PR_OTHER_SHA = '2222222222222222222222222222222222222222';
const PR_BRANCH = 'feature/3170-orb-self-signoff';

/** @return array{status: int, stdout: string, stderr: string} */
function prSuccess(string $stdout = ''): array
{
    return ['status' => 0, 'stdout' => $stdout, 'stderr' => ''];
}

/** @return array{status: int, stdout: string, stderr: string} */
function prFailure(string $stderr): array
{
    return ['status' => 1, 'stdout' => '', 'stderr' => $stderr];
}

/**
 * @param  list<string>  $headSequence
 * @param  list<string>  $worktreeSequence
 * @param  list<string>  $branchSequence
 * @param  list<string>  $remoteSequence
 * @param  list<array<string, mixed>|string>  $pullRequestSequence
 * @return array{calls: ArrayObject<int, array{command: string, arguments: list<string>, options: array<string, mixed>}>, directory: string, path: string, root: string, run: Closure}
 */
function prRunner(
    array $headSequence = [PR_SHA],
    array $worktreeSequence = [''],
    array $branchSequence = [PR_BRANCH],
    array $remoteSequence = [PR_SHA],
    array $pullRequestSequence = [[
        'headRefName' => PR_BRANCH,
        'headRefOid' => PR_SHA,
        'headRepositoryOwner' => ['login' => 'jkudish'],
        'state' => 'OPEN',
    ]],
    string $extension = "gh signoff\tbasecamp/gh-signoff\tv1.0.0",
    ?string $failingCheck = null,
    string $phpVersion = '8.5.9',
): array {
    $root = sys_get_temp_dir().'/librarium-pr-workflow-test-'.bin2hex(random_bytes(8));
    $directory = $root.'/librarium/pr-check';
    mkdir($directory, 0700, true);
    chmod($root.'/librarium', 0700);
    chmod($directory, 0700);
    prTemporaryDirectories()->append($root);
    $path = $directory.'/'.PR_SHA.'.json';
    $calls = new ArrayObject;
    $headIndex = 0;
    $worktreeIndex = 0;
    $branchIndex = 0;
    $remoteIndex = 0;
    $pullRequestIndex = 0;

    $run = function (string $command, array $arguments, array $options = []) use (
        $branchSequence,
        &$branchIndex,
        $calls,
        $extension,
        $failingCheck,
        $headSequence,
        &$headIndex,
        $path,
        $phpVersion,
        $pullRequestSequence,
        &$pullRequestIndex,
        $remoteSequence,
        &$remoteIndex,
        $root,
        $worktreeSequence,
        &$worktreeIndex,
    ): array {
        $calls[] = compact('command', 'arguments', 'options');

        if ($command === 'git' && $arguments === ['rev-parse', 'HEAD']) {
            $value = $headSequence[min($headIndex, count($headSequence) - 1)];
            $headIndex++;

            return prSuccess($value."\n");
        }

        if ($command === 'git' && $arguments === ['status', '--porcelain=v1', '--untracked-files=all']) {
            $value = $worktreeSequence[min($worktreeIndex, count($worktreeSequence) - 1)];
            $worktreeIndex++;

            return prSuccess($value);
        }

        if ($command === 'git' && $arguments === ['rev-parse', '--path-format=absolute', '--git-common-dir']) {
            return prSuccess($root."\n");
        }

        if ($command === 'git' && $arguments[1] === 'rev-parse' && in_array('--git-path', $arguments, true)) {
            return prSuccess($path."\n");
        }

        if ($command === 'git' && $arguments === ['branch', '--show-current']) {
            $value = $branchSequence[min($branchIndex, count($branchSequence) - 1)];
            $branchIndex++;

            return prSuccess($value."\n");
        }

        if ($command === 'php' && $arguments[0] === '-r') {
            return prSuccess(json_encode(['Linux', 'x86_64', $phpVersion], JSON_THROW_ON_ERROR));
        }

        if ($command === 'composer' && $arguments === ['--version', '--no-ansi']) {
            return prSuccess('Composer version 2.10.3 2026-08-27 13:34:23');
        }

        if ($command === 'gh' && $arguments === ['extension', 'list']) {
            return prSuccess($extension."\n");
        }

        if ($command === 'gh' && $arguments[0] === 'api') {
            $value = $remoteSequence[min($remoteIndex, count($remoteSequence) - 1)];
            $remoteIndex++;

            return prSuccess($value."\n");
        }

        if ($command === 'gh' && $arguments[0] === 'pr') {
            $value = $pullRequestSequence[min($pullRequestIndex, count($pullRequestSequence) - 1)];
            $pullRequestIndex++;

            return prSuccess(is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR));
        }

        if ($failingCheck === $command.' '.implode(' ', $arguments)) {
            return prFailure('intentional failure');
        }

        return prSuccess();
    };

    return compact('calls', 'directory', 'path', 'root', 'run');
}

/** @return array<string, mixed> */
function prValidReceipt(PrWorkflow $workflow, array $overrides = []): array
{
    return array_replace_recursive([
        'version' => 1,
        'sha' => PR_SHA,
        'repository' => PrWorkflow::EXPECTED_REPOSITORY,
        'planId' => $workflow->planId(),
        'success' => true,
        'completedAt' => '2026-08-28T12:00:00+00:00',
        'runtime' => [
            'policy' => ['phpConstraint' => '^8.4', 'phpMinimum' => '8.4.0'],
            'actual' => [
                'platform' => 'Linux',
                'architecture' => 'x86_64',
                'php' => '8.5.9',
                'composer' => '2.10.3',
                'composerInstalled' => hash_file('sha256', dirname(__DIR__, 2).'/vendor/composer/installed.json'),
            ],
        ],
    ], $overrides);
}

function prWriteReceipt(string $path, array|string $receipt): void
{
    file_put_contents($path, is_string($receipt) ? $receipt : json_encode($receipt, JSON_THROW_ON_ERROR));
    chmod($path, 0600);
}

/** @return ArrayObject<int, string> */
function prTemporaryDirectories(): ArrayObject
{
    static $directories;

    return $directories ??= new ArrayObject;
}

function prRemoveTestDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_dir($path) && ! is_link($path)) {
            prRemoveTestDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

afterEach(function (): void {
    $directories = array_reverse(prTemporaryDirectories()->getArrayCopy());
    prTemporaryDirectories()->exchangeArray([]);

    foreach ($directories as $directory) {
        prRemoveTestDirectory($directory);
    }
});

it('owns the deterministic offline package plan, repository, and PHP policy', function (): void {
    $workflow = new PrWorkflow(prRunner()['run'], ['PATH' => '/usr/bin']);

    expect(PrWorkflow::CHECK_STEPS)->toBe([
        ['command' => 'composer', 'arguments' => ['validate', '--strict']],
        ['command' => 'composer', 'arguments' => ['check-platform-reqs']],
        ['command' => 'composer', 'arguments' => ['test']],
        ['command' => 'composer', 'arguments' => ['analyse']],
        ['command' => 'composer', 'arguments' => ['format:check']],
        ['command' => 'git', 'arguments' => ['diff', '--check']],
        ['command' => 'git', 'arguments' => ['diff', '--exit-code']],
    ])->and(PrWorkflow::EXPECTED_REPOSITORY)->toBe('jkudish/laravel-ai-librarium')
        ->and($workflow->runtimePolicy())->toBe([
            'phpConstraint' => '^8.4',
            'phpMinimum' => '8.4.0',
        ])->and($workflow->planId())->toMatch('/^[0-9a-f]{64}$/');
});

it('constructs an allowlist candidate environment without ambient credentials or config', function (): void {
    $environment = PrWorkflow::candidateEnvironment([
        'PATH' => '/usr/bin',
        'HOME' => '/home/user',
        'GH_TOKEN' => 'github-secret',
        'GH_SIGNOFF_TOKEN' => 'signoff-secret',
        'OPENAI_API_KEY' => 'provider-secret',
        'GEMINI_API_KEY' => 'provider-secret',
        'COMPOSER_AUTH' => 'composer-secret',
        'GH_REPO' => 'attacker/redirect',
    ], '/tmp/disposable-home');

    expect($environment)->toBe([
        'PATH' => '/usr/bin',
        'HOME' => '/tmp/disposable-home',
        'APP_ENV' => 'testing',
        'CI' => '1',
        'COMPOSER_NO_INTERACTION' => '1',
    ]);
});

it('writes an atomic exact-SHA mode-0600 repository-bound receipt after every check passes', function (): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], [
        'PATH' => '/usr/bin',
        'HOME' => '/home/user',
        'GH_TOKEN' => 'github-secret',
        'GH_SIGNOFF_TOKEN' => 'signoff-secret',
        'OPENAI_API_KEY' => 'provider-secret',
    ]);
    $result = $workflow->check(new DateTimeImmutable('2026-08-28T12:00:00+00:00'));

    expect($result['path'])->toBe($mock['path'])
        ->and(fileperms($mock['path']) & 0777)->toBe(0600)
        ->and(fileperms($mock['directory']) & 0777)->toBe(0700)
        ->and(fileperms(dirname($mock['directory'])) & 0777)->toBe(0700)
        ->and(json_decode((string) file_get_contents($mock['path']), true, flags: JSON_THROW_ON_ERROR))->toBe($result['receipt'])
        ->and($result['receipt'])->toBe(prValidReceipt($workflow));

    $checkCalls = array_values(array_filter(
        $mock['calls']->getArrayCopy(),
        fn (array $call): bool => ($call['options']['inherit'] ?? false) === true,
    ));

    expect(array_map(fn (array $call): array => [$call['command'], $call['arguments']], $checkCalls))
        ->toBe(array_map(fn (array $step): array => [$step['command'], $step['arguments']], PrWorkflow::CHECK_STEPS));

    foreach ($checkCalls as $call) {
        expect($call['options']['env'])->not->toHaveKeys(['GH_TOKEN', 'GH_SIGNOFF_TOKEN', 'OPENAI_API_KEY'])
            ->and($call['options']['env']['HOME'])->toStartWith(sys_get_temp_dir().'/librarium-pr-check-home-')
            ->and($call['options']['env']['HOME'])->not->toBe('/home/user');
    }

    expect(glob(dirname($mock['path']).'/*.tmp') ?: [])->toBe([]);
});

it('stops at the first failed ordered check and writes no receipt', function (): void {
    $mock = prRunner(failingCheck: 'composer test');

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->check())
        ->toThrow(RuntimeException::class, 'composer test failed');
    expect(file_exists($mock['path']))->toBeFalse();

    $inherited = array_values(array_filter(
        $mock['calls']->getArrayCopy(),
        fn (array $call): bool => ($call['options']['inherit'] ?? false) === true,
    ));
    expect(array_map(fn (array $call): string => $call['command'].' '.implode(' ', $call['arguments']), $inherited))->toBe([
        'composer validate --strict',
        'composer check-platform-reqs',
        'composer test',
    ]);
});

it('rejects a dirty candidate before running checks', function (): void {
    $mock = prRunner(worktreeSequence: ['?? accidental.txt']);

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->check())
        ->toThrow(RuntimeException::class, 'worktree must be clean');
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => ($call['options']['inherit'] ?? false) === true))->toBe([]);
});

it('does not write a receipt when HEAD or the worktree changes during checks', function (array $heads, array $worktrees, string $message): void {
    $mock = prRunner(headSequence: $heads, worktreeSequence: $worktrees);

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->check())
        ->toThrow(RuntimeException::class, $message);
    expect(file_exists($mock['path']))->toBeFalse();
})->with([
    'HEAD drift' => [[PR_SHA, PR_OTHER_SHA], ['', ''], 'HEAD changed'],
    'worktree drift' => [[PR_SHA], ['', ' M README.md'], 'worktree must be clean'],
]);

it('requires an explicit full lowercase SHA matching HEAD', function (?string $approvedSha, string $message): void {
    $mock = prRunner();

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->signoff($approvedSha))
        ->toThrow(RuntimeException::class, $message);
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'))->toBe([]);
})->with([
    'missing' => [null, 'full 40-character lowercase'],
    'partial' => ['1111111', 'full 40-character lowercase'],
    'uppercase' => [str_repeat('A', 40), 'full 40-character lowercase'],
    'different' => [PR_OTHER_SHA, 'approved SHA does not match'],
]);

it('rejects malformed HEAD and dirty signoff state before evidence or GitHub access', function (array $heads, array $worktrees, string $message): void {
    $mock = prRunner(headSequence: $heads, worktreeSequence: $worktrees);

    expect(fn () => (new PrWorkflow($mock['run'], ['PATH' => '/usr/bin']))->signoff(PR_SHA))
        ->toThrow(RuntimeException::class, $message);
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'))->toBe([]);
})->with([
    'malformed HEAD' => [['HEAD'], [''], 'full lowercase SHA'],
    'dirty tree' => [[PR_SHA], ['?? accidental.txt'], 'worktree must be clean'],
]);

it('rejects missing, partial, malformed, stale, and foreign receipts before GitHub access', function (array|string|null $receipt, string $message): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);

    if ($receipt !== null) {
        prWriteReceipt($mock['path'], is_array($receipt) ? prValidReceipt($workflow, $receipt) : $receipt);
    }

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
    expect(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'))->toBe([]);
})->with([
    'missing' => [null, 'No readable verification receipt'],
    'partial JSON' => ['{"version":1', 'malformed JSON'],
    'malformed JSON' => ['{', 'malformed JSON'],
    'wrong version' => [['version' => 2], 'malformed, stale'],
    'wrong SHA' => [['sha' => PR_OTHER_SHA], 'malformed, stale'],
    'wrong repository' => [['repository' => 'attacker/redirect'], 'malformed, stale'],
    'wrong plan' => [['planId' => str_repeat('a', 64)], 'malformed, stale'],
    'unsuccessful' => [['success' => false], 'malformed, stale'],
    'bad timestamp' => [['completedAt' => 'yesterday'], 'malformed, stale'],
    'wrong policy' => [['runtime' => ['policy' => ['phpConstraint' => '^8.5', 'phpMinimum' => '8.5.0']]], 'malformed, stale'],
    'missing runtime' => [['runtime' => null], 'malformed, stale'],
    'malformed runtime' => [['runtime' => ['actual' => ['php' => 'not-a-version']]], 'malformed, stale'],
    'different installed packages' => [['runtime' => ['actual' => ['composerInstalled' => str_repeat('a', 64)]]], 'runtime evidence'],
    'unexpected field' => [['unexpected' => true], 'malformed, stale'],
]);

it('rejects unsafe receipt and receipt-directory modes', function (string $target, int $mode, string $message): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));
    chmod($target === 'receipt' ? $mock['path'] : $mock['directory'], $mode);

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
})->with([
    'receipt mode' => ['receipt', 0644, 'mode 600'],
    'directory mode' => ['directory', 0755, 'mode 700'],
]);

it('rejects symlinked and oversized receipts', function (string $kind, string $message): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);

    if ($kind === 'symlink') {
        $target = dirname($mock['path']).'/target.json';
        prWriteReceipt($target, prValidReceipt($workflow));
        symlink($target, $mock['path']);
    } else {
        prWriteReceipt($mock['path'], str_repeat('x', 65_537));
    }

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
})->with([
    'symlink' => ['symlink', 'No readable verification receipt'],
    'oversized' => ['oversized', 'malformed or too large'],
]);

it('rejects an intermediate symlink that redirects receipt storage outside Git administration', function (): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);
    $outside = sys_get_temp_dir().'/librarium-receipt-outside-'.bin2hex(random_bytes(8));
    mkdir($outside.'/pr-check', 0700, true);
    chmod($outside, 0700);
    chmod($outside.'/pr-check', 0700);
    prTemporaryDirectories()->append($outside);
    prRemoveTestDirectory($mock['root'].'/librarium');
    symlink($outside, $mock['root'].'/librarium');
    prWriteReceipt($outside.'/pr-check/'.PR_SHA.'.json', prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, 'receipt namespace directory');
});

it('rejects runtime evidence that differs or falls outside policy', function (string $phpVersion, string $message): void {
    $mock = prRunner(phpVersion: $phpVersion);
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
})->with([
    'runtime drift' => ['8.5.10', 'runtime evidence'],
    'below policy' => ['8.3.9', 'outside the accepted PHP policy'],
    'above policy' => ['9.0.0', 'outside the accepted PHP policy'],
]);

it('requires the canonical extension identity and valid pinned GitHub state', function (
    string $extension,
    array $remoteSequence,
    array $pullRequestSequence,
    string $message,
): void {
    $mock = prRunner(extension: $extension, remoteSequence: $remoteSequence, pullRequestSequence: $pullRequestSequence);
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
    expect(array_filter(
        $mock['calls']->getArrayCopy(),
        fn (array $call): bool => $call['command'] === 'gh' && $call['arguments'][0] === 'signoff',
    ))->toBe([]);
})->with([
    'missing extension' => ['', [PR_SHA], [[
        'headRefName' => PR_BRANCH,
        'headRefOid' => PR_SHA,
        'headRepositoryOwner' => ['login' => 'jkudish'],
        'state' => 'OPEN',
    ]], 'canonical basecamp/gh-signoff'],
    'wrong extension source' => ["gh signoff\tattacker/gh-signoff\tv1", [PR_SHA], [[
        'headRefName' => PR_BRANCH,
        'headRefOid' => PR_SHA,
        'headRepositoryOwner' => ['login' => 'jkudish'],
        'state' => 'OPEN',
    ]], 'canonical basecamp/gh-signoff'],
    'unpushed local head' => ["gh signoff\tbasecamp/gh-signoff\tv1", [PR_OTHER_SHA], [[
        'headRefName' => PR_BRANCH,
        'headRefOid' => PR_SHA,
        'headRepositoryOwner' => ['login' => 'jkudish'],
        'state' => 'OPEN',
    ]], 'pushed branch head'],
    'invalid PR JSON' => ["gh signoff\tbasecamp/gh-signoff\tv1", [PR_SHA], ['{'], 'invalid pull-request response'],
    'closed PR' => ["gh signoff\tbasecamp/gh-signoff\tv1", [PR_SHA], [[
        'headRefName' => PR_BRANCH,
        'headRefOid' => PR_SHA,
        'headRepositoryOwner' => ['login' => 'jkudish'],
        'state' => 'CLOSED',
    ]], 'open pull request'],
    'wrong PR owner' => ["gh signoff\tbasecamp/gh-signoff\tv1", [PR_SHA], [[
        'headRefName' => PR_BRANCH,
        'headRefOid' => PR_SHA,
        'headRepositoryOwner' => ['login' => 'attacker'],
        'state' => 'OPEN',
    ]], 'does not match'],
    'mismatched PR head' => ["gh signoff\tbasecamp/gh-signoff\tv1", [PR_SHA], [[
        'headRefName' => PR_BRANCH,
        'headRefOid' => PR_OTHER_SHA,
        'headRepositoryOwner' => ['login' => 'jkudish'],
        'state' => 'OPEN',
    ]], 'does not match'],
]);

it('requires a valid current branch before pinned remote and PR lookups', function (): void {
    $mock = prRunner(branchSequence: ['']);
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user', 'GH_SIGNOFF_TOKEN' => 'token']);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, 'valid current branch');
});

it('requires the dedicated token only after read-only eligibility checks', function (): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], ['PATH' => '/usr/bin', 'HOME' => '/home/user']);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, 'GH_SIGNOFF_TOKEN is required');

    $githubCalls = array_values(array_filter($mock['calls']->getArrayCopy(), fn (array $call): bool => $call['command'] === 'gh'));
    expect(array_map(fn (array $call): string => $call['arguments'][0], $githubCalls))->toBe(['extension', 'api', 'pr']);
});

it('reruns every credential-free check in order and stops before signoff when one fails', function (): void {
    $mock = prRunner(failingCheck: 'composer test');
    $workflow = new PrWorkflow($mock['run'], [
        'PATH' => '/usr/bin',
        'HOME' => '/home/user',
        'GH_TOKEN' => 'ambient-token',
        'GH_SIGNOFF_TOKEN' => 'status-token',
        'OPENAI_API_KEY' => 'provider-token',
        'GH_REPO' => 'attacker/redirect',
    ]);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, 'composer test failed');

    $calls = $mock['calls']->getArrayCopy();
    $checkCalls = array_values(array_filter(
        $calls,
        fn (array $call): bool => ($call['options']['inherit'] ?? false) === true && $call['command'] !== 'gh',
    ));
    expect(array_map(fn (array $call): string => $call['command'].' '.implode(' ', $call['arguments']), $checkCalls))->toBe([
        'composer validate --strict',
        'composer check-platform-reqs',
        'composer test',
    ])->and(array_filter(
        $calls,
        fn (array $call): bool => $call['command'] === 'gh' && $call['arguments'][0] === 'signoff',
    ))->toBe([]);

    foreach ($checkCalls as $call) {
        expect($call['options']['env'])->not->toHaveKeys([
            'GH_TOKEN',
            'GH_SIGNOFF_TOKEN',
            'OPENAI_API_KEY',
            'GH_REPO',
        ])->and($call['options']['env']['HOME'])->toStartWith(sys_get_temp_dir().'/librarium-pr-check-home-');
    }
});

it('fails closed when local, branch, remote, or PR state drifts during final checks', function (
    array $heads,
    array $worktrees,
    array $branches,
    array $remotes,
    array $pullRequests,
    string $message,
): void {
    $mock = prRunner(
        headSequence: $heads,
        worktreeSequence: $worktrees,
        branchSequence: $branches,
        remoteSequence: $remotes,
        pullRequestSequence: $pullRequests,
    );
    $workflow = new PrWorkflow($mock['run'], [
        'PATH' => '/usr/bin',
        'HOME' => '/home/user',
        'GH_SIGNOFF_TOKEN' => 'status-token',
    ]);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect(fn () => $workflow->signoff(PR_SHA))->toThrow(RuntimeException::class, $message);
    expect(array_filter(
        $mock['calls']->getArrayCopy(),
        fn (array $call): bool => $call['command'] === 'gh' && $call['arguments'][0] === 'signoff',
    ))->toBe([]);
})->with([
    'HEAD drift' => [[PR_SHA, PR_SHA, PR_SHA, PR_OTHER_SHA], ['', '', '', ''], [PR_BRANCH], [PR_SHA, PR_SHA], [
        ['headRefName' => PR_BRANCH, 'headRefOid' => PR_SHA, 'headRepositoryOwner' => ['login' => 'jkudish'], 'state' => 'OPEN'],
    ], 'HEAD changed'],
    'worktree drift' => [[PR_SHA], ['', '', '', ' M README.md'], [PR_BRANCH], [PR_SHA, PR_SHA], [
        ['headRefName' => PR_BRANCH, 'headRefOid' => PR_SHA, 'headRepositoryOwner' => ['login' => 'jkudish'], 'state' => 'OPEN'],
    ], 'worktree must be clean'],
    'branch drift' => [[PR_SHA, PR_SHA], ['', ''], [PR_BRANCH, 'feature/other'], [PR_SHA, PR_SHA], [
        ['headRefName' => PR_BRANCH, 'headRefOid' => PR_SHA, 'headRepositoryOwner' => ['login' => 'jkudish'], 'state' => 'OPEN'],
    ], 'branch changed'],
    'remote drift' => [[PR_SHA], ['', ''], [PR_BRANCH], [PR_SHA, PR_OTHER_SHA], [
        ['headRefName' => PR_BRANCH, 'headRefOid' => PR_SHA, 'headRepositoryOwner' => ['login' => 'jkudish'], 'state' => 'OPEN'],
    ], 'pushed branch head'],
    'PR drift' => [[PR_SHA], ['', ''], [PR_BRANCH], [PR_SHA, PR_SHA], [
        ['headRefName' => PR_BRANCH, 'headRefOid' => PR_SHA, 'headRepositoryOwner' => ['login' => 'jkudish'], 'state' => 'OPEN'],
        ['headRefName' => PR_BRANCH, 'headRefOid' => PR_OTHER_SHA, 'headRepositoryOwner' => ['login' => 'jkudish'], 'state' => 'OPEN'],
    ], 'does not match'],
]);

it('pins selectors and maps the dedicated token only for one exact unforced signoff', function (): void {
    $mock = prRunner();
    $workflow = new PrWorkflow($mock['run'], [
        'PATH' => '/usr/bin',
        'HOME' => '/home/user',
        'GH_TOKEN' => 'ambient-token',
        'GH_SIGNOFF_TOKEN' => 'status-token',
        'OPENAI_API_KEY' => 'provider-token',
        'GH_REPO' => 'attacker/redirect',
    ]);
    prWriteReceipt($mock['path'], prValidReceipt($workflow));

    expect($workflow->signoff(PR_SHA))->toBe(['path' => $mock['path'], 'sha' => PR_SHA]);

    $calls = $mock['calls']->getArrayCopy();
    $githubCalls = array_values(array_filter($calls, fn (array $call): bool => $call['command'] === 'gh'));
    $signoffCalls = array_values(array_filter($githubCalls, fn (array $call): bool => $call['arguments'][0] === 'signoff'));
    $remoteCalls = array_values(array_filter($githubCalls, fn (array $call): bool => $call['arguments'][0] === 'api'));
    $pullRequestCalls = array_values(array_filter($githubCalls, fn (array $call): bool => $call['arguments'][0] === 'pr'));
    $gitPathCalls = array_values(array_filter(
        $calls,
        fn (array $call): bool => $call['command'] === 'git' && in_array('--git-path', $call['arguments'], true),
    ));
    $checkCalls = array_values(array_filter(
        $calls,
        fn (array $call): bool => ($call['options']['inherit'] ?? false) === true && $call['command'] !== 'gh',
    ));
    $guardedSequence = array_values(array_filter(
        $calls,
        fn (array $call): bool => $call['command'] === 'gh' || ($call['options']['inherit'] ?? false) === true,
    ));

    expect($signoffCalls)->toHaveCount(1)
        ->and($remoteCalls)->toHaveCount(2)
        ->and($pullRequestCalls)->toHaveCount(2)
        ->and($gitPathCalls)->toHaveCount(2)
        ->and($gitPathCalls[0]['arguments'])->toBe([
            '--git-dir='.$mock['root'],
            'rev-parse',
            '--path-format=absolute',
            '--git-path',
            'librarium/pr-check/'.PR_SHA.'.json',
        ])->and($gitPathCalls[1]['arguments'])->toBe($gitPathCalls[0]['arguments'])
        ->and(array_map(fn (array $call): array => [$call['command'], $call['arguments']], $checkCalls))
        ->toBe(array_map(fn (array $step): array => [$step['command'], $step['arguments']], PrWorkflow::CHECK_STEPS))
        ->and(array_map(
            fn (array $call): string => $call['command'].' '.implode(' ', $call['command'] === 'gh' ? [$call['arguments'][0]] : $call['arguments']),
            $guardedSequence,
        ))->toBe([
            'gh extension',
            'gh api',
            'gh pr',
            ...array_map(fn (array $step): string => $step['command'].' '.implode(' ', $step['arguments']), PrWorkflow::CHECK_STEPS),
            'gh api',
            'gh pr',
            'gh signoff',
        ]);

    foreach ($checkCalls as $call) {
        expect($call['options']['env'])->not->toHaveKeys([
            'GH_TOKEN',
            'GH_SIGNOFF_TOKEN',
            'OPENAI_API_KEY',
            'GH_REPO',
        ])->and($call['options']['env']['HOME'])->toStartWith(sys_get_temp_dir().'/librarium-pr-check-home-');
    }

    $signoffCall = $signoffCalls[0];
    expect($signoffCall['arguments'])->toBe(['signoff', '--commit', PR_SHA])
        ->and($signoffCall['arguments'])->not->toContain('--force')
        ->and($signoffCall['arguments'])->not->toContain('-f')
        ->and($signoffCall['options']['env']['GH_TOKEN'])->toBe('status-token')
        ->and($signoffCall['options']['env']['GH_REPO'])->toBe(PrWorkflow::EXPECTED_REPOSITORY)
        ->and($signoffCall['options']['env'])->not->toHaveKeys(['GH_SIGNOFF_TOKEN', 'OPENAI_API_KEY']);

    $replacementReceipt = json_decode((string) file_get_contents($mock['path']), true, flags: JSON_THROW_ON_ERROR);
    expect($replacementReceipt['sha'])->toBe(PR_SHA)
        ->and($replacementReceipt['repository'])->toBe(PrWorkflow::EXPECTED_REPOSITORY)
        ->and($replacementReceipt['planId'])->toBe($workflow->planId())
        ->and($replacementReceipt['completedAt'])->not->toBe('2026-08-28T12:00:00+00:00');

    foreach ($remoteCalls as $call) {
        expect($call['arguments'])->toBe([
            'api',
            '--hostname',
            'github.com',
            '--method',
            'GET',
            'repos/'.PrWorkflow::EXPECTED_REPOSITORY.'/git/ref/heads/feature%2F3170-orb-self-signoff',
            '--jq',
            '.object.sha',
        ]);
    }

    foreach ($pullRequestCalls as $call) {
        expect($call['arguments'])->toBe([
            'pr',
            'view',
            '--repo',
            PrWorkflow::EXPECTED_REPOSITORY,
            '--json',
            'headRefName,headRefOid,headRepositoryOwner,state',
            '--',
            PR_BRANCH,
        ]);
    }

    foreach (array_slice($calls, 0, -1) as $call) {
        expect($call['options']['env'])->not->toHaveKeys(['GH_TOKEN', 'GH_SIGNOFF_TOKEN', 'OPENAI_API_KEY', 'GH_REPO']);
    }
});

it('uses no-shell exact argv and deadlock-safe bounded stdout and stderr capture', function (): void {
    $method = new ReflectionMethod(PrWorkflow::class, 'runProcess');
    $method->setAccessible(true);
    $marker = sys_get_temp_dir().'/librarium-shell-marker-'.bin2hex(random_bytes(8));
    $argument = '; touch '.$marker;

    /** @var array{status: int, stdout: string, stderr: string} $argvResult */
    $argvResult = $method->invoke(null, PHP_BINARY, ['-r', 'echo $argv[1];', '--', $argument], ['env' => ['PATH' => (string) getenv('PATH')]]);
    expect($argvResult)->toBe(['status' => 0, 'stdout' => $argument, 'stderr' => ''])
        ->and(file_exists($marker))->toBeFalse();

    /** @var array{status: int, stdout: string, stderr: string} $captureResult */
    $captureResult = $method->invoke(null, PHP_BINARY, [
        '-r',
        'fwrite(STDOUT, str_repeat("o", 100000)); fwrite(STDERR, str_repeat("e", 100000));',
    ], ['env' => ['PATH' => (string) getenv('PATH')]]);

    expect($captureResult['status'])->toBe(0)
        ->and($captureResult['stdout'])->toEndWith("\n[output truncated]\n")
        ->and($captureResult['stderr'])->toEndWith("\n[output truncated]\n")
        ->and(strlen($captureResult['stdout']))->toBeLessThan(65_600)
        ->and(strlen($captureResult['stderr']))->toBeLessThan(65_600);
});
