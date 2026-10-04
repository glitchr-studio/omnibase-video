<?php

namespace Base\Video\Controller\Client;

use Base\Entity\User;
use Base\Form\Model\CommentModel;
use Base\Form\Type\CommentType;
use Base\Service\CommentGuard;
use Base\Video\Entity\Source;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use Base\Video\Exception\RemoteException;
use Base\Video\Repository\PlaylistRepository;
use Base\Video\Repository\VideoCommentRepository;
use Base\Video\Repository\VideoRepository;
use Base\Video\Repository\WatchEventRepository;
use Base\Video\Security\VideoVoter;
use Base\Video\Service\Channels;
use Base\Video\Service\Comments;
use Base\Video\Service\Moderation;
use Base\Video\Service\Ratings;
use Base\Video\Service\RemoteManifest;
use Base\Video\Service\Views;
use Base\Video\Suggest\SuggestInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A film's page and what it answers: the manifest of a remote film, the
 * player's beacons, the ratings, the comments, the reports.
 */
class WatchController extends AbstractController
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly VideoCommentRepository $comments,
        private readonly SuggestInterface $suggest,
        private readonly Ratings $ratings,
        private readonly Views $views,
        private readonly Channels $channels,
        private readonly FormFactoryInterface $forms,
        #[Autowire('%video.comments.guests%')] private readonly bool $guests = false,
    ) {
    }

    #[Route('/v/{slug}', name: 'video_watch', requirements: ['slug' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function watch(Request $request, string $slug, WatchEventRepository $events, PlaylistRepository $playlists): Response
    {
        $video = $this->find($slug);
        $user = $this->member();
        $list = $request->query->get('list') ? $playlists->findOneBy(['slug' => (string) $request->query->get('list')]) : null;
        if ($list && !$list->isReachableBy($user)) {
            $list = null;
        }

        return $this->render('@Video/client/watch.html.twig', [
            'video' => $video,
            'channel' => $video->getChannel(),
            'subscribed' => $video->getChannel() ? $this->channels->isSubscribed($user, $video->getChannel()) : false,
            'comments' => $this->comments->findVisible($video),
            'comment_count' => $this->comments->countVisible($video),
            'form' => $this->commentForm($user)?->createView(),
            'next' => $this->suggest->similar($video, 12),
            'list' => $list,
            'list_next' => $list?->next($video),
            'resume' => $user ? $events->findResume($user, $video) : null,
            'source' => self::source($request),
            'playlists' => $user ? $playlists->findOwnedBy($user) : [],
            'later' => $user ? $playlists->findLater($user, false) : null,
            'reasons' => Moderation::REASONS,
            'can_comment' => $video->areCommentsEnabled() && ($user || $this->guests),
        ]);
    }

    /**
     * A remote film's master playlist, from our address (its origin serves
     * it without CORS): read on the server, kept a while, URIs absolute -
     * the browser takes the variants and the segments from the origin.
     */
    #[Route('/v/{slug}/master.m3u8', name: 'video_manifest', requirements: ['slug' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function manifest(string $slug, RemoteManifest $remote): Response
    {
        $video = $this->find($slug);
        $source = $video->getPlayableSource();
        if (!$source || Source::REMOTE_HLS !== $source->getKind()) {
            throw new NotFoundHttpException('This film is not a remote one.');
        }
        try {
            $playlist = $remote->master($source->getUrl());
        } catch (RemoteException $e) {
            return new Response('#EXTM3U'."\n# ".$e->getMessage()."\n", Response::HTTP_BAD_GATEWAY, ['Content-Type' => 'application/vnd.apple.mpegurl']);
        }

        $response = new Response($playlist, Response::HTTP_OK, ['Content-Type' => 'application/vnd.apple.mpegurl']);
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }

    /** The player's beacon: start, progress, end (Service\Views). */
    #[Route('/v/{slug}/watch', name: 'video_beacon', requirements: ['slug' => '[A-Za-z0-9_-]+'], methods: ['POST'])]
    public function beacon(Request $request, string $slug): Response
    {
        $video = $this->videos->findOneBy(['slug' => $slug]);
        $user = $this->member();
        if (!$video instanceof Video || !$this->isGranted(VideoVoter::VIEW, $video)) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }
        $data = json_decode($request->getContent() ?: '{}', true);
        if (\is_array($data)) {
            $this->views->record($video, $data, $this->views->visitor($request, $user), $user);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/v/{slug}/rate', name: 'video_rate', requirements: ['slug' => '[A-Za-z0-9_-]+'], methods: ['POST'])]
    public function rate(Request $request, string $slug, #[Autowire(service: 'limiter.video_rate')] RateLimiterFactoryInterface $limiter): Response
    {
        $video = $this->find($slug);
        $user = $this->member();
        if (!$user) {
            return $this->answer($request, ['error' => 'login'], Response::HTTP_UNAUTHORIZED, $video);
        }
        if (!$this->isCsrfTokenValid('video', (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token')))) {
            return $this->answer($request, ['error' => 'csrf'], Response::HTTP_FORBIDDEN, $video);
        }
        if (!$limiter->create('user-'.$user->getId())->consume()->isAccepted()) {
            return $this->answer($request, ['error' => 'limit'], Response::HTTP_TOO_MANY_REQUESTS, $video);
        }
        $rating = (string) $request->request->get('rating', 'up');
        $mine = $this->ratings->rate($user, $video, \in_array($rating, ['up', 'down', 'none'], true) ? $rating : 'up');

        return $this->answer($request, $this->ratings->count($video) + ['mine' => $mine], Response::HTTP_OK, $video);
    }

    #[Route('/v/{slug}/comment', name: 'video_comment', requirements: ['slug' => '[A-Za-z0-9_-]+'], methods: ['POST'])]
    public function comment(Request $request, string $slug, Comments $comments, CommentGuard $guard, #[Autowire(service: 'limiter.video_comment')] RateLimiterFactoryInterface $limiter): Response
    {
        $video = $this->find($slug);
        $user = $this->member();
        $back = $this->generateUrl('video_watch', ['slug' => $video->getSlug()]).'#comments';
        if (!$video->areCommentsEnabled() || (!$user && !$this->guests)) {
            $this->addFlash('warning', '@video.comment.closed');

            return $this->redirect($back);
        }

        $model = CommentModel::forUser($user);
        $form = $this->commentForm($user, $model);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->redirect($back);
        }
        $why = $guard->check($form, $request);
        if (CommentGuard::TRAPPED === $why) {
            // A robot: thanked all the same, nothing kept.
            return $this->redirect($back);
        }
        if (null !== $why) {
            $this->addFlash('warning', '@video.comment.'.$why);

            return $this->redirect($back);
        }
        if (!$limiter->create($user ? 'user-'.$user->getId() : 'ip-'.$request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('warning', '@video.comment.limit');

            return $this->redirect($back);
        }
        if (!$form->isValid()) {
            $this->addFlash('warning', '@video.comment.invalid');

            return $this->redirect($back);
        }

        $moment = $request->request->get('moment');
        $parent = $form->get('parent')->getData();
        $comment = $comments->create($video, $model, $user, $request, is_numeric($moment) ? (int) $moment : null, is_numeric($parent) ? (int) $parent : null);
        $this->addFlash($comment->isVisible() ? 'success' : 'info', $comment->isVisible() ? '@video.comment.posted' : '@video.comment.pending');

        return $this->redirect($this->generateUrl('video_watch', ['slug' => $video->getSlug()]).'#comment-'.$comment->getId());
    }

    #[Route('/v/{slug}/report', name: 'video_report', requirements: ['slug' => '[A-Za-z0-9_-]+'], methods: ['POST'])]
    public function report(Request $request, string $slug, Moderation $moderation, #[Autowire(service: 'limiter.video_report')] RateLimiterFactoryInterface $limiter): Response
    {
        $video = $this->find($slug);
        $user = $this->member();
        $back = $this->generateUrl('video_watch', ['slug' => $video->getSlug()]);
        if (!$this->isCsrfTokenValid('video', (string) $request->request->get('_token'))) {
            return $this->redirect($back);
        }
        if (!$limiter->create($user ? 'user-'.$user->getId() : 'ip-'.$request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('warning', '@video.report.limit');

            return $this->redirect($back);
        }
        $subject = $video;
        if ($commentId = $request->request->getInt('comment')) {
            $comment = $this->comments->find($commentId);
            if ($comment instanceof VideoComment && $comment->getThread()?->getId() === $video->getId()) {
                $subject = $comment;
            }
        }
        $moderation->report($subject, $user, (string) $request->request->get('reason', 'other'), mb_substr((string) $request->request->get('text', ''), 0, 2000));
        $this->addFlash('success', '@video.report.sent');

        return $this->redirect($back);
    }

    /** Where the visitor came from, for the studio's statistics: the page before, by its address. */
    public static function source(Request $request): string
    {
        if ($from = $request->query->get('from')) {
            return (string) $from;
        }
        $referer = (string) $request->headers->get('referer', '');
        if ('' === $referer) {
            return 'direct';
        }
        $host = parse_url($referer, \PHP_URL_HOST);
        if ($host && $host !== $request->getHost()) {
            return 'external';
        }
        $path = (string) parse_url($referer, \PHP_URL_PATH);

        return match (true) {
            '/' === $path || '' === $path => 'home',
            str_starts_with($path, '/recherche') => 'search',
            str_starts_with($path, '/chaine/') => 'channel',
            str_starts_with($path, '/liste') || str_starts_with($path, '/plus-tard') => 'playlist',
            str_starts_with($path, '/abonnements') => 'subscriptions',
            str_starts_with($path, '/historique') => 'history',
            str_starts_with($path, '/v/') => 'suggest',
            default => 'direct',
        };
    }

    private function find(string $slug): Video
    {
        $video = $this->videos->findOneBy(['slug' => $slug]);
        if (!$video instanceof Video || !$this->isGranted(VideoVoter::VIEW, $video)) {
            throw new NotFoundHttpException('No such video.');
        }

        return $video;
    }

    private function member(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function commentForm(?User $user, ?CommentModel $model = null): ?FormInterface
    {
        if (!$user && !$this->guests) {
            return null;
        }

        return $this->forms->createNamed('comment', CommentType::class, $model ?? CommentModel::forUser($user), [
            'signed_in' => null !== $user,
            'placeholders' => ['content' => '@video.comment.placeholder'],
        ]);
    }

    /** JSON for the page's script, a redirect back for a plain form. */
    private function answer(Request $request, array $data, int $status, Video $video): Response
    {
        if (str_contains((string) $request->headers->get('Accept'), 'json') || $request->isXmlHttpRequest()) {
            return new JsonResponse($data, $status);
        }

        return $this->redirectToRoute('video_watch', ['slug' => $video->getSlug()]);
    }
}
