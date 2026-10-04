<?php

namespace Base\Video\Controller\Client;

use Base\Entity\User;
use Base\Video\Entity\Playlist;
use Base\Video\Entity\Video;
use Base\Video\Form\PlaylistDetailsType;
use Base\Video\Model\PlaylistDetails;
use Base\Video\Repository\PlaylistRepository;
use Base\Video\Repository\SubscriptionRepository;
use Base\Video\Repository\VideoRepository;
use Base\Video\Repository\WatchEventRepository;
use Base\Video\Security\VideoVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What a member keeps: the history, "plus tard", the lists, the
 * subscriptions' feed - and a list's own page, for anyone it is shown to.
 */
class LibraryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlaylistRepository $playlists,
        private readonly VideoRepository $videos,
    ) {
    }

    #[Route('/historique', name: 'video_history', methods: ['GET'])]
    public function history(WatchEventRepository $events): Response
    {
        $user = $this->member();

        return $this->render('@Video/client/history.html.twig', ['history' => $events->findHistory($user)]);
    }

    #[Route('/historique/effacer', name: 'video_history_clear', methods: ['POST'])]
    public function clearHistory(Request $request, WatchEventRepository $events): Response
    {
        $user = $this->member();
        if ($this->isCsrfTokenValid('video', (string) $request->request->get('_token'))) {
            if ($slug = $request->request->get('video')) {
                $video = $this->videos->findOneBy(['slug' => (string) $slug]);
                if ($video instanceof Video) {
                    $events->removeFromHistory($user, $video);
                }
            } else {
                $events->clearHistory($user);
                $this->addFlash('success', '@video.history.cleared');
            }
        }

        return $this->redirectToRoute('video_history');
    }

    #[Route('/plus-tard', name: 'video_later', methods: ['GET'])]
    public function later(): Response
    {
        $user = $this->member();

        return $this->render('@Video/client/playlist.html.twig', ['playlist' => $this->playlists->findLater($user, false), 'later' => true, 'form' => null]);
    }

    #[Route('/abonnements', name: 'video_subscriptions', methods: ['GET'])]
    public function subscriptions(SubscriptionRepository $subscriptions): Response
    {
        $user = $this->member();

        return $this->render('@Video/client/subscriptions.html.twig', [
            'channels' => $subscriptions->findChannels($user),
            'videos' => $this->videos->findFromSubscriptions($user),
        ]);
    }

    #[Route('/listes', name: 'video_playlists', methods: ['GET', 'POST'])]
    public function playlists(Request $request): Response
    {
        $user = $this->member();
        $details = new PlaylistDetails();
        $form = $this->createForm(PlaylistDetailsType::class, $details);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $playlist = (new Playlist($user, $details->title))->setDescription($details->description)->setVisibility($details->visibility);
            $this->entityManager->persist($playlist);
            if ($slug = $request->query->get('ajouter')) {
                $video = $this->videos->findOneBy(['slug' => (string) $slug]);
                if ($video instanceof Video && $this->isGranted(VideoVoter::VIEW, $video)) {
                    $playlist->add($video);
                }
            }
            $this->entityManager->flush();

            return $this->redirectToRoute('video_playlist', ['slug' => $playlist->getSlug()]);
        }

        return $this->render('@Video/client/playlists.html.twig', [
            'playlists' => $this->playlists->findOwnedBy($user),
            'later' => $this->playlists->findLater($user, false),
            'form' => $form->createView(),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/liste/{slug}', name: 'video_playlist', requirements: ['slug' => '[A-Za-z0-9_-]+'], methods: ['GET', 'POST'])]
    public function playlist(Request $request, string $slug): Response
    {
        $playlist = $this->playlists->findOneBy(['slug' => $slug]);
        if (!$playlist instanceof Playlist || !$this->isGranted(VideoVoter::VIEW, $playlist)) {
            throw new NotFoundHttpException('No such list.');
        }
        if ($playlist->isLater()) {
            return $this->redirectToRoute('video_later');
        }
        $form = null;
        if ($this->isGranted(VideoVoter::EDIT, $playlist)) {
            $details = PlaylistDetails::of($playlist);
            $form = $this->createForm(PlaylistDetailsType::class, $details);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $playlist->setTitle($details->title)->setDescription($details->description)->setVisibility($details->visibility);
                $this->entityManager->flush();
                $this->addFlash('success', '@video.playlist.saved');

                return $this->redirectToRoute('video_playlist', ['slug' => $slug]);
            }
        }

        return $this->render('@Video/client/playlist.html.twig', ['playlist' => $playlist, 'later' => false, 'form' => $form?->createView()]);
    }

    /** Add a film to a list (or "plus tard"), or take it out: the list's slug, or "later". */
    #[Route('/listes/video', name: 'video_playlist_toggle', methods: ['POST'])]
    public function toggle(Request $request): Response
    {
        $user = $this->member();
        $json = str_contains((string) $request->headers->get('Accept'), 'json');
        $video = $this->videos->findOneBy(['slug' => (string) $request->request->get('video')]);
        if (!$video instanceof Video || !$this->isGranted(VideoVoter::VIEW, $video) || !$this->isCsrfTokenValid('video', (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token')))) {
            return $json ? new JsonResponse(['error' => 'refused'], Response::HTTP_FORBIDDEN) : $this->redirectToRoute('video_playlists');
        }
        $target = (string) $request->request->get('playlist', 'later');
        $playlist = 'later' === $target ? $this->playlists->findLater($user) : $this->playlists->findOneBy(['slug' => $target]);
        if (!$playlist instanceof Playlist || !$playlist->isOwnedBy($user)) {
            return $json ? new JsonResponse(['error' => 'list'], Response::HTTP_NOT_FOUND) : $this->redirectToRoute('video_playlists');
        }
        $in = $playlist->has($video);
        $in ? $playlist->remove($video) : $playlist->add($video);
        $this->entityManager->flush();
        if ($json) {
            return new JsonResponse(['in' => !$in, 'playlist' => $playlist->getSlug(), 'count' => $playlist->getItems()->count()]);
        }
        $this->addFlash('success', !$in ? '@video.playlist.added' : '@video.playlist.removed');
        $back = (string) $request->request->get('back', '');

        return $this->redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : $this->generateUrl('video_watch', ['slug' => $video->getSlug()]));
    }

    #[Route('/liste/{slug}/supprimer', name: 'video_playlist_delete', requirements: ['slug' => '[A-Za-z0-9_-]+'], methods: ['POST'])]
    public function delete(Request $request, string $slug): Response
    {
        $playlist = $this->playlists->findOneBy(['slug' => $slug]);
        if ($playlist instanceof Playlist && !$playlist->isLater() && $playlist->isOwnedBy($this->member()) && $this->isCsrfTokenValid('video', (string) $request->request->get('_token'))) {
            $this->entityManager->remove($playlist);
            $this->entityManager->flush();
            $this->addFlash('success', '@video.playlist.deleted');
        }

        return $this->redirectToRoute('video_playlists');
    }

    #[Route('/liste/{slug}/ordre', name: 'video_playlist_order', requirements: ['slug' => '[A-Za-z0-9_-]+'], methods: ['POST'])]
    public function order(Request $request, string $slug): Response
    {
        $playlist = $this->playlists->findOneBy(['slug' => $slug]);
        if (!$playlist instanceof Playlist || !$playlist->isOwnedBy($this->member()) || !$this->isCsrfTokenValid('video', (string) $request->request->get('_token'))) {
            throw new NotFoundHttpException('No such list.');
        }
        // "Monter" / "descendre" buttons: one video one step.
        $id = $request->request->getInt('move');
        $step = 'up' === $request->request->get('direction') ? -1 : 1;
        $ids = array_map(fn ($item) => $item->getVideo()->getId(), $playlist->getItems()->toArray());
        $at = array_search($id, $ids, true);
        if (false !== $at && isset($ids[$at + $step])) {
            [$ids[$at], $ids[$at + $step]] = [$ids[$at + $step], $ids[$at]];
            $playlist->reorder($ids);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute($playlist->isLater() ? 'video_later' : 'video_playlist', $playlist->isLater() ? [] : ['slug' => $slug]);
    }

    private function member(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('A member\'s page.');
        }

        return $user;
    }
}
