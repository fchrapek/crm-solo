<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Agent\AgentAbilities;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * bin/crm with CRM_REMOTE_URL set, run against a recording `curl` (and
 * `security`) stub on PATH: what it sends, where the token travels, and how
 * the answer becomes output and an exit status. No network is touched.
 */
final class CrmShimRemoteModeTest extends TestCase
{
    /** Records argv and stdin, then answers with $STUB_STATUS, $STUB_EXIT and $STUB_BODY. */
    private const CURL_STUB = <<<'BASH'
        #!/usr/bin/env bash
        S="$STUB_DIR"
        : > "$S/curl.args"
        for a in "$@"; do printf '%s\n' "$a" >> "$S/curl.args"; done
        if [ -t 0 ]; then : > "$S/curl.stdin"; else cat > "$S/curl.stdin"; fi
        out=""; head=""
        while [ $# -gt 0 ]; do
          case "$1" in
            -o) out="$2"; shift ;;
            -D) head="$2"; shift ;;
          esac
          shift
        done
        {
          printf 'HTTP/1.1 %s\r\nX-Crm-Exit: %s\r\n' "$STUB_STATUS" "$STUB_EXIT"
          [ -z "$STUB_HEADER" ] || printf '%s\r\n' "$STUB_HEADER"
          printf '\r\n'
        } > "$head"
        printf '%s' "$STUB_BODY" > "$out"
        printf '%s' "$STUB_STATUS"
        BASH;

    private const SECURITY_STUB = <<<'BASH'
        #!/usr/bin/env bash
        [ "$3" = "crm-solo-test" ] || exit 44
        printf 'crmsolo_keychain_secret\n'
        BASH;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/crm-shim-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir.'/bin');
        File::put($this->dir.'/bin/curl', self::CURL_STUB);
        File::put($this->dir.'/bin/security', self::SECURITY_STUB);
        chmod($this->dir.'/bin/curl', 0755);
        chmod($this->dir.'/bin/security', 0755);
        File::put($this->dir.'/remote.headers', "CF-Access-Client-Id: id.access\nCF-Access-Client-Secret: sec123\n");
        chmod($this->dir.'/remote.headers', 0600);
        File::put($this->dir.'/agent.token', "1|crmsolo_file_secret\n");
        chmod($this->dir.'/agent.token', 0600);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_each_argument_travels_as_one_encoded_field_and_the_answer_is_printed_with_its_exit(): void
    {
        $run = $this->crm(['note', 'Smoke Co', 'It\'s "quoted" $HOME `x` --json'], body: "Noted.\n", exit: 3);

        $this->assertSame(3, $run->getExitCode());
        $this->assertSame("Noted.\n", $run->getOutput());
        $args = $this->curlArgs();
        $this->assertContains('args[]=Smoke Co', $args);
        $this->assertContains('args[]=It\'s "quoted" $HOME `x` --json', $args);
        $this->assertContains('verb=note', $args);
        $this->assertContains('http://127.0.0.1:8199/agent/verb', $args);
        $this->assertContains('@'.$this->dir.'/remote.headers', $args);
    }

    public function test_the_token_never_appears_on_the_command_line(): void
    {
        $this->crm(['today'], env: ['CRM_REMOTE_KEYCHAIN_SERVICE' => 'crm-solo-test', 'CRM_REMOTE_HEADERS_FILE' => $this->dir.'/none']);

        $this->assertStringNotContainsString('crmsolo_keychain_secret', implode("\n", $this->curlArgs()));
        $this->assertSame("Authorization: Bearer crmsolo_keychain_secret\n", File::get($this->dir.'/curl.stdin'));

        File::put($this->dir.'/agent.token', "2|crmsolo_token_file_secret\n");
        chmod($this->dir.'/agent.token', 0600);
        $this->crm(['today'], env: ['CRM_REMOTE_TOKEN_FILE' => $this->dir.'/agent.token', 'CRM_REMOTE_HEADERS_FILE' => $this->dir.'/none']);
        $this->assertStringNotContainsString('crmsolo_token_file_secret', implode("\n", $this->curlArgs()));
        $this->assertSame("Authorization: Bearer 2|crmsolo_token_file_secret\n", File::get($this->dir.'/curl.stdin'));

        $this->crm(['today']);
        $this->assertStringNotContainsString('crmsolo_file_secret', implode("\n", $this->curlArgs()));
    }

    public function test_a_token_file_others_can_read_is_refused(): void
    {
        File::put($this->dir.'/agent.token', "2|crmsolo_token_file_secret\n");
        chmod($this->dir.'/agent.token', 0644);

        $run = $this->crm(['today'], env: ['CRM_REMOTE_TOKEN_FILE' => $this->dir.'/agent.token']);

        $this->assertSame(77, $run->getExitCode());
        $this->assertFileDoesNotExist($this->dir.'/curl.args');
    }

    public function test_without_any_token_nothing_is_sent(): void
    {
        $run = $this->crm(['today'], env: ['CRM_REMOTE_TOKEN_FILE' => $this->dir.'/none']);

        $this->assertSame(77, $run->getExitCode());
        $this->assertStringContainsString('no token', $run->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/curl.args');
    }

    public function test_the_session_id_is_sent_when_exported(): void
    {
        $this->crm(['today'], env: ['CRM_SESSION_ID' => 'abc-123']);

        $this->assertContains('X-Crm-Session: abc-123', $this->curlArgs());
    }

    public function test_verbs_outside_the_remote_list_are_refused_before_any_request(): void
    {
        $run = $this->crm(['migrate', '--force']);

        $this->assertSame(64, $run->getExitCode());
        $this->assertFileDoesNotExist($this->dir.'/curl.args');
    }

    public function test_a_header_file_others_can_read_is_refused(): void
    {
        chmod($this->dir.'/remote.headers', 0644);

        $run = $this->crm(['today']);

        $this->assertSame(77, $run->getExitCode());
        $this->assertStringContainsString('chmod 600', $run->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/curl.args');
    }

    public function test_plain_http_is_allowed_only_for_localhost(): void
    {
        $run = $this->crm(['today'], env: ['CRM_REMOTE_URL' => 'http://app.example.test']);

        $this->assertSame(64, $run->getExitCode());
        $this->assertFileDoesNotExist($this->dir.'/curl.args');
    }

    public function test_a_refused_token_and_an_access_redirect_explain_themselves(): void
    {
        $refused = $this->crm(['today'], status: '401');
        $this->assertSame(77, $refused->getExitCode());
        $this->assertStringContainsString('refused', $refused->getErrorOutput());

        $redirected = $this->crm(['today'], status: '302');
        $this->assertSame(77, $redirected->getExitCode());
        $this->assertStringContainsString('CF-Access-Client-Id', $redirected->getErrorOutput());

        $forbidden = $this->crm(['note', 'x', 'y'], status: '403', body: "crm: this token cannot run \"note\".\n", exit: 1);
        $this->assertSame(1, $forbidden->getExitCode());
        $this->assertSame('', $forbidden->getOutput());
        $this->assertStringContainsString('cannot run', $forbidden->getErrorOutput());
    }

    public function test_a_service_auth_refusal_from_access_explains_itself_instead_of_printing_the_access_body(): void
    {
        $denied = $this->crm(['today'], status: '403', body: '{"message":"Forbidden.","aud":"x"}', env: ['STUB_HEADER' => 'cf-access-domain: app.example.test']);

        $this->assertSame(77, $denied->getExitCode());
        $this->assertSame('', $denied->getOutput());
        $this->assertStringContainsString('Cloudflare Access refused', $denied->getErrorOutput());
        $this->assertStringContainsString('CF-Access-Client-Id', $denied->getErrorOutput());
        $this->assertStringNotContainsString('aud', $denied->getErrorOutput());
    }

    public function test_the_mcp_headers_helper_prints_the_token_and_access_headers_as_json(): void
    {
        File::put($this->dir.'/agent.token', "3|crmsolo_helper\n");
        chmod($this->dir.'/agent.token', 0600);
        File::put($this->dir.'/remote.headers', "CF-Access-Client-Id: id.access\r\nCF-Access-Client-Secret: sec123\nX-Other: ignored\n");

        $run = new Process(['sh', base_path('bin/crm-mcp-headers')], base_path(), [
            'PATH' => '/usr/bin:/bin', 'HOME' => $this->dir, 'CRM_SESSION_ID' => 's-1', 'CRM_REMOTE_KEYCHAIN_SERVICE' => false,
            'CRM_REMOTE_TOKEN_FILE' => $this->dir.'/agent.token', 'CRM_REMOTE_HEADERS_FILE' => $this->dir.'/remote.headers',
        ]);
        $run->run();

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame([
            'Authorization' => 'Bearer 3|crmsolo_helper',
            'CF-Access-Client-Id' => 'id.access',
            'CF-Access-Client-Secret' => 'sec123',
            'X-Crm-Session' => 's-1',
        ], json_decode($run->getOutput(), true, flags: JSON_THROW_ON_ERROR));

        chmod($this->dir.'/agent.token', 0640);
        $run->run();
        $this->assertSame(77, $run->getExitCode());
    }

    public function test_a_bearer_token_in_the_access_header_file_is_refused(): void
    {
        File::put($this->dir.'/remote.headers', "Authorization: Bearer 1|crmsolo_in_headers\n");

        $run = $this->crm(['today']);

        $this->assertSame(65, $run->getExitCode());
        $this->assertStringContainsString('token file', $run->getErrorOutput());
        $this->assertFileDoesNotExist($this->dir.'/curl.args');
    }

    public function test_the_shim_offers_exactly_the_verbs_the_server_runs(): void
    {
        $shim = File::get(base_path('bin/crm'));
        preg_match('/^# remote-verbs: (.+)$/m', $shim, $declared);
        $this->assertNotEmpty($declared, 'bin/crm declares its remote verb list');
        $listed = explode(' ', mb_trim($declared[1]));
        sort($listed);
        $server = array_keys(AgentAbilities::VERBS);
        sort($server);

        $this->assertSame($server, $listed);
        foreach ($server as $verb) {
            $this->crm([$verb]);
            $this->assertContains('verb='.$verb, $this->curlArgs(), "{$verb} reaches the server");
        }
    }

    public function test_an_attachment_is_fetched_by_id_to_stdout(): void
    {
        $run = $this->crm(['attachment', '42'], body: 'file-bytes');

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame('file-bytes', $run->getOutput());
        $this->assertContains('http://127.0.0.1:8199/agent/attachments/42', $this->curlArgs());

        $this->assertSame(64, $this->crm(['attachment', '42; ls'])->getExitCode());
    }

    public function test_without_a_remote_url_the_shim_runs_artisan_locally_and_never_calls_curl(): void
    {
        File::put($this->dir.'/bin/php', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$STUB_DIR/php.args\"\n");
        chmod($this->dir.'/bin/php', 0755);

        $run = $this->crm(['today', '--json'], env: ['CRM_REMOTE_URL' => false]);

        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        $this->assertSame(
            [base_path('artisan'), 'crm:today', '--json'],
            explode("\n", mb_rtrim(File::get($this->dir.'/php.args'), "\n")),
        );
        $this->assertFileDoesNotExist($this->dir.'/curl.args');
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, string|false>  $env
     */
    private function crm(array $args, string $status = '200', string $body = '', int $exit = 0, array $env = []): Process
    {
        $process = new Process(['sh', base_path('bin/crm'), ...$args], base_path(), [
            'PATH' => $this->dir.'/bin:/usr/bin:/bin',
            'HOME' => $this->dir,
            'STUB_DIR' => $this->dir,
            'STUB_STATUS' => $status,
            'STUB_EXIT' => (string) $exit,
            'STUB_BODY' => $body,
            'STUB_HEADER' => '',
            'CRM_REMOTE_URL' => 'http://127.0.0.1:8199',
            'CRM_REMOTE_HEADERS_FILE' => $this->dir.'/remote.headers',
            'CRM_SESSION_ID' => false,
            'CRM_REMOTE_KEYCHAIN_SERVICE' => false,
            'CRM_REMOTE_TOKEN_FILE' => $this->dir.'/agent.token',
            ...$env,
        ]);
        $process->run();

        return $process;
    }

    /** @return list<string> */
    private function curlArgs(): array
    {
        return explode("\n", mb_rtrim(File::get($this->dir.'/curl.args'), "\n"));
    }
}
