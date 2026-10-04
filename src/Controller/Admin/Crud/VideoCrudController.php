<?php

namespace Base\Video\Controller\Admin\Crud;

use Base\Admin\Attribute\AdminAction;
use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Admin\Filter\Filters;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\SelectField;
use Base\Field\SlugField;
use Base\Field\StateField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Video\Controller\Admin\OpenToEditorsTrait;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Video;
use Base\Video\Service\Moderation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * The films, all of them: their state and processing, who sent them, their
 * figures; "retirer" takes one down with a reason its authors are told,
 * "rétablir" puts it back (private). Written by the administrators; the
 * moderators take down and restore.
 */
class VideoCrudController extends AbstractCrudController
{
    use OpenToEditorsTrait;

    private Moderation $moderation;

    #[Required]
    public function setVideoServices(Moderation $moderation): void
    {
        $this->moderation = $moderation;
    }

    public static function getEntityFqcn(): string
    {
        return Video::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-film';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('state')->add('processing')->add('channel');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title', '@video.admin.video.title')->setColumns(8);
        yield StateField::new('state')->setColumns(4);
        yield SlugField::new('slug')->setColumns(4)->hideOnIndex();
        yield SelectField::new('channel', '@video.admin.video.channel')->setClass(Channel::class)->setRequired(false)->setColumns(4);
        yield DateTimeField::new('publishedAt', '@video.admin.video.published_at')->setColumns(4);
        yield TextField::new('processing', '@video.admin.video.processing')->setColumns(3)->setFormTypeOption('disabled', true);
        yield IntegerField::new('duration', '@video.admin.video.duration')->setColumns(3)->hideOnIndex();
        yield IntegerField::new('views', '@video.admin.video.views')->setColumns(3)->setFormTypeOption('disabled', true);
        yield IntegerField::new('legacyViews', '@video.admin.video.legacy_views')->setColumns(3)->hideOnIndex()->setRequired(false);
        yield TextField::new('category', '@video.admin.video.category')->setColumns(4)->hideOnIndex()->setRequired(false);
        yield TextField::new('language', '@video.admin.video.language')->setColumns(2)->hideOnIndex()->setRequired(false);
        yield TextField::new('license', '@video.admin.video.license')->setColumns(3)->hideOnIndex();
        yield BooleanField::new('commentsEnabled', '@video.admin.video.comments')->setColumns(3)->hideOnIndex();
        yield TextField::new('poster', '@video.admin.video.poster')->setColumns(12)->hideOnIndex()->setRequired(false);
        yield TextField::new('externalUrl', '@video.admin.video.external')->setColumns(12)->hideOnIndex()->setRequired(false);
        yield TextareaField::new('content', '@video.admin.video.description')->hideOnIndex();
        yield TextareaField::new('takedownReason', '@video.admin.video.takedown_reason')->onlyOnDetail();
        yield TextareaField::new('failure', '@video.admin.video.failure')->onlyOnDetail();
        yield AssociationField::new('owners', '@video.admin.video.owners')->allowMultipleChoices()->setRequired(false)->hideOnIndex();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->openTo(parent::configureActions($actions), 'ROLE_ADMIN')
            ->setPermission('takedown', 'ROLE_EDITOR')->setPermission('restore', 'ROLE_EDITOR');
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL] as $page) {
            $actions
                ->add($page, Action::new('takedown', '@video.admin.video.action.takedown', 'fa-solid fa-ban')->linkToCrudAction('takedown')
                    ->displayIf(fn (Video $video) => !$video->isTakenDown()))
                ->add($page, Action::new('restore', '@video.admin.video.action.restore', 'fa-solid fa-rotate-left')->linkToCrudAction('restore')
                    ->displayIf(fn (Video $video) => $video->isTakenDown()));
        }

        return $actions;
    }

    /** A reason first (the DSA's statement of reasons, sent to the authors), then down. */
    #[AdminAction('/{entityId}/takedown', methods: ['GET', 'POST'], csrf: false)]
    public function takedown(Request $request, string $entityId): Response
    {
        /** @var Video $video */
        $video = $this->findEntity($entityId);
        $reason = trim((string) $request->request->get('reason', ''));
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('video-takedown', (string) $request->request->get('_token')) && '' !== $reason) {
            $this->moderation->takeDown($video, $reason);
            $this->addFlash('success', '@video.admin.video.flash.taken_down');

            return $this->redirectToIndex();
        }

        return $this->renderCrud('@Video/admin/video/takedown.html.twig', ['video' => $video, 'reasons' => Moderation::REASONS]);
    }

    #[AdminAction('/{entityId}/restore')]
    public function restore(string $entityId): Response
    {
        /** @var Video $video */
        $video = $this->findEntity($entityId);
        $this->moderation->restore($video);
        $this->addFlash('success', '@video.admin.video.flash.restored');

        return $this->redirectToIndex();
    }
}
