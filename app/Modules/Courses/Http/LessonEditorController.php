<?php

declare(strict_types=1);

namespace App\Modules\Courses\Http;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Core\View\View;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseAuthoring;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Files\FileRepository;
use App\Modules\Files\FileService;

/** Educator workspace at lesson level: edit, reorder, delete, questions, preview. */
final class LessonEditorController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly AssessmentRepository $assessments,
        private readonly CourseAuthoring $authoring,
        private readonly FileService $fileService,
        private readonly FileRepository $files,
        private readonly ?\App\Modules\Courses\LessonMedia $media = null,
        private readonly ?\App\Modules\Learning\LearningService $learning = null,
    ) {
    }

    /** POST …/media: the lesson's video or recording (ADR 0029). */
    public function uploadMedia(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $upload = $request->file('media');
        try {
            if ($upload === null) {
                throw ValidationException::single('media', 'Choose a file first.');
            }
            $file = $this->mediaService()->upload($course, RequestContext::user($request), $lesson->id, $upload);
            $this->session->flash('success', sprintf('“%s” is the lesson’s %s now (%s).', $file->originalName, $lesson->isVideo() ? 'video' : 'recording', $file->humanSize()));
        } catch (ValidationException $e) {
            return $this->render($course, $lesson, $e->errors, [], 422);
        }

        return Response::redirect($this->editUrl($course, $lesson) . '#lesson-media');
    }

    /** POST …/media/remove */
    public function removeMedia(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $this->mediaService()->remove($course, RequestContext::user($request), $lesson->id);
        $this->session->flash('success', $lesson->isVideo() ? 'Video removed.' : 'Recording removed.');

        return Response::redirect($this->editUrl($course, $lesson) . '#lesson-media');
    }

    private function mediaService(): \App\Modules\Courses\LessonMedia
    {
        return $this->media ?? throw new \LogicException('Lesson media is not set up.');
    }

    public function uploadFile(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $upload = $request->file('file');
        try {
            if ($upload === null) {
                throw ValidationException::single('file', 'Choose a file first.');
            }
            $file = $this->fileService->attachToLesson($course, RequestContext::user($request), $lesson->id, $upload);
            $this->session->flash('success', sprintf('“%s” added (%s).', $file->originalName, $file->humanSize()));
        } catch (ValidationException $e) {
            return $this->render($course, $lesson, $e->errors, [], 422);
        }

        return Response::redirect($this->editUrl($course, $lesson) . '#files');
    }

    public function removeFile(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $file = $this->fileService->removeFromLesson($course, RequestContext::user($request), $lesson->id, $request->routeInt('file'));
        $this->session->flash('success', sprintf('“%s” removed.', $file->originalName));

        return Response::redirect($this->editUrl($course, $lesson) . '#files');
    }

    public function edit(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);

        return $this->render($course, $lesson);
    }

    public function update(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $input = [
            'title' => $request->input('lesson_title', '') ?? '',
            'body' => $request->input('body', '') ?? '',
            'media_url' => $request->input('media_url', '') ?? '',
            'pass_threshold' => $request->input('pass_threshold', '70') ?? '70',
            'max_attempts' => $request->input('max_attempts', '') ?? '',
            'preview' => $request->input('preview', '0') === '1' ? '1' : '0',
        ];
        // The form always sends "required_shown"; then an unticked box means "not required".
        if ($request->input('required_shown') === '1') {
            $input['required'] = $request->input('required', '0') === '1' ? '1' : '0';
        }
        try {
            $this->authoring->updateLesson($course, RequestContext::user($request), $lesson->id, $input);
        } catch (ValidationException $e) {
            return $this->render($course, $lesson, $e->errors, [
                'lesson_title' => $input['title'],
                'body' => $input['body'],
                'media_url' => $input['media_url'],
                'pass_threshold' => $input['pass_threshold'],
                'max_attempts' => $input['max_attempts'],
                'preview' => $input['preview'],
                'required' => $input['required'] ?? ($lesson->mandatory ? '1' : '0'),
            ], 422);
        }
        $finished = 0;
        if ($lesson->mandatory && ($input['required'] ?? '1') === '0') {
            $finished = $this->learning?->recheckCourse($course) ?? 0;
        }
        $this->session->flash('success', 'Lesson saved.' . ($finished > 0 ? ' ' . $finished . ($finished === 1 ? ' learner has' : ' learners have') . ' now finished the course.' : ''));

        return Response::redirect($this->editUrl($course, $lesson));
    }

    public function move(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $this->authoring->moveLesson($course, RequestContext::user($request), $lesson->id, $request->input('direction', '') ?? '');

        return Response::redirect('/teach/courses/' . $course->id . '#lesson-' . $lesson->id);
    }

    public function delete(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        try {
            $keys = $this->files->storageKeysForLesson($course->tenantId, $lesson->id);
            $this->authoring->deleteLesson($course, RequestContext::user($request), $lesson->id);
            $this->fileService->deleteBlobs($keys);
            $this->session->flash('success', sprintf('Lesson “%s” deleted.', $lesson->title));

            return Response::redirect('/teach/courses/' . $course->id);
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));

            return Response::redirect($this->editUrl($course, $lesson));
        }
    }

    public function addQuestion(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $options = array_values($request->inputArray('options'));
        try {
            $this->authoring->addQuestion(
                $course,
                RequestContext::user($request),
                $lesson->id,
                $request->input('prompt', '') ?? '',
                $options,
                $request->input('correct', '') ?? '',
                $request->input('explanation', '') ?? '',
            );
        } catch (ValidationException $e) {
            $old = [
                'prompt' => $request->input('prompt', '') ?? '',
                'correct' => $request->input('correct', '') ?? '',
                'explanation' => $request->input('explanation', '') ?? '',
            ];
            foreach ($options as $i => $label) {
                $old['option_' . $i] = $label;
            }

            return $this->render($course, $lesson, $e->errors, $old, 422);
        }
        $this->session->flash('success', 'Question added.');

        return Response::redirect($this->editUrl($course, $lesson) . '#questions');
    }

    public function updateExplanation(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        try {
            $this->authoring->updateExplanation($course, RequestContext::user($request), $lesson->id, $request->routeInt('question'), $request->input('explanation', '') ?? '');
            $this->session->flash('success', 'Explanation saved.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect($this->editUrl($course, $lesson) . '#question-' . $request->routeInt('question'));
    }

    public function deleteQuestion(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        try {
            $this->authoring->deleteQuestion($course, RequestContext::user($request), $lesson->id, $request->routeInt('question'));
            $this->session->flash('success', 'Question removed.');
        } catch (ValidationException $e) {
            $this->session->flash('error', implode(' ', $e->errors));
        }

        return Response::redirect($this->editUrl($course, $lesson) . '#questions');
    }

    /** Shows the lesson as learners see it, with correct answers marked. Never records progress. */
    public function preview(Request $request): Response
    {
        [$course, $lesson] = $this->load($request);
        $assessment = $lesson->isQuiz() ? $this->assessments->findForLesson($course->tenantId, $lesson->id) : null;

        return Response::html($this->view->render('teach/preview', [
            'pageTitle' => 'Preview: ' . $lesson->title,
            'course' => $course,
            'lesson' => $lesson,
            'assessment' => $assessment,
            'questions' => $assessment === null ? [] : $this->assessments->questions($course->tenantId, $assessment['id']),
            'files' => self::withoutMedia($this->files->forLesson($course->tenantId, $lesson->id), $lesson),
            'mediaFile' => $this->media?->fileFor($lesson),
        ]));
    }

    /**
     * The lesson's own video or recording is shown as the player, not again in the file list.
     *
     * @param list<\App\Modules\Files\StoredFile> $files
     * @return list<\App\Modules\Files\StoredFile>
     */
    public static function withoutMedia(array $files, Lesson $lesson): array
    {
        return array_values(array_filter($files, static fn ($f): bool => $f->id !== $lesson->mediaFileId));
    }

    /** @return array{0: Course, 1: Lesson} */
    private function load(Request $request): array
    {
        $tenant = RequestContext::tenant($request);
        $course = $this->courses->findById($tenant->id, $request->routeInt('course')) ?? throw HttpException::notFound();
        $lesson = $this->lessons->findInCourse($tenant->id, $course->id, $request->routeInt('lesson')) ?? throw HttpException::notFound();

        return [$course, $lesson];
    }

    private function editUrl(Course $course, Lesson $lesson): string
    {
        return sprintf('/teach/courses/%d/lessons/%d', $course->id, $lesson->id);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function render(Course $course, Lesson $lesson, array $errors = [], array $old = [], int $status = 200): Response
    {
        $assessment = $lesson->isQuiz() ? $this->assessments->findForLesson($course->tenantId, $lesson->id) : null;
        $attempts = $assessment === null ? 0 : $this->assessments->attemptCount($course->tenantId, $assessment['id']);

        return Response::html($this->view->render('teach/lesson', [
            'pageTitle' => 'Edit ' . $lesson->title,
            'course' => $course,
            'lesson' => $lesson,
            'assessment' => $assessment,
            'questions' => $assessment === null ? [] : $this->assessments->questions($course->tenantId, $assessment['id']),
            'attempts' => $attempts,
            'files' => self::withoutMedia($this->files->forLesson($course->tenantId, $lesson->id), $lesson),
            'mediaFile' => $this->media?->fileFor($lesson),
            'policy' => $this->fileService->policy(),
            'inUse' => $attempts > 0 || $this->lessons->hasLearnerActivity($course->tenantId, $lesson->id),
            'errors' => $errors,
            'old' => $old + [
                'lesson_title' => $lesson->title,
                'body' => $lesson->body,
                'media_url' => $lesson->mediaUrl ?? '',
                'pass_threshold' => (string) ($assessment['pass_threshold'] ?? 70),
                'max_attempts' => $assessment === null || $assessment['max_attempts'] === null ? '' : (string) $assessment['max_attempts'],
            ],
        ]), $status);
    }
}
