<?php

namespace Base\Video\Form;

use Base\Video\Model\LinkDetails;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LinkDetailsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('url', UrlType::class, ['label' => '@video.studio.link.url', 'default_protocol' => 'https', 'attr' => ['placeholder' => 'https://']])
            ->add('title', TextType::class, ['label' => '@video.studio.link.name', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LinkDetails::class]);
    }
}
