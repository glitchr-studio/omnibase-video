<?php

namespace Base\Video\Controller\Client;

use Base\Entity\User;
use Base\Video\Entity\Channel;
use Base\Video\Repository\ChannelRepository;
use Base\Video\Repository\PlaylistRepository;
use Base\Video\Repository\VideoRepository;
use Base\Video\Service\Channels;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** A channel's page - its shelves, its videos, its lists, what it says - and following it. */
class ChannelController extends AbstractController
{
    public const PER_PAGE = 24;

    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly VideoRepository $videos,
        private readonly Channels $subscriptions,
    ) {
    }

    #[Route('/chaine/{slug}', name: 'video_channel', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(Request $request, string $slug, PlaylistRepository $playlists): Response
    {
        $channel = $this->find($slug);
        $tab = \in_array($request->query->get('onglet'), ['videos', 'listes', 'a-propos'], true) ? (string) $request->query->get('onglet') : 'accueil';
        $page = max(1, $request->query->getInt('page', 1));
        $user = $this->getUser();

        return $this->render('@Video/client/channel.html.twig', [
            'channel' => $channel,
            'tab' => $tab,
            'page' => $page,
            'latest' => $this->videos->findLatest('videos' === $tab ? self::PER_PAGE : 12, 'videos' === $tab ? ($page - 1) * self::PER_PAGE : 0, $channel),
            'popular' => 'accueil' === $tab ? $this->videos->findPopular(8, $channel) : [],
            'playlists' => \in_array($tab, ['accueil', 'listes'], true) ? $playlists->findPublicOf($channel->getOwners()) : [],
            'subscribed' => $user instanceof User && $this->subscriptions->isSubscribed($user, $channel),
            'own' => $user instanceof User && $channel->isOwnedBy($user),
        ]);
    }

    #[Route('/chaine/{slug}/abonnement', name: 'video_subscribe', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function subscribe(Request $request, string $slug): Response
    {
        $channel = $this->find($slug);
        $user = $this->getUser();
        $json = str_contains((string) $request->headers->get('Accept'), 'json');
        if (!$user instanceof User) {
            return $json ? new JsonResponse(['error' => 'login'], Response::HTTP_UNAUTHORIZED) : $this->redirectToRoute('security_login');
        }
        if (!$this->isCsrfTokenValid('video', (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-Token')))) {
            return $json ? new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN) : $this->redirectToRoute('video_channel', ['slug' => $slug]);
        }
        $subscribed = $this->subscriptions->toggle($user, $channel);
        if ($json) {
            return new JsonResponse(['subscribed' => $subscribed, 'subscribers' => $channel->getSubscribers()]);
        }
        $back = (string) $request->request->get('back', '');

        return $this->redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : $this->generateUrl('video_channel', ['slug' => $slug]));
    }

    private function find(string $slug): Channel
    {
        return $this->channels->findOneBy(['slug' => $slug]) ?? throw new NotFoundHttpException('No such channel.');
    }
}
