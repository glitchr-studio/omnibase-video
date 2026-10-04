<?php

namespace Base\Video\Form;

use Base\Video\Entity\Channel;
use Base\Video\Model\ChannelDetails;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChannelDetailsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => '@video.studio.channel.name'])
            ->add('slug', TextType::class, ['label' => '@video.studio.channel.slug', 'help' => '@video.studio.channel.slug_help'])
            ->add('description', TextareaType::class, ['label' => '@video.studio.channel.description', 'required' => false, 'attr' => ['rows' => 5]])
            ->add('shelves', ChoiceType::class, [
                'label' => '@video.studio.channel.shelves',
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine(array_map(fn ($s) => '@video.channel.shelf.'.$s, Channel::SHELVES), Channel::SHELVES),
            ])
            ->add('avatarFile', FileType::class, ['label' => '@video.studio.channel.avatar', 'required' => false, 'attr' => ['accept' => 'image/jpeg,image/png,image/webp']])
            ->add('bannerFile', FileType::class, ['label' => '@video.studio.channel.banner', 'required' => false, 'attr' => ['accept' => 'image/jpeg,image/png,image/webp']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ChannelDetails::class]);
    }
}
