<?php

namespace Base\Video\Admin\Widget;

use Base\Admin\Config\Menu\MenuItem;
use Base\Admin\Widget\DashboardWidgetTypeInterface;
use Base\Entity\User\Complaint;
use Base\Video\Entity\Video;
use Base\Video\Repository\VideoCommentRepository;
use Base\Video\Repository\VideoRepository;
use Base\Video\Repository\WatchEventRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The platform at a glance: comments waiting, reports open, films being
 * transcoded or failed, views of the last thirty days. Placed with
 * `yield MenuItem::block('video_overview', ...)` in the dashboard.
 */
final class VideoOverviewWidgetType implements DashboardWidgetTypeInterface
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly VideoCommentRepository $comments,
        private readonly WatchEventRepository $events,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getName(): string
    {
        return 'video_overview';
    }

    public function getTemplate(): string
    {
        return '@Video/admin/widget/overview.html.twig';
    }

    public function getTemplateVars(MenuItem $widget): array
    {
        $processing = $this->videos->countByProcessing();

        return [
            'pending' => $this->comments->countPending(),
            'reports' => (int) $this->entityManager->createQueryBuilder()->select('COUNT(c.id)')->from(Complaint::class, 'c')
                ->andWhere('c.status = :open')->setParameter('open', Complaint::OPEN)
                ->andWhere('c.source = :source')->setParameter('source', 'video')
                ->getQuery()->getSingleScalarResult(),
            'running' => ($processing[Video::PROCESSING_UPLOADED] ?? 0) + ($processing[Video::PROCESSING_RUNNING] ?? 0),
            'failed' => $processing[Video::PROCESSING_FAILED] ?? 0,
            'views' => $this->events->countViewsSince(30),
            'recent' => $this->videos->findProcessing(5),
        ];
    }
}
