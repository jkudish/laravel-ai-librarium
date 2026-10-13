<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\Stores\FilesystemRunStore;
use Jkudish\LaravelAiLibrarium\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$fixture = new class('offlineSignalProbe') extends TestCase
{
    public function boot(): void
    {
        $this->setUp();
    }

    public function application(): Application
    {
        return $this->app ?? throw new LogicException('Console fixture was not booted.');
    }
};
$fixture->boot();
// Laravel disables traps in testing; retain HTTP fakes in an ordinary console environment.
$fixture->application()->instance('env', 'verification');
$arguments = $_SERVER['argv'] ?? [];
if (! is_array($arguments) || ! is_string($arguments[1] ?? null) || ! is_string($arguments[2] ?? null)) {
    throw new LogicException('The fixture requires a root and mode.');
}
$root = $arguments[1];
$mode = $arguments[2];
config([
    'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
    'librarium.runs.store' => 'signal',
    'filesystems.disks.signal' => ['driver' => 'local', 'root' => $root, 'throw' => true],
    'ai.providers.openai.key' => 'dummy-offline-key',
]);
$local = Storage::disk('signal');
$disk = new class($local->getDriver(), $local->getAdapter(), $local->getConfig()) extends FilesystemAdapter
{
    public bool $armed = false;

    public function get($path): ?string
    {
        if ($this->armed && str_ends_with($path, '/run.json')) {
            $this->armed = false;
            $owner = json_decode((string) file_get_contents($this->path($path).'.lock'), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($owner) || ($owner['pid'] ?? null) !== getmypid()) {
                throw new LogicException('Signal barrier must hold its own real filesystem marker.');
            }
            fwrite(STDERR, "locked-read\n");
            fflush(STDERR);
            fgets(STDIN);
        }

        return parent::get($path);
    }
};
app(LibrariumManager::class)->extendStore('signal', fn () => new FilesystemRunStore($disk, 'runs'));
Http::preventStrayRequests();
Http::fake(['https://api.openai.com/v1/responses*' => function (Request $request) use ($root, $mode, $disk) {
    file_put_contents($root.'/requests.log', $request->method()."\n", FILE_APPEND);
    if ($request->method() === 'POST') {
        if ($mode === 'resume') {
            throw new LogicException('Resume must not resubmit.');
        }

        return Http::response(['id' => 'resp_signal_proof', 'status' => 'queued']);
    }
    if ($mode !== 'resume') {
        $disk->armed = $mode === 'locked-read';
        fwrite(STDERR, "awaiting-sleep\n");
        fflush(STDERR);

        return Http::response(['id' => 'resp_signal_proof', 'status' => 'in_progress']);
    }

    return Http::response(['id' => 'resp_signal_proof', 'status' => 'completed', 'model' => 'gpt-6.1-sol',
        'output' => [
            ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search']],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Recovered after real SIGINT.',
                'annotations' => [['type' => 'url_citation', 'url' => 'https://example.org/proof', 'title' => 'Proof']]]]],
        ],
    ]);
}]);
$input = $mode === 'resume'
    ? new ArrayInput(['command' => 'librarium:show', 'id' => $arguments[3] ?? null, '--wait' => true, '--json' => true])
    : new ArrayInput(['command' => 'librarium:research', 'question' => 'Offline signal proof', '--deep' => true, '--only' => 'openai', '--json' => true]);
$input->setInteractive(false);
exit($fixture->application()->make(Kernel::class)->handle($input, new ConsoleOutput));
