<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

/**
 * The curl_multi engine of `CurlTransport` and `CurlHttpChannel`: one multi handle, made at the first use, and its
 * transfers, each with its caller's payload. It keeps the rules that stop a waiting caller from hanging: a transfer
 * counts before its handle is added (a signal drain that runs right after the add must see it), a refused handle
 * leaves the multi handle again, a failed `curl_multi_exec()` fails every transfer, and every finished handle leaves
 * the multi handle. The callers choose what a result means (retries, callbacks) and suppress PHP warnings.
 *
 * A signal handler runs between two opcodes, so also inside a PHP callback of `curl_multi_exec()` (the write and header
 * functions of `CurlHttpChannel`; `CurlTransport` sets none). libcurl answers every multi call from there with
 * `CURLM_RECURSIVE_API_CALL`. Such an answer leaves every handle and transfer as it is: removing or failing a transfer
 * there would free a handle that libcurl still uses, and PHP would crash when the callback returns. A refused add
 * waits for the next `run()`. (`CurlHttpChannel` keeps its drains out of its callbacks; this rule is the safety net.)
 *
 * @template TPayload
 *
 * @internal
 */
final class CurlMulti
{
    /** libcurl's `CURLM_RECURSIVE_API_CALL` (7.59.0+), which PHP does not define: a multi call from inside a callback. */
    public const int RECURSIVE_API_CALL = 8;

    /** Null until the first use. */
    private ?\CurlMultiHandle $multi = null;

    /** @var array<int, array{handle: \CurlHandle, payload: TPayload}> transfers in `$multi`, keyed by `spl_object_id($handle)` */
    private array $transfers = [];

    /** @var \Closure(string, int): int */
    private readonly \Closure $multiStatus;

    /** @var array<int, int> the ids of the transfers whose add libcurl refused from inside a callback: the next `run()` adds them */
    private array $unadded = [];

    /**
     * @param (\Closure(string, int): int)|null $multiStatus tests only: receives the function name and the CURLM code of each
     *                                                       `curl_multi_add_handle()` and `curl_multi_exec()` call, returns the code to act on
     */
    public function __construct(?\Closure $multiStatus = null)
    {
        $this->multiStatus = $multiStatus ?? static fn(string $function, int $code): int => $code;
    }

    /**
     * Starts a transfer with `$options`. Null when it runs, or waits for the next `run()` (an add from inside a
     * callback); else the error text, and nothing of it is left behind. A throwable of curl_setopt_array() (a NUL byte in
     * a string option) reaches the caller.
     *
     * @param array<int, mixed> $options
     * @param TPayload          $payload
     */
    public function open(array $options, mixed $payload): ?string
    {
        $handle = curl_init();
        if ($handle === false) {
            return 'cURL error: curl_init() failed';
        }
        if (!curl_setopt_array($handle, $options)) {
            return 'cURL error: curl_setopt_array() failed'; // a rejected option, such as a URL over 8 MB: never added
        }
        // Recorded before the add: a signal drain that runs right after the add must see this transfer.
        $id = spl_object_id($handle);
        $this->transfers[$id] = ['handle' => $handle, 'payload' => $payload];

        return $this->add($id, $handle);
    }

    /**
     * Runs the transfers, then hands each transfer this run finished to `$finished` with its handle and CURLE
     * result. When the multi handle cannot run them, every transfer goes to `$failed` with the error text first,
     * so that nothing waits for them forever. Called from inside a callback of the running `curl_multi_exec()`, it
     * changes nothing (libcurl refuses the nested call) and returns false.
     *
     * @param \Closure(TPayload, \CurlHandle, int): void $finished
     * @param \Closure(TPayload, string): void           $failed
     */
    public function run(\Closure $finished, \Closure $failed): bool
    {
        foreach (array_intersect_key($this->transfers, $this->unadded) as $id => $transfer) {
            $error = $this->add($id, $transfer['handle']);
            if ($error !== null) {
                $failed($transfer['payload'], $error);
            }
        }
        $code = ($this->multiStatus)('curl_multi_exec', curl_multi_exec($this->multi(), $running));
        if ($code === self::RECURSIVE_API_CALL) {
            return false; // from inside a callback of the running curl_multi_exec(): every transfer stays as it is
        }
        if ($code !== CURLM_OK) {
            $transfers = $this->transfers;
            $this->transfers = [];
            $this->unadded = [];
            foreach ($transfers as $transfer) {
                curl_multi_remove_handle($this->multi(), $transfer['handle']);
                $failed($transfer['payload'], CurlSupport::multiError($code));
            }
        }
        while (true) {
            /** @var array{msg: int, result: int, handle: \CurlHandle}|false $info the documented shape */
            $info = curl_multi_info_read($this->multi());
            if ($info === false) {
                return true;
            }
            $id = spl_object_id($info['handle']);
            $transfer = $this->transfers[$id] ?? null;
            unset($this->transfers[$id]);
            curl_multi_remove_handle($this->multi(), $info['handle']);
            if ($transfer !== null) {
                $finished($transfer['payload'], $info['handle'], $info['result']);
            }
        }
    }

    /** Blocks until a transfer can progress or `$seconds` pass (0 or more, see `CurlSupport::waitSeconds()`). Does no work. */
    public function select(float $seconds): void
    {
        if (curl_multi_select($this->multi(), $seconds) === -1) {
            usleep(1000); // the wait failed: a caller's loop must not spin
        }
    }

    /** Transfers in the multi handle (or waiting for the next `run()` to add them). */
    public function count(): int
    {
        return count($this->transfers);
    }

    /** @return list<TPayload> the payloads of the transfers in the multi handle */
    public function payloads(): array
    {
        return array_column($this->transfers, 'payload');
    }

    /**
     * Adds transfer `$id`. Null when libcurl took it, or refused it from inside a callback (it then waits for the next
     * `run()`); else the error text, and the transfer is gone.
     */
    private function add(int $id, \CurlHandle $handle): ?string
    {
        $code = ($this->multiStatus)('curl_multi_add_handle', curl_multi_add_handle($this->multi(), $handle));
        if ($code === CURLM_OK) {
            unset($this->unadded[$id]);

            return null;
        }
        // PHP keeps the handle in its list of the multi handle even when libcurl refused it. libcurl never took this
        // one, so the remove frees nothing that libcurl uses.
        curl_multi_remove_handle($this->multi(), $handle);
        if ($code === self::RECURSIVE_API_CALL) {
            $this->unadded[$id] = $id;

            return null;
        }
        unset($this->transfers[$id], $this->unadded[$id]);

        return CurlSupport::multiError($code);
    }

    private function multi(): \CurlMultiHandle
    {
        return $this->multi ??= curl_multi_init();
    }
}
