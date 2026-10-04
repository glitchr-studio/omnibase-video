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
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Video\Controller\Admin\OpenToEditorsTrait;
use Base\Video\Entity\VideoComment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Moderating the comments under the films: what waits, what is online,
 * what Akismet held - approve, spam, trash. The moderators' screen
 * (ROLE_EDITOR); comments are written under the films, never here.
 */
class VideoCommentCrudController extends AbstractCrudController
{
    use OpenToEditorsTrait;

    public static function getEntityFqcn(): string
    {
        return VideoComment::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-comments';
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('state')->add('thread');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield SelectField::new('state', '@video.admin.comment.state')->setColumns(3);
        yield TextField::new('name', '@video.admin.comment.name')->setColumns(3);
        yield AssociationField::new('thread', '@video.admin.comment.video')->setColumns(6)->setRequired(false);
        yield IntegerField::new('moment', '@video.admin.comment.moment')->setColumns(2)->hideOnIndex()->setRequired(false);
        yield BooleanField::new('pinned', '@video.admin.comment.pinned')->setColumns(2)->hideOnIndex();
        yield TextareaField::new('content', '@video.admin.comment.content');
        yield DateTimeField::new('createdAt', '@video.admin.comment.created_at')->onlyOnIndex();
        yield TextField::new('ip', '@video.admin.comment.ip')->onlyOnDetail();
        yield TextField::new('spamScore', '@video.admin.comment.spam_score')->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $this->openTo(parent::configureActions($actions), 'ROLE_EDITOR', 'approve', 'spam', 'trash')->disable(Action::NEW);
        foreach ([Actions::PAGE_INDEX, Actions::PAGE_DETAIL] as $page) {
            $actions
                ->add($page, Action::new('approve', '@video.admin.comment.action.approve', 'fa-solid fa-check')->linkToCrudAction('approve'))
                ->add($page, Action::new('spam', '@video.admin.comment.action.spam', 'fa-solid fa-ban')->linkToCrudAction('spam'))
                ->add($page, Action::new('trash', '@video.admin.comment.action.trash', 'fa-solid fa-trash-can')->linkToCrudAction('trash'));
        }

        return $actions;
    }

    #[AdminAction('/{entityId}/approve')]
    public function approve(string $entityId): Response
    {
        return $this->moderate($entityId, fn (VideoComment $c) => $c->approve(), '@video.admin.comment.flash.approved');
    }

    #[AdminAction('/{entityId}/spam')]
    public function spam(string $entityId): Response
    {
        return $this->moderate($entityId, fn (VideoComment $c) => $c->markAsSpam()->setPinned(false), '@video.admin.comment.flash.spam');
    }

    #[AdminAction('/{entityId}/trash')]
    public function trash(string $entityId): Response
    {
        return $this->moderate($entityId, fn (VideoComment $c) => $c->trash()->setPinned(false), '@video.admin.comment.flash.trashed');
    }

    private function moderate(string $entityId, callable $change, string $flash): Response
    {
        /** @var VideoComment $comment */
        $comment = $this->findEntity($entityId);
        $change($comment);
        $this->entityManager->flush();
        $this->addFlash('success', $flash);

        return $this->redirectToIndex();
    }
}
