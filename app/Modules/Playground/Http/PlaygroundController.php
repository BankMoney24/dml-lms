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
use App\Modules\Playground\Challenges;
use App\Modules\Playground\SnippetRepository;

/**
 * The Code studio (/playground; ADR 0020, extended in ADR 0027): write
 * JavaScript, Python or a web page and run it in the browser's sealed
 * runners (no network, no access to this site, stopped after a few
 * seconds). Keep the snippets you want, and practise with challenges whose
 * tests run in the same runners. No learner code ever runs on the server.
 */
final class PlaygroundController
{
    public const MAX_CODE = 20000;
    public const LANGUAGES = ['javascript' => 'JavaScript', 'python' => 'Python', 'web' => 'Web page'];

    // phpcs:disable Generic.Files.LineLength
    /** language => title => code: small starting points, from first steps to things programmers try. */
    public const EXAMPLES = [
        'javascript' => [
            'Hello' => "// Press Run (or Ctrl + Enter). console.log prints a line below.\nconst name = 'Ada';\nconsole.log('Hello, ' + name + '!');\n",
            'Loops' => "// Times table for 7\nfor (let i = 1; i <= 12; i++) {\n  console.log(`7 x \${i} = \${7 * i}`);\n}\n",
            'Functions and if' => "function grade(score) {\n  if (score >= 70) return 'A';\n  else if (score >= 60) return 'B';\n  else if (score >= 50) return 'C';\n  return 'F';\n}\n\nfor (const s of [85, 64, 50, 49]) {\n  console.log(s, grade(s));\n}\n",
            'Arrays and objects' => "const learners = [\n  { name: 'Amaka', score: 82 },\n  { name: 'Tobi', score: 67 },\n  { name: 'Ada', score: 91 },\n];\n\nconst best = [...learners].sort((a, b) => b.score - a.score)[0];\nconsole.log('Top score:', best.name, best.score);\nconsole.log('Average:', learners.reduce((t, l) => t + l.score, 0) / learners.length);\n",
            'Reading input' => "// Type lines in the Input box. prompt() reads the next line.\nconst name = prompt('What is your name?');\nconst age = Number(prompt('How old are you?'));\nconsole.log(`Hello \${name}. In five years you will be \${age + 5}.`);\n",
            'Subnet maths' => "// Network address of 192.168.10.37/26\nconst ip = [192, 168, 10, 37];\nconst prefix = 26;\nconst toInt = (a) => a.reduce((n, o) => (n << 8) + o, 0) >>> 0;\nconst toIp = (n) => [24, 16, 8, 0].map((s) => (n >>> s) & 255).join('.');\nconst mask = (0xffffffff << (32 - prefix)) >>> 0;\nconsole.log('Mask     ', toIp(mask));\nconsole.log('Network  ', toIp((toInt(ip) & mask) >>> 0));\nconsole.log('Broadcast', toIp((toInt(ip) | ~mask) >>> 0));\nconsole.log('Hosts    ', 2 ** (32 - prefix) - 2);\n",
            'Async and promises' => "const wait = (ms) => new Promise((done) => setTimeout(done, ms));\n\nasync function main() {\n  console.log('Starting');\n  await wait(100);\n  console.log('Finished after 100 ms');\n}\nmain();\n",
        ],
        'python' => [
            'Hello' => "# Press Run (or Ctrl + Enter). print() shows a line below.\nname = \"Ada\"\nprint(\"Hello, \" + name + \"!\")\n",
            'Reading input' => "# Type lines in the Input box. input() reads the next line.\nname = input(\"What is your name? \")\nage = int(input(\"How old are you? \"))\nprint(f\"Hello {name}. In five years you will be {age + 5}.\")\n",
            'Loops' => "# Times table for 7\nfor i in range(1, 13):\n    print(f\"7 x {i} = {7 * i}\")\n",
            'Functions and if' => "def grade(score):\n    if score >= 70:\n        return \"A\"\n    elif score >= 60:\n        return \"B\"\n    elif score >= 50:\n        return \"C\"\n    return \"F\"\n\n\nfor s in [85, 64, 50, 49]:\n    print(s, grade(s))\n",
            'Lists and dictionaries' => "learners = [\n    {\"name\": \"Amaka\", \"score\": 82},\n    {\"name\": \"Tobi\", \"score\": 67},\n    {\"name\": \"Ada\", \"score\": 91},\n]\n\nbest = max(learners, key=lambda l: l[\"score\"])\nprint(\"Top score:\", best[\"name\"], best[\"score\"])\naverage = sum(l[\"score\"] for l in learners) / len(learners)\nprint(\"Average:\", round(average, 1))\n",
            'Classes' => "class Account:\n    def __init__(self, owner, balance=0):\n        self.owner = owner\n        self.balance = balance\n\n    def deposit(self, amount):\n        self.balance += amount\n        return self.balance\n\n    def withdraw(self, amount):\n        if amount > self.balance:\n            raise ValueError(\"Not enough money\")\n        self.balance -= amount\n        return self.balance\n\n\nacc = Account(\"Ngozi\", 5000)\nprint(acc.deposit(2500))\nprint(acc.withdraw(1000))\ntry:\n    acc.withdraw(100000)\nexcept ValueError as e:\n    print(\"Refused:\", e)\n",
            'Subnet maths' => "# Network address of 192.168.10.37/26\nip = [192, 168, 10, 37]\nprefix = 26\n\nvalue = 0\nfor octet in ip:\n    value = value * 256 + octet\nmask = (2 ** 32 - 1) - (2 ** (32 - prefix) - 1)\n\n\ndef dotted(n):\n    return \".\".join(str((n >> s) & 255) for s in (24, 16, 8, 0))\n\n\nprint(\"Mask     \", dotted(mask))\nprint(\"Network  \", dotted(value & mask))\nprint(\"Hosts    \", 2 ** (32 - prefix) - 2)\n",
        ],
        'web' => [
            'Hello page' => "<!doctype html>\n<html>\n<head>\n  <style>\n    body { font-family: sans-serif; margin: 2rem; background: #f5f6f8; color: #161a25; }\n    h1 { color: #1670ad; }\n    .card { background: white; padding: 1rem 1.5rem; border-radius: 12px; border-left: 6px solid #23a1de; }\n  </style>\n</head>\n<body>\n  <h1>My first page</h1>\n  <div class=\"card\">\n    <p>Edit the HTML and CSS, then press <strong>Run</strong>.</p>\n  </div>\n</body>\n</html>\n",
            'Button and counter' => "<!doctype html>\n<html>\n<head>\n  <style>\n    body { font-family: sans-serif; display: grid; place-items: center; min-height: 80vh; }\n    button { font-size: 1.25rem; padding: 0.6rem 1.4rem; border-radius: 999px; border: 0; background: #d52f2f; color: white; cursor: pointer; }\n    output { display: block; font-size: 3rem; text-align: center; margin: 1rem; }\n  </style>\n</head>\n<body>\n  <div>\n    <output id=\"count\">0</output>\n    <button id=\"add\">Add one</button>\n  </div>\n  <script>\n    let n = 0;\n    document.getElementById('add').addEventListener('click', () => {\n      n++;\n      document.getElementById('count').textContent = n;\n      console.log('Clicked', n, 'times');\n    });\n  </script>\n</body>\n</html>\n",
            'Times table maker' => "<!doctype html>\n<html>\n<head>\n  <style>\n    body { font-family: sans-serif; margin: 1.5rem; }\n    table { border-collapse: collapse; margin-top: 1rem; }\n    td { border: 1px solid #ccd; padding: 0.3rem 0.8rem; text-align: right; }\n  </style>\n</head>\n<body>\n  <label>Number <input id=\"n\" type=\"number\" value=\"7\" min=\"1\" max=\"20\"></label>\n  <table id=\"t\"></table>\n  <script>\n    const input = document.getElementById('n');\n    function draw() {\n      const n = Number(input.value);\n      let rows = '';\n      for (let i = 1; i <= 12; i++) rows += `<tr><td>\${n} x \${i}</td><td>\${n * i}</td></tr>`;\n      document.getElementById('t').innerHTML = rows;\n    }\n    input.addEventListener('input', draw);\n    draw();\n  </script>\n</body>\n</html>\n",
            'Profile card (CSS)' => "<!doctype html>\n<html>\n<head>\n  <style>\n    body { font-family: sans-serif; background: #161a25; display: grid; place-items: center; min-height: 90vh; margin: 0; }\n    .card { width: 18rem; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 12px 32px rgba(0,0,0,.3); }\n    .top { height: 6rem; background: linear-gradient(135deg, #1670ad, #23a1de); }\n    .avatar { width: 5rem; height: 5rem; border-radius: 50%; background: #d52f2f; color: white; display: grid; place-items: center; font-size: 2rem; margin: -2.5rem auto 0; border: 4px solid white; }\n    .body { padding: 1rem 1.5rem 1.5rem; text-align: center; }\n    h2 { margin: 0.5rem 0 0.25rem; }\n    p { color: #4b5468; margin: 0; }\n  </style>\n</head>\n<body>\n  <div class=\"card\">\n    <div class=\"top\"></div>\n    <div class=\"avatar\">A</div>\n    <div class=\"body\">\n      <h2>Ada Okafor</h2>\n      <p>Junior web developer</p>\n    </div>\n  </div>\n</body>\n</html>\n",
        ],
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
        $language = $open['language'] ?? self::language((string) ($request->query('lang', 'javascript') ?? 'javascript'));
        $first = array_key_first(self::EXAMPLES[$language]);

        return Response::html($this->view->render('playground/index', [
            'pageTitle' => 'Code studio',
            'snippets' => $this->snippets->list($tenant->id, $me->user->id),
            'open' => $open,
            'language' => $language,
            'code' => $open['code'] ?? self::EXAMPLES[$language][$first],
            'examples' => self::EXAMPLES,
            'solvedCount' => count($this->snippets->solved($tenant->id, $me->user->id)),
            'challengeCount' => count(Challenges::all()),
        ]));
    }

    public function save(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = $this->member($request);
        $title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $request->input('title', '') ?? '')), 0, 120);
        $code = str_replace("\r\n", "\n", $request->input('code', '') ?? '');
        $language = self::language((string) ($request->input('language', 'javascript') ?? 'javascript'));
        $id = (int) ($request->input('id', '0') ?? '0');
        if (trim($code) === '' || mb_strlen($code) > self::MAX_CODE) {
            $this->session->flash('error', trim($code) === '' ? 'There is no code to save.' : 'Snippets can be up to 20,000 characters.');

            return Response::redirect('/playground' . ($id > 0 ? '?snippet=' . $id : ($language === 'javascript' ? '' : '?lang=' . $language)));
        }
        $title = $title !== '' ? $title : 'Untitled, ' . date('j M H:i');
        if ($id > 0 && $this->snippets->update($tenant->id, $me->user->id, $id, $title, $code, $language)) {
            $this->session->flash('success', 'Saved "' . $title . '".');

            return Response::redirect('/playground?snippet=' . $id);
        }
        if ($this->snippets->count($tenant->id, $me->user->id) >= SnippetRepository::MAX_PER_PERSON) {
            $this->session->flash('error', 'You have 50 saved snippets. Delete one you no longer need first.');

            return Response::redirect('/playground' . ($language === 'javascript' ? '' : '?lang=' . $language));
        }
        $newId = $this->snippets->create($tenant->id, $me->user->id, $title, $code, $language);
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

    /** GET /playground/challenges */
    public function challenges(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = $this->member($request);

        return Response::html($this->view->render('playground/challenges', [
            'pageTitle' => 'Coding challenges',
            'challenges' => Challenges::all(),
            'solved' => $this->snippets->solved($tenant->id, $me->user->id),
        ]));
    }

    /** GET /playground/challenges/{key}?lang=python */
    public function challenge(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = $this->member($request);
        $key = $request->routeParam('key');
        $challenge = Challenges::find($key) ?? throw HttpException::notFound();
        $language = (string) ($request->query('lang', 'javascript') ?? 'javascript');
        $language = isset(Challenges::LANGUAGES[$language]) ? $language : 'javascript';
        $mine = $this->snippets->solution($tenant->id, $me->user->id, $key, $language);
        $keys = array_keys(Challenges::all());
        $at = (int) array_search($key, $keys, true);

        return Response::html($this->view->render('playground/challenge', [
            'pageTitle' => $challenge['title'] . ': coding challenge',
            'key' => $key,
            'challenge' => $challenge,
            'language' => $language,
            'code' => $mine['code'] ?? Challenges::starter($key, $language),
            'solved' => $mine !== null,
            'cases' => Challenges::cases($key),
            'fn' => $language === 'python' ? $challenge['py'] : $challenge['js'],
            'next' => $keys[$at + 1] ?? null,
        ]));
    }

    /**
     * POST /playground/challenges/{key}: the browser says every case passed.
     * Kept as the learner's practice record (the tests ran in their browser).
     */
    public function solved(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $me = $this->member($request);
        $key = $request->routeParam('key');
        $challenge = Challenges::find($key) ?? throw HttpException::notFound();
        $language = (string) ($request->input('language', '') ?? '');
        $code = str_replace("\r\n", "\n", $request->input('code', '') ?? '');
        if (!isset(Challenges::LANGUAGES[$language]) || trim($code) === '' || mb_strlen($code) > self::MAX_CODE) {
            throw HttpException::badRequest();
        }
        $fn = $language === 'python' ? $challenge['py'] : $challenge['js'];
        if (!str_contains($code, $fn)) {
            $this->session->flash('error', 'Your code needs a function called ' . $fn . '.');

            return Response::redirect('/playground/challenges/' . $key . '?lang=' . $language);
        }
        $this->snippets->recordSolve($tenant->id, $me->user->id, $key, $language, $code);
        $this->session->flash('success', '“' . $challenge['title'] . '” solved in ' . Challenges::LANGUAGES[$language] . '. Your code is saved with it.');

        return Response::redirect('/playground/challenges/' . $key . '?lang=' . $language);
    }

    private static function language(string $value): string
    {
        return isset(self::LANGUAGES[$value]) ? $value : 'javascript';
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
