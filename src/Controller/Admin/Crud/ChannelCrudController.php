<?php

namespace Base\Video\Controller\Admin\Crud;

use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Video\Controller\Admin\OpenToEditorsTrait;
use Base\Video\Entity\Channel;

/** The channels: their names and addresses, their owners, how many follow them. */
class ChannelCrudController extends AbstractCrudController
{
    use OpenToEditorsTrait;

    public static function getEntityFqcn(): string
    {
        return Channel::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-tv';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', '@video.admin.channel.name')->setColumns(6);
        yield TextField::new('slug', '@video.admin.channel.slug')->setColumns(6);
        yield IntegerField::new('subscribers', '@video.admin.channel.subscribers')->setColumns(3)->setFormTypeOption('disabled', true);
        yield DateTimeField::new('createdAt', '@video.admin.channel.created_at')->onlyOnIndex();
        yield TextField::new('avatar', '@video.admin.channel.avatar')->setColumns(6)->hideOnIndex()->setRequired(false);
        yield TextField::new('banner', '@video.admin.channel.banner')->setColumns(6)->hideOnIndex()->setRequired(false);
        yield TextareaField::new('description', '@video.admin.channel.description')->hideOnIndex()->setRequired(false);
        yield AssociationField::new('owners', '@video.admin.channel.owners')->allowMultipleChoices()->setRequired(false);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->openTo(parent::configureActions($actions), 'ROLE_ADMIN');
    }
}
