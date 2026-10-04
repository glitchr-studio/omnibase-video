<?php

namespace Base\Video\Controller\Client;

use Base\Video\Repository\ChannelRepository;
use Base\Video\Search\VideoSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The search: one bar, the platform's films (Typesense, the database when it is down), its filters. */
class SearchController extends AbstractController
{
    /** @param list<string> $categories */
    public function __construct(
        private readonly VideoSearch $search,
        #[Autowire('%video.categories%')] private readonly array $categories = [],
    ) {
    }

    #[Route('/recherche', name: 'video_search', methods: ['GET'])]
    public function search(Request $request, ChannelRepository $channels): Response
    {
        $q = mb_substr(trim((string) $request->query->get('q', '')), 0, 120);
        $filters = [
            'category' => \in_array($request->query->get('categorie'), $this->categories, true) ? (string) $request->query->get('categorie') : null,
            'channel' => preg_match('/^[a-z0-9-]+$/', (string) $request->query->get('chaine')) ? (string) $request->query->get('chaine') : null,
            'duration' => \in_array($request->query->get('duree'), ['short', 'medium', 'long'], true) ? (string) $request->query->get('duree') : null,
            'date' => \in_array($request->query->get('date'), ['day', 'week', 'month', 'year'], true) ? (string) $request->query->get('date') : null,
            'sort' => \in_array($request->query->get('tri'), ['date', 'views'], true) ? (string) $request->query->get('tri') : null,
        ];
        $page = max(1, $request->query->getInt('page', 1));
        $result = '' !== $q || array_filter($filters) ? $this->search->search($q, $filters, $page) : ['videos' => [], 'found' => 0, 'engine' => null];

        return $this->render('@Video/client/search.html.twig', [
            'q' => $q,
            'filters' => $filters,
            'page' => $page,
            'videos' => $result['videos'],
            'found' => $result['found'],
            'engine' => $result['engine'],
            'per_page' => VideoSearch::PER_PAGE,
            'categories' => $this->categories,
            'channel' => $filters['channel'] ? $channels->findOneBy(['slug' => $filters['channel']]) : null,
        ]);
    }
}
