<?php

declare(strict_types=1);

namespace App\Modules\Playground\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\View\View;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Playground\SnippetRepository;

/**
 * /playground: write JavaScript and run it in the browser's sandboxed code
 * runner (the one programming labs use: no network, no page access, stopped
 * after a few seconds), and keep the snippets you want (ADR 0020).
 */
final class PlaygroundController
{
    public const MAX_CODE = 20000;

    // phpcs:disable Generic.Files.LineLength
    /** title => code: small starting points, from first steps to things programmers try. */
    public const EXAMPLES = [
        'Hello' => "// Press Run (or Ctrl + Enter). console.log prints a line below.\nconst name = 'Ada';\nconsole.log('Hello, ' + name + '!');\n",
        'Loops' => "// Times table for 7\nfor (let i = 1; i <= 12; i++) {\n  console.log(`7 x \${i} = \${7 * i}`);\n}\n",
        'Functions and if' => "function grade(score) {\n  if (score >= 70) return 'A';\n  else if (score >= 60) return 'B';\n  else if (score >= 50) return 'C';\n  return 'F';\n}\n\nfor (const s of [85, 64, 50, 49]) {\n  console.log(s, grade(s));\n}\n",
        'Arrays and objects' => "const learners = [\n  { name: 'Amaka', score: 82 },\n  { name: 'Tobi', score: 67 },\n  { name: 'Ada', score: 91 },\n];\n\nconst best = [...learners].sort((a, b) => b.score - a.score)[0];\nconsole.log('Top score:', best.name, best.score);\nconsole.log('Average:', learners.reduce((t, l) => t + l.score, 0) / learners.length);\n",
        'Subnet maths' => "// Network address of 192.168.10.37/26\nconst ip = [192, 168, 10, 37];\nconst prefix = 26;\nconst toInt = (a) => a.reduce((n, o) => (n << 8) + o, 0) >>> 0;\nconst toIp = (n) => [24, 16, 8, 0].map((s) => (n >>> s) & 255).join('.');\nconst mask = (0xffffffff << (32 - prefix)) >>> 0;\nconsole.log('Mask     ', toIp(mask));\nconsole.log('Network  ', toIp((toInt(ip) & mask) >>> 0));\nconsole.log('Broadcast', toIp((toInt(ip) | ~mask) >>> 0));\nconsole.log('Hosts    ', 2 ** (32 - prefix) - 2);\n",
        'Async and promises' => "const wait = (ms) => new Promise((done) => setTimeout(done, ms));\n\nasync function main() {\n  console.log('Starting');\n  await wait(100);\n  console.log('Finished after 100 ms');\n}\nmain();\n",
    ];
    // phpcs:enable Generic.Files.LineLength

    public function __construct(private readonly View $view, private readonly Session $session, private readonly SnippetRepository $snippets)
    {
    }

    public function index(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = $this->member($request);
        $open = null;
        $id = (int) ($request->query('snippet', '0') ?? '0');
        if ($id > 0) {
            $open = $this->snippets->find($tenant->id, $me->user->id, $id) ?? throw HttpException::notFound();
        }

        return Response::html($this->view->render('playground/index', [
            'pageTitle' => 'Code playground',
            'snippets' => $this->snippets->list($tenant->id, $me->user->id),
            'open' => $open,
            'code' => $open['code'] ?? self::EXAMPLES['Hello'],
            'examples' => self::EXAMPLES,
        ]));
    }

    public function save(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = $this->member($request);
        $title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $request->input('title', '') ?? '')), 0, 120);
        $code = str_replace("\r\n", "\n", $request->input('code', '') ?? '');
        $id = (int) ($request->input('id', '0') ?? '0');
        if (trim($code) === '' || mb_strlen($code) > self::MAX_CODE) {
            $this->session->flash('error', trim($code) === '' ? 'There is no code to save.' : 'Snippets can be up to 20,000 characters.');

            return Response::redirect('/playground' . ($id > 0 ? '?snippet=' . $id : ''));
        }
        $title = $title !== '' ? $title : 'Untitled, ' . date('j M H:i');
        if ($id > 0 && $this->snippets->update($tenant->id, $me->user->id, $id, $title, $code)) {
            $this->session->flash('success', 'Saved "' . $title . '".');

            return Response::redirect('/playground?snippet=' . $id);
        }
        if ($this->snippets->count($tenant->id, $me->user->id) >= SnippetRepository::MAX_PER_PERSON) {
            $this->session->flash('error', 'You have 50 saved snippets. Delete one you no longer need first.');

            return Response::redirect('/playground');
        }
        $newId = $this->snippets->create($tenant->id, $me->user->id, $title, $code);
        $this->session->flash('success', 'Saved "' . $title . '". Only you can see your snippets.');

        return Response::redirect('/playground?snippet=' . $newId);
    }

    public function delete(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = $this->member($request);
        $this->snippets->delete($tenant->id, $me->user->id, $request->routeInt('snippet'));
        $this->session->flash('success', 'Snippet deleted.');

        return Response::redirect('/playground');
    }

    private function member(Request $request): CurrentUser
    {
        $me = RequestContext::user($request);
        if (!$me->can(Permission::CourseLearn) && !$me->can(Permission::CourseManage)) {
            throw HttpException::forbidden();
        }

        return $me;
    }
}
