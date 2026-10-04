<?php

namespace Base\Video\Controller\Client;

use Base\Entity\User;
use Base\Service\TrashManager;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use Base\Video\Form\ChannelDetailsType;
use Base\Video\Form\LinkDetailsType;
use Base\Video\Form\VideoDetailsType;
use Base\Video\Model\ChannelDetails;
use Base\Video\Model\LinkDetails;
use Base\Video\Model\VideoDetails;
use Base\Video\Repository\ChannelRepository;
use Base\Video\Repository\SubscriptionRepository;
use Base\Video\Repository\VideoCommentRepository;
use Base\Video\Repository\VideoRepository;
use Base\Video\Repository\WatchEventRepository;
use Base\Video\Security\VideoVoter;
use Base\Video\Service\Channels;
use Base\Video\Service\Comments;
use Base\Video\Service\Editor;
use Base\Video\Service\Pictures;
use Base\Video\Service\Processing;
use Base\Video\Service\Uploads;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A member's studio: pages of the site (not a second back office) where
 * they send films, fill them in, choose who sees them and when, read their
 * figures, look after their comments, their channel, their trash.
 */
#[IsGranted('ROLE_USER')]
#[Route('/studio')]
class StudioController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoRepository $videos,
        private readonly Uploads $uploads,
        private readonly Channels $channels,
        #[Autowire('%video.upload.endpoint%')] private readonly string $endpoint = '/files/',
        #[Autowire('%video.upload.max_size%')] private readonly int $maxSize = 0,
        #[Autowire('%video.upload.quota%')] private readonly int $quota = 0,
    ) {
    }

    #[Route('', name: 'video_studio', methods: ['GET'])]
    public function index(Request $request, VideoCommentRepository $comments): Response
    {
        $user = $this->member();
        $visibility = \in_array($request->query->get('visibilite'), Video::VISIBILITIES, true) ? (string) $request->query->get('visibilite') : null;
        $videos = $this->videos->findForStudio($user, $visibility);
        $counts = [];
        foreach ($videos as $video) {
            $counts[$video->getId()] = $comments->countVisible($video);
        }

        return $this->render('@Video/studio/index.html.twig', [
            'videos' => $videos,
            'comments' => $counts,
            'visibility' => $visibility,
            'channel' => $this->channels->forMember($user, false),
            'used' => $this->videos->sumUploads($user),
            'quota' => $this->quota,
            'upload' => $this->uploads->isEnabled(),
        ]);
    }

    #[Route('/envoyer', name: 'video_studio_upload', methods: ['GET'])]
    public function upload(): Response
    {
        $user = $this->member();
        if (!$this->uploads->isEnabled()) {
            throw new NotFoundHttpException('Uploads are off on this site (video.upload.enabled).');
        }

        return $this->render('@Video/studio/upload.html.twig', [
            'refusal' => $this->uploads->refusal($user, 0),
            'token' => $this->uploads->token($user),
            'endpoint' => $this->endpoint,
            'max_size' => $this->maxSize,
            'remaining' => $this->uploads->remaining($user),
            'replace' => null,
        ]);
    }

    /** Where the uploader goes once a file is whole: the film's page in the studio, as soon as the hook made it. */
    #[Route('/envoi/{uploadId}', name: 'video_studio_uploaded', requirements: ['uploadId' => '[A-Za-z0-9_+-]+'], methods: ['GET'])]
    public function uploaded(string $uploadId): Response
    {
        $video = $this->videos->findOneByUploadId($uploadId);
        if ($video && $this->isGranted(VideoVoter::EDIT, $video)) {
            return $this->redirectToRoute('video_studio_edit', ['id' => $video->getId()]);
        }

        return $this->render('@Video/studio/waiting.html.twig', ['upload_id' => $uploadId]);
    }

    #[Route('/lien', name: 'video_studio_link', methods: ['GET', 'POST'])]
    public function link(Request $request, Editor $editor): Response
    {
        $user = $this->member();
        $details = new LinkDetails();
        $form = $this->createForm(LinkDetailsType::class, $details);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $video = $editor->link($details, $user);
            if ($video) {
                return $this->redirectToRoute('video_studio_edit', ['id' => $video->getId()]);
            }
            $this->addFlash('warning', '@video.studio.link.unknown');
        }

        return $this->render('@Video/studio/link.html.twig', ['form' => $form->createView()], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/video/{id}', name: 'video_studio_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id, Editor $editor, Processing $processing): Response
    {
        $video = $this->own($id);
        $details = VideoDetails::of($video);
        $form = $this->createForm(VideoDetailsType::class, $details);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $editor->apply($details, $video);
            $this->addFlash('success', '@video.studio.saved');

            return $this->redirectToRoute('video_studio_edit', ['id' => $video->getId()]);
        }

        return $this->render('@Video/studio/edit.html.twig', [
            'video' => $video,
            'form' => $form->createView(),
            'frames' => $processing->frames($video),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/video/{id}/etat', name: 'video_studio_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(int $id, TranslatorInterface $translator): JsonResponse
    {
        $video = $this->own($id);

        return new JsonResponse([
            'processing' => $video->getProcessing(),
            'label' => $translator->trans('processing.'.$video->getProcessing(), [], 'video'),
            'duration' => $video->getDuration(),
            'poster' => $video->getPoster(),
            'failure' => $video->getFailure(),
        ]);
    }

    #[Route('/video/{id}/statistiques', name: 'video_studio_stats', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function stats(int $id, WatchEventRepository $events): Response
    {
        $video = $this->own($id);

        return $this->render('@Video/studio/stats.html.twig', ['video' => $video, 'stats' => $events->stats($video)]);
    }

    #[Route('/video/{id}/commentaires', name: 'video_studio_comments', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function comments(Request $request, int $id, VideoCommentRepository $comments, Comments $service): Response
    {
        $video = $this->own($id);
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('video', (string) $request->request->get('_token'))) {
            if ($request->request->has('toggle')) {
                $video->setCommentsEnabled(!$video->areCommentsEnabled());
                $this->entityManager->flush();
            } elseif (($comment = $comments->find($request->request->getInt('comment'))) instanceof VideoComment && $comment->getThread()?->getId() === $video->getId()) {
                match ((string) $request->request->get('action')) {
                    'pin' => $service->pin($comment, true),
                    'unpin' => $service->pin($comment, false),
                    'hide' => $service->hide($comment, true),
                    'show' => $service->hide($comment, false),
                    default => null,
                };
            }

            return $this->redirectToRoute('video_studio_comments', ['id' => $id]);
        }

        return $this->render('@Video/studio/comments.html.twig', ['video' => $video, 'comments' => $comments->findForVideo($video)]);
    }

    #[Route('/video/{id}/remplacer', name: 'video_studio_replace', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function replace(int $id): Response
    {
        $user = $this->member();
        $video = $this->own($id);
        if (!$this->uploads->isEnabled() || $video->isEmbedded() || $video->isRemote()) {
            throw new NotFoundHttpException('This film\'s file cannot be replaced here.');
        }

        return $this->render('@Video/studio/upload.html.twig', [
            'refusal' => $this->uploads->refusal($user, 0),
            'token' => $this->uploads->token($user, $video),
            'endpoint' => $this->endpoint,
            'max_size' => $this->maxSize,
            'remaining' => $this->uploads->remaining($user),
            'replace' => $video,
        ]);
    }

    /** To the trash (omnibase's TrashBall): back from the studio's trash within the delay, gone after. */
    #[Route('/video/{id}/supprimer', name: 'video_studio_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $video = $this->own($id);
        if ($this->isCsrfTokenValid('video', (string) $request->request->get('_token'))) {
            $this->entityManager->remove($video);
            $this->entityManager->flush();
            $this->addFlash('success', '@video.studio.trashed');
        }

        return $this->redirectToRoute('video_studio');
    }

    #[Route('/corbeille', name: 'video_studio_trash', methods: ['GET', 'POST'])]
    public function trash(Request $request, TrashManager $trash): Response
    {
        $user = $this->member();
        $mine = [];
        foreach ($trash->contents(Video::class) as $ball) {
            $video = $trash->entity($ball);
            if ($video instanceof Video && $video->isOwnedBy($user)) {
                $mine[$ball->getId()] = ['ball' => $ball, 'video' => $video];
            }
        }
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('video', (string) $request->request->get('_token')) && isset($mine[$request->request->getInt('ball')])) {
            $trash->restore($mine[$request->request->getInt('ball')]['ball']);
            $this->addFlash('success', '@video.studio.restored');

            return $this->redirectToRoute('video_studio_trash');
        }

        return $this->render('@Video/studio/trash.html.twig', ['items' => array_values($mine)]);
    }

    #[Route('/chaine', name: 'video_studio_channel', methods: ['GET', 'POST'])]
    public function channel(Request $request, ChannelRepository $channels, Pictures $pictures): Response
    {
        $user = $this->member();
        $channel = $this->channels->forMember($user);
        if (!$channel->getId()) {
            $this->entityManager->flush();
        }
        $details = ChannelDetails::of($channel);
        $form = $this->createForm(ChannelDetailsType::class, $details);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $taken = $channels->findOneBy(['slug' => $details->slug]);
            if ($taken && $taken !== $channel) {
                $this->addFlash('warning', '@video.studio.channel.slug_taken');
            } else {
                $channel->setName($details->name)->setSlug($details->slug)->setDescription($details->description)->setShelves($details->shelves);
                if ($details->avatarFile) {
                    $channel->setAvatar($pictures->channel($channel, $details->avatarFile, 'avatar'));
                }
                if ($details->bannerFile) {
                    $channel->setBanner($pictures->channel($channel, $details->bannerFile, 'banner'));
                }
                $this->entityManager->flush();
                $this->addFlash('success', '@video.studio.saved');

                return $this->redirectToRoute('video_studio_channel');
            }
        }

        return $this->render('@Video/studio/channel.html.twig', ['channel' => $channel, 'form' => $form->createView()], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/abonnes', name: 'video_studio_subscribers', methods: ['GET'])]
    public function subscribers(SubscriptionRepository $subscriptions): Response
    {
        $channel = $this->channels->forMember($this->member(), false);

        return $this->render('@Video/studio/subscribers.html.twig', ['channel' => $channel, 'subscriptions' => $channel ? $subscriptions->findSubscribers($channel) : []]);
    }

    private function member(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function own(int $id): Video
    {
        $video = $this->videos->find($id);
        if (!$video instanceof Video || !$this->isGranted(VideoVoter::EDIT, $video)) {
            throw new NotFoundHttpException('No such video in your studio.');
        }

        return $video;
    }
}
