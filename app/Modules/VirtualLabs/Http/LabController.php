<?php

declare(strict_types=1);

namespace App\Modules\VirtualLabs\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Learning\LearningService;
use App\Modules\VirtualLabs\LabCatalogue;
use App\Modules\VirtualLabs\LabGateway;
use App\Modules\VirtualLabs\LabResult;
use App\Modules\VirtualLabs\LabSession;
use App\Modules\VirtualLabs\Seed;

/**
 * Lab screens. Learners: start, work, check, end. Teachers: the catalogue
 * and a "try it" page that marks answers without keeping anything.
 */
final class LabController
{
    /** A teacher's try-it page stays valid this long. */
    private const TRY_SECONDS = 7200;

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly LabCatalogue $catalogue,
        private readonly LabGateway $gateway,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly LearningService $learning,
        private readonly string $signingKey,
    ) {
    }

    public function start(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $user = RequestContext::user($request);
        $course = $this->courses->findBySlug($tenant->id, $request->routeParam('slug'));
        if ($course === null || !$course->isPublished()) {
            throw HttpException::notFound();
        }
        $lesson = $this->lessons->findInCourse($tenant->id, $course->id, $request->routeInt('lesson')) ?? throw HttpException::notFound();
        $enrolment = $this->learning->requireEnrolment($course, $user);
        try {
            $session = $this->gateway->launch($course, $lesson, $enrolment, $user, $request->clientIp);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id);
        }

        return Response::redirect('/labs/' . $session->id);
    }

    public function show(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $session = $this->gateway->sessionFor($tenant, RequestContext::user($request), $request->routeInt('session'));
        [$course, $lesson] = $this->placeOf($session);
        $lab = $this->gateway->labForSession($session);
        $params = $this->gateway->paramsFor($session);
        $result = $session->status === LabSession::SUBMITTED
            ? new LabResult((int) $session->score, $session->feedback, $session->evidence)
            : null;

        return Response::html($this->view->render('labs/session', [
            'pageTitle' => $lab->definition()->title,
            'definition' => $lab->definition(),
            'session' => $session,
            'course' => $course,
            'lesson' => $lesson,
            'params' => $params['public'],
            'fields' => $lab->fields($params['public']),
            'result' => $result,
            'secondsLeft' => $session->secondsLeft(time()),
            'action' => '/labs/' . $session->id . '/submit',
            'restart' => '/courses/' . $course->slug . '/lessons/' . $lesson->id . '/lab',
            'back' => '/courses/' . $course->slug . '/lessons/' . $lesson->id,
            'next' => $this->nextAfter($course, $lesson),
            'preview' => null,
        ]));
    }

    public function submit(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $id = $request->routeInt('session');
        try {
            $courseDone = $this->gateway->submit($tenant, RequestContext::user($request), $id, $request->inputArray('answers'), $request->clientIp);
            if ($courseDone) {
                $this->session->flash('success', 'Lab passed, and that completes the course. Well done!');
            }
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect('/labs/' . $id);
    }

    public function end(Request $request): Response
    {
        $session = $this->gateway->end(RequestContext::tenant($request), RequestContext::user($request), $request->routeInt('session'));
        [$course, $lesson] = $this->placeOf($session);
        $this->session->flash('success', 'Lab ended. Nothing was marked; start it again whenever you are ready.');

        return Response::redirect('/courses/' . $course->slug . '/lessons/' . $lesson->id);
    }

    /**
     * The sandbox for learner code: an empty page that runs code it is sent
     * inside a Web Worker. It is loaded in an iframe with sandbox="allow-scripts"
     * (a unique, opaque origin: no cookies, no access to this site's pages),
     * and its CSP forbids every network request and every outside script.
     */
    public function runner(Request $request): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $script = (string) file_get_contents(dirname(__DIR__, 4) . '/public/assets/labs/runner.js');
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Code runner</title></head><body>'
            . '<script nonce="' . $nonce . '">' . $script . '</script></body></html>';

        return Response::html($html)
            ->withHeader('Content-Security-Policy', "default-src 'none'; script-src 'nonce-" . $nonce . "' 'unsafe-eval'; worker-src blob:; "
                . "connect-src 'none'; img-src 'none'; style-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'")
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * The web page preview for the Code studio (ADR 0027). Served on its own,
     * framed with sandbox="allow-scripts" (an opaque origin: no cookies, no
     * storage, no access to the page around it). The learner's page arrives by
     * postMessage and is written into this document. Its policy allows the
     * page's own inline scripts and styles and data: images, and nothing that
     * leaves the browser: no requests, no forms, no plugins.
     */
    public function webRunner(Request $request): Response
    {
        // phpcs:disable Generic.Files.LineLength
        $boot = <<<'JS'
(function () {
  var started = false;
  window.addEventListener('message', function (e) {
    if (e.source !== window.parent || started) { return; }
    var d = e.data || {};
    if (typeof d.html !== 'string' || d.html.length > 60000) { return; }
    started = true;
    var capture = '<script>(function(){var n=0;function s(k,a){if(n++>200){return;}var t=Array.prototype.map.call(a,function(v){if(typeof v==="string"){return v;}try{return JSON.stringify(v);}catch(x){return String(v);}}).join(" ");parent.postMessage({webLog:true,kind:k,text:String(t).slice(0,1000)},"*");}' +
      '["log","info","warn","error"].forEach(function(k){console[k]=function(){s(k,arguments);};});' +
      'window.onerror=function(m,u,l){s("error",[String(m)+(l?" (line "+l+")":"")]);};' +
      'window.alert=function(m){s("alert",["alert: "+m]);};window.prompt=function(m){s("alert",["prompt: "+m]);return null;};window.confirm=function(m){s("alert",["confirm: "+m]);return false;};})();</scr' + 'ipt>';
    document.open();
    document.write(capture + d.html);
    document.close();
  });
  window.parent.postMessage({ webReady: true }, '*');
})();
JS;
        // phpcs:enable Generic.Files.LineLength
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Preview</title></head><body><script>' . $boot . '</script></body></html>';

        return Response::html($html)
            ->withHeader('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; font-src data:; media-src data:; "
                . "connect-src 'none'; form-action 'none'; base-uri 'none'; frame-src 'none'; worker-src 'none'; frame-ancestors 'self'")
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function catalogue(Request $request): Response
    {
        return Response::html($this->view->render('labs/catalogue', [
            'pageTitle' => 'Lab catalogue',
            'labs' => $this->catalogue->definitions(),
        ]));
    }

    /** A teacher tries a lab: same screen, nothing stored. The seed travels signed in the form. */
    public function try(Request $request): Response
    {
        $lab = $this->catalogue->find($request->routeParam('key')) ?? throw HttpException::notFound();
        $seed = Seed::fresh();

        return $this->renderTry($lab, $seed, time() + self::TRY_SECONDS, null);
    }

    public function checkTry(Request $request): Response
    {
        $lab = $this->catalogue->find($request->routeParam('key')) ?? throw HttpException::notFound();
        $seed = $request->input('seed', '') ?? '';
        $until = (int) ($request->input('until', '0') ?? '0');
        $signature = $request->input('signature', '') ?? '';
        if (
            preg_match('/^[a-f0-9]{32}$/', $seed) !== 1 || $until < time()
            || !hash_equals($this->sign($lab->definition()->key, $seed, $until), $signature)
        ) {
            $this->session->flash('error', 'That practice run has expired. Here is a fresh one.');

            return Response::redirect('/teach/labs/' . $lab->definition()->key);
        }
        $answers = array_map(static fn (string $a): string => mb_substr($a, 0, 20000), $request->inputArray('answers'));
        $result = $lab->check($lab->params(new Seed($seed)), $answers);

        return $this->renderTry($lab, $seed, $until, $result);
    }

    private function renderTry(\App\Modules\VirtualLabs\Lab $lab, string $seed, int $until, ?LabResult $result): Response
    {
        $definition = $lab->definition();
        $params = $lab->params(new Seed($seed));

        return Response::html($this->view->render('labs/session', [
            'pageTitle' => 'Try: ' . $definition->title,
            'definition' => $definition,
            'session' => null,
            'course' => null,
            'lesson' => null,
            'params' => $params['public'],
            'fields' => $lab->fields($params['public']),
            'result' => $result,
            'secondsLeft' => $definition->minutes * 60,
            'action' => '/teach/labs/' . $definition->key,
            'restart' => '/teach/labs/' . $definition->key,
            'back' => '/teach/labs',
            'next' => null,
            'preview' => ['seed' => $seed, 'until' => $until, 'signature' => $this->sign($definition->key, $seed, $until), 'pass' => $definition->passMark],
        ]));
    }

    private function sign(string $key, string $seed, int $until): string
    {
        return hash_hmac('sha256', 'lab-try|' . $key . '|' . $seed . '|' . $until, $this->signingKey);
    }

    /** @return array{0: Course, 1: Lesson} */
    private function placeOf(LabSession $session): array
    {
        $course = $this->courses->findById($session->tenantId, $session->courseId);
        $lesson = $course === null ? null : $this->lessons->findInCourse($session->tenantId, $course->id, $session->lessonId);
        if ($course === null || $lesson === null) {
            throw HttpException::notFound();
        }

        return [$course, $lesson];
    }

    private function nextAfter(Course $course, Lesson $lesson): ?Lesson
    {
        $ordered = $this->lessons->orderedLessons($course->tenantId, $course->id);
        foreach ($ordered as $i => $l) {
            if ($l->id === $lesson->id) {
                return $ordered[$i + 1] ?? null;
            }
        }

        return null;
    }
}
