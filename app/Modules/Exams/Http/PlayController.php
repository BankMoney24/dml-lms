<?php

declare(strict_types=1);

namespace App\Modules\Exams\Http;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Security\RateLimiter;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Exams\Live\LiveGame;
use App\Modules\Exams\Live\LivePlayer;
use App\Modules\Exams\Live\LiveRepository;
use App\Modules\Exams\Live\LiveSession;

/**
 * Joining and playing a live quiz on a phone (ADR 0042), at /play. Members
 * join as themselves; guests (when the host allows them) with a nickname
 * that lives only in this browser's session.
 */
final class PlayController
{
    private const KEY = 'live_player';

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly LiveGame $game,
        private readonly LiveRepository $live,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     */
    public function join(Request $request, array $errors = [], array $old = [], int $status = 200): Response
    {
        $current = $this->current($request);
        if ($current !== null && $errors === [] && $current[0]->status !== 'finished' && $current[1]->removedAt === null && $request->query('pin') === null) {
            return Response::redirect('/play/game');
        }

        return Response::html($this->view->render('exams/play_join', [
            'pageTitle' => 'Join a live quiz',
            'pin' => (string) ($old['pin'] ?? $request->query('pin', '') ?? ''),
            'nickname' => (string) ($old['nickname'] ?? ''),
            'signedIn' => RequestContext::optionalUser($request) !== null,
            'needsName' => (bool) ($old['needs_name'] ?? false),
            'errors' => $errors,
            'focusClass' => 'focus-play',
            'exitHref' => '/',
            'exitLabel' => 'Home',
        ], 'layouts/focus'), $status);
    }

    public function enter(Request $request): Response
    {
        $tenant = RequestContext::tenant($request);
        $who = RequestContext::optionalUser($request);
        $pin = (string) preg_replace('/\D/', '', (string) ($request->input('pin', '') ?? ''));
        $nickname = (string) ($request->input('nickname', '') ?? '');
        $old = ['pin' => $pin, 'nickname' => $nickname];
        if (!$this->limiter->attempt('live-join:' . $tenant->id . ':' . $request->clientIp, 30, 60)) {
            return $this->join($request, ['pin' => 'Too many tries. Wait a minute, then check the PIN on the screen.'], $old, 429);
        }
        $game = strlen($pin) === 6 ? $this->live->findOpenByPin($tenant->id, $pin) : null;
        if ($game === null) {
            return $this->join($request, ['pin' => 'No game is open with that PIN. Check the six digits on the class screen.'], $old, 422);
        }
        if ($who === null && !$game->funNames && trim($nickname) === '' && $game->allowGuests) {
            // The first press asks for a name; pressing on with the name empty is the mistake.
            $named = $request->input('step') === 'name';

            return $this->join($request, $named ? ['nickname' => 'Choose a name for the class screen.'] : [], $old + ['needs_name' => true], $named ? 422 : 200);
        }
        try {
            $playerId = $this->game->join($tenant, $game, $who, $nickname);
        } catch (ValidationException $e) {
            return $this->join($request, $e->errors, $old + ['needs_name' => $who === null && isset($e->errors['nickname'])], 422);
        }
        $this->session->set(self::KEY, ['tenant' => $tenant->id, 'game' => $game->id, 'player' => $playerId]);

        return Response::redirect('/play/game');
    }

    public function screen(Request $request): Response
    {
        $current = $this->current($request);
        if ($current === null) {
            return Response::redirect('/play');
        }
        [$game, $player] = $current;

        return Response::html($this->view->render('exams/play_screen', [
            'pageTitle' => $game->title,
            'game' => $game,
            'player' => $player,
            'focusClass' => 'focus-play',
            'focusScript' => 'live.js',
            'exitHref' => '/play/leave',
            'exitLabel' => 'Leave',
        ], 'layouts/focus'))->withHeader('Cache-Control', 'no-store');
    }

    public function state(Request $request): Response
    {
        $current = $this->current($request);
        if ($current === null) {
            return Response::json(['status' => 'gone'], 404);
        }
        [$game, $player] = $current;

        return Response::json($this->game->playerState(RequestContext::tenant($request), $game, $player, (int) ($request->query('v', '0') ?? '0')))->withHeader('Cache-Control', 'no-store');
    }

    public function answer(Request $request): Response
    {
        $current = $this->current($request);
        if ($current === null) {
            return Response::json(['ok' => false, 'error' => 'gone'], 404);
        }
        [$game, $player] = $current;
        $result = $this->game->answer(
            RequestContext::tenant($request),
            $game->id,
            $player->id,
            (int) ($request->input('position', '-1') ?? '-1'),
            (int) ($request->input('choice', '-1') ?? '-1'),
        );

        return Response::json($result);
    }

    public function leave(Request $request): Response
    {
        $this->session->remove(self::KEY);

        return Response::redirect('/play');
    }

    /** @return ?array{0: LiveSession, 1: LivePlayer} this browser's game and player */
    private function current(Request $request): ?array
    {
        $tenant = RequestContext::tenant($request);
        $saved = $this->session->get(self::KEY);
        if (!is_array($saved) || (int) ($saved['tenant'] ?? 0) !== $tenant->id) {
            return null;
        }
        $game = $this->live->findSession($tenant->id, (int) ($saved['game'] ?? 0));
        $player = $game === null ? null : $this->live->findPlayer($tenant->id, $game->id, (int) ($saved['player'] ?? 0));
        $who = RequestContext::optionalUser($request);
        if ($game === null || $player === null || ($player->userId !== null && $player->userId !== $who?->user->id)) {
            return null;
        }

        return [$game, $player];
    }
}
